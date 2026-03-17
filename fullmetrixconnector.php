<?php
/**
 * Fullmetrix - E-commerce analytics platform connector
 *
 * @author    Fullmetrix <contact@fullmetrix.com>
 * @copyright 2024-2026 Fullmetrix
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/FullmetrixSecurity.php';
require_once dirname(__FILE__) . '/classes/FullmetrixFastExporter.php';
require_once dirname(__FILE__) . '/classes/FullmetrixStreamExporter.php';
require_once dirname(__FILE__) . '/classes/FullmetrixWebhookSender.php';
require_once dirname(__FILE__) . '/classes/FullmetrixLogger.php';

class FullmetrixConnector extends Module
{
    const FULLMETRIX_API_BASE = 'https://fullmetrix.com/api/plugin';
    const FULLMETRIX_VERSION = '1.0.0';

    public static function getApiBase()
    {
        $custom = Configuration::get('FULLMETRIX_API_BASE');
        return $custom ?: self::FULLMETRIX_API_BASE;
    }

    public function __construct()
    {
        $this->name = 'fullmetrixconnector';
        $this->tab = 'analytics_stats';
        $this->version = self::FULLMETRIX_VERSION;
        $this->author = 'Fullmetrix';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => '8.99.99'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Fullmetrix');
        $this->description = $this->l('Connect your PrestaShop store to Fullmetrix to sync your orders.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall the Fullmetrix module?');

        FullmetrixWebhookSender::init();
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('displayBackOfficeHeader')
            && $this->registerHook('displayHeader')
            && $this->registerHook('displayFooter')
            // Webhook hooks
            && $this->registerHook('actionValidateOrder')
            && $this->registerHook('actionOrderStatusUpdate')
            && $this->registerHook('actionCustomerAccountUpdate')
            && $this->registerHook('actionObjectCustomerUpdateAfter')
            && $this->registerHook('actionProductUpdate')
            && $this->registerHook('actionProductAdd')
            && $this->registerHook('actionUpdateQuantity')
            && $this->registerHook('actionObjectCartRuleUpdateAfter')
            && $this->registerHook('actionOrderSlipAdd')
            && $this->registerHook('actionCategoryUpdate')
            && Configuration::updateValue('FULLMETRIX_CONNECTION_CODE', '')
            && Configuration::updateValue('FULLMETRIX_CONNECTION_SECRET', '')
            && Configuration::updateValue('FULLMETRIX_REGISTERED', false)
            && Configuration::updateValue('FULLMETRIX_LAST_SYNC', '')
            && Configuration::updateValue('FULLMETRIX_EXPORT_COUNT', 0)
            && Configuration::updateValue('FULLMETRIX_SYNC_IN_PROGRESS', '')
            && Configuration::updateValue('FULLMETRIX_LOGS', '[]');
    }

    public function uninstall()
    {
        return parent::uninstall()
            && Configuration::deleteByName('FULLMETRIX_CONNECTION_CODE')
            && Configuration::deleteByName('FULLMETRIX_CONNECTION_SECRET')
            && Configuration::deleteByName('FULLMETRIX_REGISTERED')
            && Configuration::deleteByName('FULLMETRIX_WEBHOOKS_ENABLED')
            && Configuration::deleteByName('FULLMETRIX_LAST_SYNC')
            && Configuration::deleteByName('FULLMETRIX_EXPORT_COUNT')
            && Configuration::deleteByName('FULLMETRIX_SYNC_IN_PROGRESS')
            && Configuration::deleteByName('FULLMETRIX_LOGS');
    }

    public function hookDisplayBackOfficeHeader()
    {
    }

    public function hookDisplayHeader()
    {
        // Header hook - reserved for future use
        return '';
    }

    /**
     * Get cached plugin config from Fullmetrix API (cached 5 min)
     */
    private function getCachedConfig()
    {
        $cacheKey = 'fullmetrix_plugin_config';
        $cached = Configuration::get($cacheKey);
        if ($cached) {
            $data = json_decode($cached, true);
            if (is_array($data) && isset($data['_ts']) && (time() - $data['_ts']) < 300) {
                return $data;
            }
        }

        $secret = Configuration::get('FULLMETRIX_CONNECTION_SECRET');
        $code = Configuration::get('FULLMETRIX_CONNECTION_CODE');
        if (empty($secret) || empty($code)) {
            return null;
        }

        $apiBase = Configuration::get('FULLMETRIX_API_BASE');
        if (empty($apiBase)) {
            $apiBase = 'https://fullmetrix.com/api/plugin';
        }

        $headers = FullmetrixSecurity::createSignedHeaders($secret, $code, '');

        $ch = curl_init($apiBase . '/config');
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => array(
                'X-Fullmetrix-Connection-Code: ' . $headers['X-Fullmetrix-Connection-Code'],
                'X-Fullmetrix-Signature: ' . $headers['X-Fullmetrix-Signature'],
                'X-Fullmetrix-Timestamp: ' . $headers['X-Fullmetrix-Timestamp'],
                'X-Fullmetrix-Plugin-Version: ' . self::FULLMETRIX_VERSION,
            ),
        ));
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || empty($body)) {
            return null;
        }

        $config = json_decode($body, true);
        if (!is_array($config)) {
            return null;
        }

        $config['_ts'] = time();
        Configuration::updateValue($cacheKey, json_encode($config), false, 0, 0);

        return $config;
    }

    public function hookDisplayFooter()
    {
        $secret = Configuration::get('FULLMETRIX_CONNECTION_SECRET');
        $code = Configuration::get('FULLMETRIX_CONNECTION_CODE');
        $registered = Configuration::get('FULLMETRIX_REGISTERED');

        if (empty($secret) || empty($code) || !$registered) {
            return '';
        }

        $config = $this->getCachedConfig();
        if (!$config) {
            return '';
        }

        $apiBase = Configuration::get('FULLMETRIX_API_BASE');
        if (empty($apiBase)) {
            $apiBase = 'https://fullmetrix.com/api/plugin';
        }
        $origin = rtrim(str_replace('/api/plugin', '', $apiBase), '/');
        $output = '';

        // Inject active form scripts
        if (!empty($config['activeForms']) && is_array($config['activeForms'])) {
            foreach ($config['activeForms'] as $form) {
                $id = Tools::safeOutput($form['id']);
                $token = Tools::safeOutput($form['publicToken']);
                $output .= '<script src="' . Tools::safeOutput($origin) . '/forms/fullmetrix-forms.js" data-form-id="' . $id . '" data-token="' . $token . '" defer></script>' . "\n";
            }
        }

        return $output;
    }

    // ─── Webhook hook handlers ────────────────────────────────────────

    public function hookActionValidateOrder($params)
    {
        if (isset($params['order'])) {
            FullmetrixWebhookSender::enqueue('order', (int) $params['order']->id);
        }
    }

    public function hookActionOrderStatusUpdate($params)
    {
        if (isset($params['id_order'])) {
            FullmetrixWebhookSender::enqueue('order', (int) $params['id_order']);
        }
    }

    public function hookActionCustomerAccountUpdate($params)
    {
        if (isset($params['customer'])) {
            FullmetrixWebhookSender::enqueue('customer', (int) $params['customer']->id);
        }
    }

    public function hookActionObjectCustomerUpdateAfter($params)
    {
        if (isset($params['object'])) {
            FullmetrixWebhookSender::enqueue('customer', (int) $params['object']->id);
        }
    }

    public function hookActionProductUpdate($params)
    {
        if (isset($params['id_product'])) {
            FullmetrixWebhookSender::enqueue('product', (int) $params['id_product']);
        } elseif (isset($params['product'])) {
            FullmetrixWebhookSender::enqueue('product', (int) $params['product']->id);
        }
    }

    public function hookActionProductAdd($params)
    {
        if (isset($params['id_product'])) {
            FullmetrixWebhookSender::enqueue('product', (int) $params['id_product']);
        } elseif (isset($params['product'])) {
            FullmetrixWebhookSender::enqueue('product', (int) $params['product']->id);
        }
    }

    public function hookActionUpdateQuantity($params)
    {
        if (isset($params['id_product'])) {
            FullmetrixWebhookSender::enqueue('product', (int) $params['id_product']);
        }
    }

    public function hookActionObjectCartRuleUpdateAfter($params)
    {
        if (isset($params['object'])) {
            FullmetrixWebhookSender::enqueue('coupon', (int) $params['object']->id);
        }
    }

    public function hookActionOrderSlipAdd($params)
    {
        if (isset($params['order'])) {
            // Get the latest slip for this order
            $orderId = (int) $params['order']->id;
            $sql = 'SELECT MAX(id_order_slip) FROM ' . _DB_PREFIX_ . 'order_slip WHERE id_order = ' . $orderId;
            $slipId = (int) Db::getInstance()->getValue($sql);
            if ($slipId > 0) {
                FullmetrixWebhookSender::enqueue('refund', $slipId);
            }
        }
    }

    public function hookActionCategoryUpdate($params)
    {
        if (isset($params['category'])) {
            FullmetrixWebhookSender::enqueue('category', (int) $params['category']->id);
        }
    }

    // ─── Admin content ───────────────────────────────────────────────

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitFullmetrixConnect')) {
            $connectionCode = Tools::getValue('FULLMETRIX_CONNECTION_CODE');

            if (empty($connectionCode)) {
                $output .= $this->displayError($this->l('Please enter a connection code.'));
            } elseif (!$this->validateCodeFormat($connectionCode)) {
                $output .= $this->displayError($this->l('Invalid code format. The code must be in FMTX-XXXX-XXXX-XXXX format.'));
            } else {
                Configuration::updateValue('FULLMETRIX_CONNECTION_CODE', $connectionCode);

                $result = $this->registerWithFullmetrix();

                if ($result === true) {
                    $output .= $this->displayConfirmation($this->l('Connection successful! Your store is now connected to Fullmetrix.'));
                    FullmetrixLogger::log('registered', 'Store connected to Fullmetrix', ['code' => $connectionCode]);
                } else {
                    $output .= $this->displayError($result);
                }
            }
        }

        if (Tools::isSubmit('submitFullmetrixDisconnect')) {
            FullmetrixLogger::log('disconnected', 'Store disconnected from Fullmetrix');
            Configuration::updateValue('FULLMETRIX_CONNECTION_CODE', '');
            Configuration::updateValue('FULLMETRIX_CONNECTION_SECRET', '');
            Configuration::updateValue('FULLMETRIX_REGISTERED', false);
            Configuration::updateValue('FULLMETRIX_WEBHOOKS_ENABLED', false);
            Configuration::updateValue('FULLMETRIX_LAST_SYNC', '');
            Configuration::updateValue('FULLMETRIX_EXPORT_COUNT', 0);
            Configuration::updateValue('FULLMETRIX_SYNC_IN_PROGRESS', '');
            $output .= $this->displayConfirmation($this->l('Successfully disconnected.'));
        }

        if (Tools::isSubmit('submitFullmetrixClearLogs')) {
            FullmetrixLogger::clear();
            $output .= $this->displayConfirmation($this->l('Logs cleared.'));
        }

        $isRegistered = (bool) Configuration::get('FULLMETRIX_REGISTERED');

        if (!$isRegistered) {
            $output .= $this->renderForm();
            return $output;
        }

        // Tabs for connected state
        $output .= '<ul class="nav nav-tabs" role="tablist">
            <li class="active"><a href="#fullmetrix-tab-connection" data-toggle="tab">' . $this->l('Connection') . '</a></li>
            <li><a href="#fullmetrix-tab-logs" data-toggle="tab">' . $this->l('Logs') . '</a></li>
        </ul>';

        $output .= '<div class="tab-content">';
        $output .= '<div class="tab-pane active" id="fullmetrix-tab-connection">';
        $output .= $this->renderForm();
        $output .= $this->renderSyncActivity();
        $output .= '</div>';
        $output .= '<div class="tab-pane" id="fullmetrix-tab-logs">';
        $output .= $this->renderLogsTab();
        $output .= '</div>';
        $output .= '</div>';

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
                    'title' => $this->l('Fullmetrix - Connected'),
                    'icon' => 'icon-check',
                ],
                'description' => $this->l('Your store is connected and ready to sync orders with Fullmetrix.'),
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->l('Connection code'),
                        'name' => 'FULLMETRIX_CONNECTION_CODE_DISPLAY',
                        'readonly' => true,
                        'disabled' => true,
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Disconnect'),
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
                'description' => $this->l('Enter the connection code provided by Fullmetrix to connect your store.'),
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->l('Connection code'),
                        'name' => 'FULLMETRIX_CONNECTION_CODE',
                        'placeholder' => 'FMTX-XXXX-XXXX-XXXX',
                        'required' => true,
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Connect'),
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
            . $this->l('Sync activity') . '</h3>';

        if ($inProgress) {
            $elapsed = time() - (int) ($inProgress['started_at'] ?? time());
            $typeLabel = '';
            if (isset($inProgress['type']) && $inProgress['type'] === 'bulk') {
                $typeLabel = ' (full export)';
            }
            $html .= '<div class="alert alert-info">'
                . '<strong>' . $this->l('Sync in progress') . $typeLabel . '...</strong> '
                . '(' . $this->formatDuration($elapsed) . ')'
                . '</div>';
            $html .= '<p class="text-muted">'
                . $this->l('Fullmetrix is fetching your store data. Refresh this page to track progress.')
                . '</p>';
            $html .= '<script>setTimeout(function() { location.reload(); }, 10000);</script>';
        } elseif ($lastSync && isset($lastSync['completed_at'])) {
            $html .= '<div class="alert alert-success">'
                . '<strong>' . $this->l('Last sync') . ':</strong> '
                . $this->formatTimeAgo((int) $lastSync['completed_at']);

            if (!empty($lastSync['type'])) {
                $modeLabel = $lastSync['type'] === 'bulk'
                    ? $this->l('Full export')
                    : $this->l('Paginated export');
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
                . $this->l('Waiting for first sync. Start a sync from your Fullmetrix dashboard.')
                . '</div>';
        }

        if ($exportCount > 0) {
            $html .= '<p class="text-muted" style="margin:0;font-size:12px;">'
                . sprintf($this->l('%s export requests processed in total'), number_format($exportCount, 0, ',', ' '))
                . '</p>';
        }

        $html .= '</div>';

        return $html;
    }

    protected function renderLogsTab()
    {
        $logs = FullmetrixLogger::getLogs();
        $inProgressRaw = Configuration::get('FULLMETRIX_SYNC_IN_PROGRESS');
        $inProgress = !empty($inProgressRaw) ? json_decode($inProgressRaw, true) : null;

        $adminUrl = $this->context->link->getAdminLink('AdminModules', true)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;

        $html = '<div class="panel">';
        $html .= '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:15px;">';
        $html .= '<h3 style="margin:0;"><i class="icon-list-alt"></i> ' . $this->l('Activity log') . '</h3>';

        if (!empty($logs)) {
            $html .= '<form method="post" action="' . htmlspecialchars($adminUrl) . '" style="margin:0;">'
                . '<button type="submit" name="submitFullmetrixClearLogs" class="btn btn-default btn-sm">'
                . '<i class="icon-trash"></i> ' . $this->l('Clear logs')
                . '</button></form>';
        }

        $html .= '</div>';

        if (empty($logs)) {
            $html .= '<p class="text-muted">' . $this->l('No activity recorded.') . '</p>';
        } else {
            $badgeColors = [
                'registered'    => '#27ae60',
                'disconnected'  => '#95a5a6',
                'sync_start'    => '#2980b9',
                'sync_complete' => '#27ae60',
                'sync_error'    => '#e74c3c',
                'webhook'       => '#2980b9',
            ];

            $typeLabels = [
                'registered'    => 'Connected',
                'disconnected'  => 'Disconnected',
                'sync_start'    => 'Sync',
                'sync_complete' => 'Sync OK',
                'sync_error'    => 'Error',
                'webhook'       => 'Webhook',
            ];

            $html .= '<table class="table table-striped" style="font-size:13px;">';
            $html .= '<thead><tr>'
                . '<th>' . $this->l('Date') . '</th>'
                . '<th>' . $this->l('Type') . '</th>'
                . '<th>' . $this->l('Message') . '</th>'
                . '<th>' . $this->l('Details') . '</th>'
                . '</tr></thead><tbody>';

            foreach ($logs as $log) {
                $color = isset($badgeColors[$log['type']]) ? $badgeColors[$log['type']] : '#95a5a6';
                $label = isset($typeLabels[$log['type']]) ? $typeLabels[$log['type']] : $log['type'];

                $details = '—';
                if (!empty($log['details']) && is_array($log['details'])) {
                    $parts = [];
                    foreach ($log['details'] as $k => $v) {
                        if (is_array($v)) {
                            $sub = [];
                            foreach ($v as $sk => $sv) {
                                $sub[] = is_int($sk) ? $sv : $sk . '=' . $sv;
                            }
                            $v = implode(', ', $sub);
                        }
                        $parts[] = $k . ': ' . $v;
                    }
                    $details = htmlspecialchars(implode(' | ', $parts));
                }

                $html .= '<tr>'
                    . '<td style="white-space:nowrap;color:#888;font-size:12px;">' . date('d/m/Y H:i:s', (int) $log['time']) . '</td>'
                    . '<td><span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;color:#fff;background:' . $color . ';">' . htmlspecialchars($label) . '</span></td>'
                    . '<td>' . htmlspecialchars($log['message']) . '</td>'
                    . '<td style="color:#888;font-size:12px;max-width:250px;word-break:break-word;">' . $details . '</td>'
                    . '</tr>';
            }

            $html .= '</tbody></table>';
        }

        $html .= '</div>';

        if ($inProgress) {
            $html .= '<script>setTimeout(function() { location.reload(); }, 10000);</script>';
        }

        return $html;
    }

    protected function formatTimeAgo($timestamp)
    {
        $diff = time() - $timestamp;

        if ($diff < 60) {
            return $this->l('just now');
        }
        if ($diff < 3600) {
            $mins = (int) floor($diff / 60);
            return sprintf($this->l('%d min ago'), $mins);
        }
        if ($diff < 86400) {
            $hours = (int) floor($diff / 3600);
            return sprintf($this->l('%d h ago'), $hours);
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
            return $this->l('Connection code missing');
        }

        $data = [
            'connectionCode' => $code,
            'siteUrl' => $this->getShopUrl(),
            'pluginVersion' => self::FULLMETRIX_VERSION,
            'platform' => 'prestashop',
            'storeSettings' => $this->getStoreSettings(),
        ];

        $response = $this->makeHttpRequest(
            self::getApiBase() . '/register',
            'POST',
            json_encode($data),
            ['Content-Type: application/json']
        );

        if ($response === false) {
            return $this->l('Connection error to Fullmetrix server');
        }

        $result = json_decode($response['body'], true);
        $statusCode = $response['http_code'];

        if ($statusCode === 404) {
            return $this->l('Connection code not found. Check your code in Fullmetrix.');
        }

        if ($statusCode === 409) {
            return $this->l('This code is already associated with another site.');
        }

        if ($statusCode !== 200 || empty($result['success'])) {
            $errorMessage = isset($result['error']) ? $result['error'] : $this->l('Unknown error');
            return sprintf($this->l('Registration failed: %s'), $errorMessage);
        }

        if (!empty($result['connectionSecret'])) {
            Configuration::updateValue('FULLMETRIX_CONNECTION_SECRET', $result['connectionSecret']);
        }

        Configuration::updateValue('FULLMETRIX_REGISTERED', true);
        Configuration::updateValue('FULLMETRIX_WEBHOOKS_ENABLED', true);

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
