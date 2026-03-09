<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/FullmetrixSecurity.php';
require_once dirname(__FILE__) . '/classes/FullmetrixFastExporter.php';
require_once dirname(__FILE__) . '/classes/FullmetrixStreamExporter.php';
require_once dirname(__FILE__) . '/classes/FullmetrixUpdater.php';

class FullmetrixConnector extends Module
{
    const FULLMETRIX_API_BASE = 'https://fullmetrix.hehocom.fr/api/plugin';
    const FULLMETRIX_VERSION = '3.7.0';

    public function __construct()
    {
        $this->name = 'fullmetrixconnector';
        $this->tab = 'analytics_stats';
        $this->version = self::FULLMETRIX_VERSION;
        $this->author = 'Fullmetrix';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Fullmetrix');
        $this->description = $this->l('Connectez votre boutique PrestaShop à Fullmetrix pour synchroniser vos commandes.');
        $this->confirmUninstall = $this->l('Êtes-vous sûr de vouloir désinstaller le module Fullmetrix ?');
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('displayBackOfficeHeader')
            && $this->registerHook('displayHeader')
            && $this->createCartContactsTable()
            && Configuration::updateValue('FULLMETRIX_CONNECTION_CODE', '')
            && Configuration::updateValue('FULLMETRIX_CONNECTION_SECRET', '')
            && Configuration::updateValue('FULLMETRIX_REGISTERED', false)
            && Configuration::updateValue('FULLMETRIX_LAST_SYNC', '')
            && Configuration::updateValue('FULLMETRIX_EXPORT_COUNT', 0)
            && Configuration::updateValue('FULLMETRIX_SYNC_IN_PROGRESS', '');
    }

    private function createCartContactsTable()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'fullmetrix_cart_contacts` (
            `id_cart` INT(10) UNSIGNED NOT NULL,
            `email` VARCHAR(255) DEFAULT NULL,
            `phone` VARCHAR(50) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_cart`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';

        return Db::getInstance()->execute($sql);
    }

    public function uninstall()
    {
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'fullmetrix_cart_contacts`');

        return parent::uninstall()
            && Configuration::deleteByName('FULLMETRIX_CONNECTION_CODE')
            && Configuration::deleteByName('FULLMETRIX_CONNECTION_SECRET')
            && Configuration::deleteByName('FULLMETRIX_REGISTERED')
            && Configuration::deleteByName('FULLMETRIX_LAST_SYNC')
            && Configuration::deleteByName('FULLMETRIX_EXPORT_COUNT')
            && Configuration::deleteByName('FULLMETRIX_SYNC_IN_PROGRESS');
    }

    public function hookDisplayBackOfficeHeader()
    {
        // Trigger update check in background (cached 12h)
        FullmetrixUpdater::checkForUpdate();
    }

    public function hookDisplayHeader()
    {
        // Only on checkout pages
        $controller = Tools::getValue('controller');
        if (!in_array($controller, ['order', 'orderopc', 'checkout', 'supercheckout'])) {
            return '';
        }

        $cartId = (int) $this->context->cart->id;
        if ($cartId <= 0) {
            return '';
        }

        $ajaxUrl = $this->context->link->getModuleLink(
            $this->name,
            'capturecontact',
            [],
            true
        );

        return '<script>
(function() {
    "use strict";
    var cartId = ' . $cartId . ';
    var ajaxUrl = "' . addslashes($ajaxUrl) . '";
    var emailCaptured = false;
    var phoneCaptured = false;

    function send(data) {
        var params = "cart_id=" + cartId;
        if (data.email) params += "&email=" + encodeURIComponent(data.email);
        if (data.phone) params += "&phone=" + encodeURIComponent(data.phone);

        var xhr = new XMLHttpRequest();
        xhr.open("POST", ajaxUrl);
        xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
        xhr.send(params);
    }

    function captureEmail(val) {
        if (!val || emailCaptured) return;
        val = val.trim();
        if (val.indexOf("@") < 1 || val.lastIndexOf(".") < val.indexOf("@") + 2) return;
        emailCaptured = true;
        send({ email: val });
    }

    function capturePhone(val) {
        if (!val || phoneCaptured) return;
        val = val.trim().replace(/[\s\-().]/g, "");
        if (val.length < 7) return;
        phoneCaptured = true;
        send({ phone: val });
    }

    document.addEventListener("focusout", function(e) {
        var t = e.target;
        if (!t || !t.name) return;
        var n = t.name.toLowerCase();
        if (n.indexOf("email") !== -1 || t.type === "email") captureEmail(t.value);
        if (n.indexOf("phone") !== -1 || t.type === "tel") capturePhone(t.value);
    }, true);
})();
</script>';
    }

    public function getContent()
    {
        $output = '';
        $output .= FullmetrixUpdater::getUpdateNotice();

        if (Tools::isSubmit('submitFullmetrixConnect')) {
            $connectionCode = Tools::getValue('FULLMETRIX_CONNECTION_CODE');

            if (empty($connectionCode)) {
                $output .= $this->displayError($this->l('Veuillez entrer un code de connexion.'));
            } elseif (!$this->validateCodeFormat($connectionCode)) {
                $output .= $this->displayError($this->l('Format de code invalide. Le code doit être au format FMTX-XXXX-XXXX-XXXX.'));
            } else {
                Configuration::updateValue('FULLMETRIX_CONNECTION_CODE', $connectionCode);

                $result = $this->registerWithFullmetrix();

                if ($result === true) {
                    $output .= $this->displayConfirmation($this->l('Connexion réussie ! Votre boutique est maintenant connectée à Fullmetrix.'));
                } else {
                    $output .= $this->displayError($result);
                }
            }
        }

        if (Tools::isSubmit('submitFullmetrixDisconnect')) {
            Configuration::updateValue('FULLMETRIX_CONNECTION_CODE', '');
            Configuration::updateValue('FULLMETRIX_CONNECTION_SECRET', '');
            Configuration::updateValue('FULLMETRIX_REGISTERED', false);
            Configuration::updateValue('FULLMETRIX_LAST_SYNC', '');
            Configuration::updateValue('FULLMETRIX_EXPORT_COUNT', 0);
            Configuration::updateValue('FULLMETRIX_SYNC_IN_PROGRESS', '');
            $output .= $this->displayConfirmation($this->l('Déconnexion réussie.'));
        }

        $output .= $this->renderForm();

        if ((bool) Configuration::get('FULLMETRIX_REGISTERED')) {
            $output .= $this->renderSyncActivity();
        }

        return $output;
    }

    protected function renderForm()
    {
        $isRegistered = (bool) Configuration::get('FULLMETRIX_REGISTERED');
        $connectionCode = Configuration::get('FULLMETRIX_CONNECTION_CODE');

        if ($isRegistered) {
            return $this->renderConnectedForm($connectionCode);
        }

        return $this->renderConnectForm($connectionCode);
    }

    protected function renderConnectedForm($connectionCode)
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitFullmetrixDisconnect';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = [
            'fields_value' => [
                'FULLMETRIX_CONNECTION_CODE_DISPLAY' => $connectionCode,
            ],
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        $form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Fullmetrix - Connecté'),
                    'icon' => 'icon-check',
                ],
                'description' => $this->l('Votre boutique est connectée et prête à synchroniser les commandes avec Fullmetrix.'),
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->l('Code de connexion'),
                        'name' => 'FULLMETRIX_CONNECTION_CODE_DISPLAY',
                        'readonly' => true,
                        'disabled' => true,
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Déconnecter'),
                    'class' => 'btn btn-default pull-right',
                ],
            ],
        ];

        return $helper->generateForm([$form]);
    }

    protected function renderConnectForm($connectionCode)
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitFullmetrixConnect';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = [
            'fields_value' => [
                'FULLMETRIX_CONNECTION_CODE' => $connectionCode,
            ],
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        $form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Fullmetrix - Configuration'),
                    'icon' => 'icon-cogs',
                ],
                'description' => $this->l('Entrez le code de connexion fourni par Fullmetrix pour connecter votre boutique.'),
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->l('Code de connexion'),
                        'name' => 'FULLMETRIX_CONNECTION_CODE',
                        'placeholder' => 'FMTX-XXXX-XXXX-XXXX',
                        'required' => true,
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Connecter'),
                    'class' => 'btn btn-primary pull-right',
                ],
            ],
        ];

        return $helper->generateForm([$form]);
    }

    protected function renderSyncActivity()
    {
        $inProgressRaw = Configuration::get('FULLMETRIX_SYNC_IN_PROGRESS');
        $inProgress = !empty($inProgressRaw) ? json_decode($inProgressRaw, true) : null;
        $lastSyncRaw = Configuration::get('FULLMETRIX_LAST_SYNC');
        $lastSync = !empty($lastSyncRaw) ? json_decode($lastSyncRaw, true) : null;
        $exportCount = (int) Configuration::get('FULLMETRIX_EXPORT_COUNT');

        // Check if in-progress is stale (> 10 min)
        if ($inProgress && isset($inProgress['started_at'])) {
            if ((time() - (int) $inProgress['started_at']) > 600) {
                $inProgress = null;
                Configuration::updateValue('FULLMETRIX_SYNC_IN_PROGRESS', '');
            }
        }

        $html = '<div class="panel"><h3><i class="icon-refresh"></i> '
            . $this->l('Activité de synchronisation') . '</h3>';

        if ($inProgress) {
            $elapsed = time() - (int) ($inProgress['started_at'] ?? time());
            $typeLabel = '';
            if (isset($inProgress['type']) && $inProgress['type'] === 'bulk') {
                $typeLabel = ' (export complet)';
            }
            $html .= '<div class="alert alert-info">'
                . '<strong>' . $this->l('Synchronisation en cours') . $typeLabel . '...</strong> '
                . '(' . $this->formatDuration($elapsed) . ')'
                . '</div>';
            $html .= '<p class="text-muted">'
                . $this->l('Fullmetrix récupère les données de votre boutique. Rafraîchissez cette page pour suivre la progression.')
                . '</p>';
            $html .= '<script>setTimeout(function() { location.reload(); }, 10000);</script>';
        } elseif ($lastSync && isset($lastSync['completed_at'])) {
            $html .= '<div class="alert alert-success">'
                . '<strong>' . $this->l('Dernière synchronisation') . ' :</strong> '
                . $this->formatTimeAgo((int) $lastSync['completed_at']);

            if (!empty($lastSync['type'])) {
                $modeLabel = $lastSync['type'] === 'bulk'
                    ? $this->l('Export complet')
                    : $this->l('Export par pages');
                $html .= ' — ' . $modeLabel;
            }

            $html .= '</div>';

            if (!empty($lastSync['entities']) && is_array($lastSync['entities'])) {
                $html .= '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:15px;">';
                foreach ($lastSync['entities'] as $label => $count) {
                    if ($count > 0) {
                        $html .= '<div style="text-align:center;background:#f8f8f8;border:1px solid #e0e0e0;border-radius:4px;padding:10px 16px;min-width:80px;">'
                            . '<div style="font-size:20px;font-weight:700;color:#333;">' . number_format($count, 0, ',', ' ') . '</div>'
                            . '<div style="font-size:11px;color:#666;text-transform:uppercase;letter-spacing:0.5px;margin-top:4px;">' . htmlspecialchars($label) . '</div>'
                            . '</div>';
                    }
                }
                $html .= '</div>';
            }
        } else {
            $html .= '<div class="alert alert-warning">'
                . $this->l('En attente de la première synchronisation. Lancez une synchronisation depuis votre tableau de bord Fullmetrix.')
                . '</div>';
        }

        if ($exportCount > 0) {
            $html .= '<p class="text-muted" style="margin:0;font-size:12px;">'
                . sprintf($this->l('%s requêtes d\'export traitées au total'), number_format($exportCount, 0, ',', ' '))
                . '</p>';
        }

        $html .= '</div>';

        return $html;
    }

    protected function formatTimeAgo($timestamp)
    {
        $diff = time() - $timestamp;

        if ($diff < 60) {
            return $this->l('à l\'instant');
        }
        if ($diff < 3600) {
            $mins = (int) floor($diff / 60);
            return sprintf($this->l('il y a %d min'), $mins);
        }
        if ($diff < 86400) {
            $hours = (int) floor($diff / 3600);
            return sprintf($this->l('il y a %d h'), $hours);
        }

        return date('d/m/Y H:i', $timestamp);
    }

    protected function formatDuration($seconds)
    {
        $seconds = (int) $seconds;

        if ($seconds < 60) {
            return sprintf($this->l('%d sec'), $seconds);
        }
        if ($seconds < 3600) {
            $mins = (int) floor($seconds / 60);
            $secs = $seconds % 60;
            return sprintf($this->l('%d min %d sec'), $mins, $secs);
        }

        $hours = (int) floor($seconds / 3600);
        $mins = (int) floor(($seconds % 3600) / 60);
        return sprintf($this->l('%d h %d min'), $hours, $mins);
    }

    protected function validateCodeFormat($code)
    {
        return preg_match('/^FMTX-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $code);
    }

    protected function registerWithFullmetrix()
    {
        $code = Configuration::get('FULLMETRIX_CONNECTION_CODE');

        if (empty($code)) {
            return $this->l('Code de connexion manquant');
        }

        $data = [
            'connectionCode' => $code,
            'siteUrl' => $this->getShopUrl(),
            'pluginVersion' => self::FULLMETRIX_VERSION,
            'platform' => 'prestashop',
            'storeSettings' => $this->getStoreSettings(),
        ];

        $response = $this->makeHttpRequest(
            self::FULLMETRIX_API_BASE . '/register',
            'POST',
            json_encode($data),
            ['Content-Type: application/json']
        );

        if ($response === false) {
            return $this->l('Erreur de connexion au serveur Fullmetrix');
        }

        $result = json_decode($response['body'], true);
        $statusCode = $response['http_code'];

        if ($statusCode === 404) {
            return $this->l('Code de connexion introuvable. Vérifiez votre code dans Fullmetrix.');
        }

        if ($statusCode === 409) {
            return $this->l('Ce code est déjà associé à un autre site.');
        }

        if ($statusCode !== 200 || empty($result['success'])) {
            $errorMessage = isset($result['error']) ? $result['error'] : $this->l('Erreur inconnue');
            return sprintf($this->l('Échec de l\'enregistrement : %s'), $errorMessage);
        }

        if (!empty($result['connectionSecret'])) {
            Configuration::updateValue('FULLMETRIX_CONNECTION_SECRET', $result['connectionSecret']);
        }

        Configuration::updateValue('FULLMETRIX_REGISTERED', true);

        return true;
    }

    protected function getStoreSettings()
    {
        $currencyId = (int) Configuration::get('PS_CURRENCY_DEFAULT');
        $currency = new \Currency($currencyId);
        $isoCode = $currency->iso_code ?: 'EUR';

        $timezone = Configuration::get('PS_TIMEZONE') ?: 'Europe/Paris';

        $langId = (int) Configuration::get('PS_LANG_DEFAULT');
        $lang = new \Language($langId);
        $locale = $lang->locale ?: $lang->language_code ?: 'fr-FR';

        $format = $currency->format ?: 0;
        $position = in_array($format, [3, 4], true) ? 'left' : 'right';

        $decimalSeparator = in_array($format, [2, 4], true) ? ',' : '.';
        $thousandSeparator = in_array($format, [2, 4], true) ? ' ' : ',';
        if ($format === 5) {
            $thousandSeparator = "'";
            $decimalSeparator = '.';
        }

        $numDecimals = (int) ($currency->precision ?? 2);

        return [
            'currency' => $isoCode,
            'timezone' => $timezone,
            'locale' => $locale,
            'currencyPosition' => $position,
            'thousandSeparator' => $thousandSeparator,
            'decimalSeparator' => $decimalSeparator,
            'numDecimals' => $numDecimals,
        ];
    }

    protected function getShopUrl()
    {
        try {
            $ssl = Configuration::get('PS_SSL_ENABLED') || Tools::usingSecureMode();
            $domain = $this->context->shop->domain;
            $physicalUri = $this->context->shop->physical_uri;
            $shopUrl = ($ssl ? 'https://' : 'http://') . $domain . $physicalUri;
            return rtrim($shopUrl, '/');
        } catch (\Throwable $e) {
            return Tools::getShopDomainSsl(true);
        }
    }

    protected function makeHttpRequest($url, $method = 'GET', $body = null, $headers = [])
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($response === false) {
            return false;
        }

        return [
            'body' => $response,
            'http_code' => $httpCode,
        ];
    }

    public static function isConfigured()
    {
        $code = Configuration::get('FULLMETRIX_CONNECTION_CODE');
        $registered = Configuration::get('FULLMETRIX_REGISTERED');
        return !empty($code) && $registered;
    }
}
