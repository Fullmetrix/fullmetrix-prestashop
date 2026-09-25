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

class FullmetrixConnector extends Module
{
    private static $pluginVersion = '2.0.0';
    private static $pluginChannel = 'community';
    private static $apiBase = 'https://fullmetrix.com/api/plugin';
    private static $guardFailed = false;

    public function __construct()
    {
        $this->name = 'fullmetrixconnector';
        $this->tab = 'analytics_stats';
        $this->version = '2.0.0';
        $this->author = 'Fullmetrix';
        $this->module_key = '9cc46e05bb451f6ed601277b8096d019';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.6.1.0', 'max' => '9.99.99'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Fullmetrix');
        $this->description = $this->l('Connect your PrestaShop store to Fullmetrix to sync your orders.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall the Fullmetrix module?');
    }

    public static function pluginVersion()
    {
        return self::$pluginVersion;
    }

    public static function pluginChannel()
    {
        return self::$pluginChannel;
    }

    private static function loadGuard()
    {
        if (PHP_VERSION_ID < 70100 || self::$guardFailed) {
            return false;
        }
        if (class_exists('FullmetrixGuard', false) && class_exists('FullmetrixJournal', false)) {
            return true;
        }
        if (!is_file(__DIR__ . '/classes/FullmetrixJournal.php') || !is_file(__DIR__ . '/classes/FullmetrixGuard.php')) {
            self::$guardFailed = true;

            return false;
        }
        try {
            require_once __DIR__ . '/classes/FullmetrixJournal.php';
            require_once __DIR__ . '/classes/FullmetrixGuard.php';
        } catch (Exception $e) {
            self::$guardFailed = true;

            return false;
        } catch (Throwable $e) {
            self::$guardFailed = true;

            return false;
        }
        if (!class_exists('FullmetrixGuard', false) || !class_exists('FullmetrixJournal', false)) {
            self::$guardFailed = true;

            return false;
        }

        return true;
    }

    public function hookDisplayHeader()
    {
        return self::loadGuard() ? FullmetrixGuard::renderHeader() : '';
    }

    public function hookDisplayFooter()
    {
    }

    public function hookDisplayBackOfficeHeader()
    {
        return $this->active && self::loadGuard() ? FullmetrixGuard::renderBackOfficeRelay() : '';
    }

    public function hookActionValidateOrder($params)
    {
    }

    public function hookActionOrderStatusUpdate($params)
    {
    }

    public function hookActionOrderSlipAdd($params)
    {
    }

    public function hookActionValidateOrderAfter($params)
    {
        if (self::loadGuard()) {
            FullmetrixGuard::record('actionValidateOrderAfter', 'order_ready', $params, 'orders[].id', null);
        }
    }

    public function hookActionCustomerAccountUpdate($params)
    {
        if (self::loadGuard()) {
            FullmetrixGuard::record('actionCustomerAccountUpdate', 'customer', $params, 'customer.id', null);
        }
    }

    public function hookActionObjectCustomerUpdateAfter($params)
    {
        if (self::loadGuard()) {
            FullmetrixGuard::record('actionObjectCustomerUpdateAfter', 'customer', $params, 'object.id', null);
        }
    }

    public function hookActionAuthentication($params)
    {
        if (self::loadGuard()) {
            FullmetrixGuard::record('actionAuthentication', 'customer_login', $params, 'customer.id', null);
        }
    }

    public function hookActionProductUpdate($params)
    {
        if (self::loadGuard()) {
            FullmetrixGuard::record('actionProductUpdate', 'product', $params, 'id_product', null);
        }
    }

    public function hookActionProductAdd($params)
    {
        if (self::loadGuard()) {
            FullmetrixGuard::record('actionProductAdd', 'product', $params, 'id_product', null);
        }
    }

    public function hookActionProductDelete($params)
    {
        if (self::loadGuard()) {
            FullmetrixGuard::record('actionProductDelete', 'product_deleted', $params, 'id_product', null);
        }
    }

    public function hookActionObjectCombinationAddAfter($params)
    {
        if (self::loadGuard()) {
            FullmetrixGuard::record('actionObjectCombinationAddAfter', 'combination', $params, 'object.id_product', 'object.id');
        }
    }

    public function hookActionObjectCombinationUpdateAfter($params)
    {
        if (self::loadGuard()) {
            FullmetrixGuard::record('actionObjectCombinationUpdateAfter', 'combination', $params, 'object.id_product', 'object.id');
        }
    }

    public function hookActionObjectCombinationDeleteAfter($params)
    {
        if (self::loadGuard()) {
            FullmetrixGuard::record('actionObjectCombinationDeleteAfter', 'combination_deleted', $params, 'object.id_product', 'object.id');
        }
    }

    public function hookActionObjectSpecificPriceAddAfter($params)
    {
        if (self::loadGuard()) {
            FullmetrixGuard::record('actionObjectSpecificPriceAddAfter', 'combination', $params, 'object.id_product', 'object.id_product_attribute');
        }
    }

    public function hookActionObjectSpecificPriceUpdateAfter($params)
    {
        if (self::loadGuard()) {
            FullmetrixGuard::record('actionObjectSpecificPriceUpdateAfter', 'combination', $params, 'object.id_product', 'object.id_product_attribute');
        }
    }

    public function hookActionObjectSpecificPriceDeleteAfter($params)
    {
        if (self::loadGuard()) {
            FullmetrixGuard::record('actionObjectSpecificPriceDeleteAfter', 'combination', $params, 'object.id_product', 'object.id_product_attribute');
        }
    }

    public function hookActionUpdateQuantity($params)
    {
        if (self::loadGuard()) {
            FullmetrixGuard::record('actionUpdateQuantity', 'stock', $params, 'id_product', 'id_product_attribute');
        }
    }

    public function hookActionObjectCartRuleAddAfter($params)
    {
        if (self::loadGuard()) {
            FullmetrixGuard::record('actionObjectCartRuleAddAfter', 'coupon', $params, 'object.id', null);
        }
    }

    public function hookActionObjectCartRuleUpdateAfter($params)
    {
        if (self::loadGuard()) {
            FullmetrixGuard::record('actionObjectCartRuleUpdateAfter', 'coupon', $params, 'object.id', null);
        }
    }

    public function hookActionObjectCartRuleDeleteAfter($params)
    {
        if (self::loadGuard()) {
            FullmetrixGuard::record('actionObjectCartRuleDeleteAfter', 'coupon_deleted', $params, 'object.id', null);
        }
    }

    public function hookActionCategoryAdd($params)
    {
        if (self::loadGuard()) {
            FullmetrixGuard::record('actionCategoryAdd', 'category', $params, 'category.id', null);
        }
    }

    public function hookActionCategoryUpdate($params)
    {
        if (self::loadGuard()) {
            FullmetrixGuard::record('actionCategoryUpdate', 'category', $params, 'category.id', null);
        }
    }

    public function hookActionCategoryDelete($params)
    {
        if (self::loadGuard()) {
            FullmetrixGuard::record('actionCategoryDelete', 'category_deleted', $params, 'category.id', null);
        }
    }

    public function hookActionCartSave($params)
    {
        if (self::loadGuard()) {
            FullmetrixGuard::record('actionCartSave', 'cart_saved', $params, 'cart.id', null);
        }
    }

    public static function getApiBase()
    {
        if (!self::loadGuard()) {
            return self::$apiBase;
        }
        $custom = Configuration::get(FullmetrixJournal::key('api_base'));

        return $custom ? $custom : self::$apiBase;
    }

    public static function isActive()
    {
        return self::loadGuard() && FullmetrixGuard::isConnected();
    }

    public function getRuntimeContext()
    {
        return $this->context;
    }

    public function install()
    {
        if (PHP_VERSION_ID < 70100) {
            $this->_errors[] = $this->l('Fullmetrix requires PHP 7.1 or later.');

            return false;
        }
        if (!self::loadGuard()) {
            $this->_errors[] = $this->l('The Fullmetrix module files are incomplete. Upload the whole module again.');

            return false;
        }
        if (!parent::install()) {
            return false;
        }
        foreach ($this->registerFullmetrixHooks(false) as $failure) {
            $this->recordUpgradeError('install_hooks', $failure);
        }
        $configured = Configuration::updateValue(FullmetrixJournal::key('code'), '')
            && Configuration::updateValue(FullmetrixJournal::key('secret'), '')
            && Configuration::updateValue(FullmetrixJournal::key('registered'), false)
            && Configuration::updateValue('FULLMETRIX_LAST_SYNC', '');
        try {
            $this->installJournal();
            Configuration::updateGlobalValue(FullmetrixJournal::key('journal_ack'), (string) time());
        } catch (Exception $e) {
            $this->recordUpgradeError('install_journal', $e->getMessage());
        } catch (Throwable $e) {
            $this->recordUpgradeError('install_journal', $e->getMessage());
        }
        if (empty(Configuration::getGlobalValue(FullmetrixJournal::key('flags')))) {
            Configuration::updateGlobalValue(FullmetrixJournal::key('flags'), json_encode(['v' => 0, 'tracker' => true]));
        }

        return $configured;
    }

    public function uninstall()
    {
        if (!parent::uninstall()) {
            return false;
        }
        $dropped = false;
        try {
            self::loadGuard();
            $db = Db::getInstance();
            $previous = (int) $db->getValue('SELECT @@SESSION.lock_wait_timeout');
            $db->execute('SET SESSION lock_wait_timeout = 2');
            $dropped = (bool) $db->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . FullmetrixJournal::table() . '`');
            if ($previous > 0) {
                $db->execute('SET SESSION lock_wait_timeout = ' . $previous);
            }
        } catch (Exception $e) {
            $dropped = false;
        } catch (Throwable $e) {
            $dropped = false;
        }
        if (!$dropped) {
            $this->_confirmations[] = $this->l('The Fullmetrix journal table is in use and was kept. It is inert and can be dropped later.');
        }
        $this->adminDeleteAllConfiguration();

        return true;
    }

    public function installJournal()
    {
        if (!self::loadGuard()) {
            throw new Exception('Fullmetrix journal classes are missing');
        }
        $file = __DIR__ . '/classes/FullmetrixJournalStore.php';
        if (!is_file($file)) {
            throw new Exception('FullmetrixJournalStore.php is missing');
        }
        require_once $file;
        $db = Db::getInstance();
        $previous = (int) $db->getValue('SELECT @@SESSION.lock_wait_timeout');
        $result = null;
        $failure = null;
        try {
            $result = FullmetrixJournalStore::ensureTable();
        } catch (Exception $e) {
            $failure = $e;
        } catch (Throwable $e) {
            $failure = $e;
        }
        if ($previous > 0) {
            $db->execute('SET SESSION lock_wait_timeout = ' . $previous);
        }
        if ($failure !== null) {
            throw $failure;
        }
        $state = is_array($result) && isset($result['state']) ? $result['state'] : FullmetrixJournal::state('denied');
        if ($state === 'ready') {
            $state = FullmetrixJournal::state('ready');
        } elseif ($state !== FullmetrixJournal::state('no_innodb')) {
            $state = FullmetrixJournal::state('denied');
        }
        Configuration::updateGlobalValue(FullmetrixJournal::key('journal_v'), $state);
        if (empty(Configuration::getGlobalValue(FullmetrixJournal::key('breaker')))) {
            Configuration::updateGlobalValue(FullmetrixJournal::key('breaker'), '{}');
        }
        if (is_array($result) && !empty($result['error'])) {
            $this->recordUpgradeError('journal', (string) $result['error']);
        }

        return $state;
    }

    public function registerFullmetrixHooks($addedOnly)
    {
        $hooks = $addedOnly ? self::adminAddedHooks() : array_merge(self::adminLegacyHooks(), self::adminAddedHooks());
        $shops = Shop::getShops(true, null, true);
        $failures = [];
        foreach ($hooks as $hook) {
            try {
                if (!$this->registerHook($hook, $shops)) {
                    $failures[] = $hook;
                }
            } catch (Exception $e) {
                $failures[] = $hook . ': ' . $e->getMessage();
            } catch (Throwable $e) {
                $failures[] = $hook . ': ' . $e->getMessage();
            }
        }

        return $failures;
    }

    public function ensureHooksRegistered()
    {
        $result = ['ran' => false, 'registered' => [], 'failed' => []];
        if (!self::loadGuard()) {
            return $result;
        }
        $marker = FullmetrixJournal::key('hooks_repaired');
        if (Configuration::getGlobalValue($marker) === self::pluginVersion()) {
            return $result;
        }
        $result['ran'] = true;
        $shops = Shop::getShops(true, null, true);
        foreach (self::adminAddedHooks() as $hook) {
            try {
                if ($this->isRegisteredInHook($hook)) {
                    continue;
                }
                if ($this->registerHook($hook, $shops)) {
                    $result['registered'][] = $hook;
                } else {
                    $result['failed'][] = $hook;
                }
            } catch (Exception $e) {
                $result['failed'][] = $hook . ': ' . $e->getMessage();
            } catch (Throwable $e) {
                $result['failed'][] = $hook . ': ' . $e->getMessage();
            }
        }
        if ($result['failed'] === []) {
            Configuration::updateGlobalValue($marker, self::pluginVersion());
        }

        return $result;
    }

    public function missingHooks()
    {
        $missing = [];
        foreach (array_merge(self::adminLegacyHooks(), self::adminAddedHooks()) as $hook) {
            try {
                if (!$this->isRegisteredInHook($hook)) {
                    $missing[] = $hook;
                }
            } catch (Exception $e) {
                $missing[] = $hook;
            } catch (Throwable $e) {
                $missing[] = $hook;
            }
        }

        return $missing;
    }

    public function recordUpgradeError($step, $message)
    {
        try {
            self::loadGuard();
            $raw = Configuration::getGlobalValue(FullmetrixJournal::key('upgrade_err'));
            $errors = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
            if (!is_array($errors)) {
                $errors = [];
            }
            $errors[] = ['step' => (string) $step, 'message' => substr((string) $message, 0, 500)];
            Configuration::updateGlobalValue(FullmetrixJournal::key('upgrade_err'), json_encode(array_values(array_slice($errors, -10))));
        } catch (Exception $e) {
            return;
        } catch (Throwable $e) {
            return;
        }
    }

    public function flushUpgradeErrors()
    {
        foreach ($this->_errors as $error) {
            $this->recordUpgradeError('core', is_string($error) ? $error : json_encode($error));
        }
        $this->_errors = [];
    }

    public function getContent()
    {
        if (PHP_VERSION_ID < 70100) {
            return $this->adminLegacyPhpPage();
        }
        self::loadGuard();
        $output = '';

        if (Tools::isSubmit('submitFullmetrixConnect')) {
            $connectionCode = Tools::getValue(FullmetrixJournal::key('code'));

            if (empty($connectionCode)) {
                $output .= $this->displayError($this->l('Please enter a connection code.'));
            } elseif (!preg_match('/^FMTX-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $connectionCode)) {
                $output .= $this->displayError($this->l('Invalid code format. The code must be in FMTX-XXXX-XXXX-XXXX format.'));
            } else {
                Configuration::updateValue(FullmetrixJournal::key('code'), $connectionCode);
                $result = $this->adminRegister();

                if ($result === true) {
                    $output .= $this->displayConfirmation($this->l('Connection successful! Your store is now connected to Fullmetrix.'));
                } else {
                    $output .= $this->displayError($result);
                }
            }
        }

        if (Tools::isSubmit('submitFullmetrixDisconnect')) {
            $this->adminDisconnect();
            $output .= $this->displayConfirmation($this->l('Successfully disconnected.'));
        }

        $this->adminAssignForm();

        if (!Configuration::get(FullmetrixJournal::key('registered'))) {
            return $output . $this->display(__FILE__, 'views/templates/admin/connect.tpl');
        }

        if ($this->adminConnectionConflict()) {
            $output .= $this->displayWarning($this->l('Only one Fullmetrix 2.0 connection is supported per PrestaShop installation. Another store of this installation stays on classic synchronization.'));
        }

        return $output . $this->display(__FILE__, 'views/templates/admin/connected.tpl') . $this->adminRenderActivity();
    }

    private function adminAssignForm()
    {
        $this->context->smarty->assign([
            'fullmetrix_logo' => $this->_path . 'logo.png',
            'connection_code' => Configuration::get('FULLMETRIX_CONNECTION_CODE'),
            'form_action' => $this->context->link->getAdminLink('AdminModules', false)
                . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name
                . '&token=' . Tools::getAdminTokenLite('AdminModules'),
        ]);
    }

    private function adminDisconnect()
    {
        Configuration::updateValue('FULLMETRIX_CONNECTION_SECRET', '');
        Configuration::updateValue('FULLMETRIX_REGISTERED', false);
        Configuration::updateValue('FULLMETRIX_WEBHOOKS_ENABLED', false);
        Configuration::updateValue('FULLMETRIX_LAST_SYNC', '');
        foreach (['ORDERS', 'CUSTOMERS', 'PRODUCTS', 'CATEGORIES', 'COUPONS', 'REFUNDS'] as $entity) {
            Configuration::deleteByName('FULLMETRIX_SYNC_' . $entity);
        }
    }

    private function adminLegacyPhpPage()
    {
        $output = $this->displayError($this->l('Fullmetrix 2.0 requires PHP 7.1 or later. This server runs an older PHP version: synchronization is stopped. Ask your host to upgrade PHP, or disconnect the store below.'));
        if (Tools::isSubmit('submitFullmetrixDisconnect')) {
            $this->adminDisconnect();
            $output .= $this->displayConfirmation($this->l('Successfully disconnected.'));
        }
        if (!Configuration::get('FULLMETRIX_REGISTERED')) {
            return $output;
        }
        $this->adminAssignForm();

        return $output . $this->display(__FILE__, 'views/templates/admin/connected.tpl');
    }

    public function getStoreSettings()
    {
        $currencyId = (int) Configuration::get('PS_CURRENCY_DEFAULT');
        $currency = new Currency($currencyId);
        $isoCode = !empty($currency->iso_code) ? $currency->iso_code : 'EUR';

        $timezone = Configuration::get('PS_TIMEZONE') ?: 'Europe/Paris';

        $langId = (int) Configuration::get('PS_LANG_DEFAULT');
        $lang = new Language($langId);
        $locale = 'fr-FR';
        if (!empty($lang->locale)) {
            $locale = $lang->locale;
        } elseif (!empty($lang->language_code)) {
            $locale = $lang->language_code;
        }

        $format = !empty($currency->format) ? (int) $currency->format : 0;
        $position = in_array($format, [3, 4], true) ? 'left' : 'right';

        $decimalSeparator = in_array($format, [2, 4], true) ? ',' : '.';
        $thousandSeparator = in_array($format, [2, 4], true) ? ' ' : ',';
        if ($format === 5) {
            $thousandSeparator = "'";
            $decimalSeparator = '.';
        }

        $currencyFields = get_object_vars($currency);
        if (isset($currencyFields['precision'])) {
            $numDecimals = (int) $currencyFields['precision'];
        } elseif (isset($currencyFields['decimals'])) {
            $numDecimals = (int) $currencyFields['decimals'] ? 2 : 0;
        } else {
            $numDecimals = 2;
        }

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

    private static function adminLegacyHooks()
    {
        return [
            'displayBackOfficeHeader',
            'displayHeader',
            'displayFooter',
            'actionValidateOrder',
            'actionOrderStatusUpdate',
            'actionCustomerAccountUpdate',
            'actionObjectCustomerUpdateAfter',
            'actionProductUpdate',
            'actionProductAdd',
            'actionObjectCombinationAddAfter',
            'actionObjectCombinationUpdateAfter',
            'actionObjectCombinationDeleteAfter',
            'actionObjectSpecificPriceAddAfter',
            'actionObjectSpecificPriceUpdateAfter',
            'actionObjectSpecificPriceDeleteAfter',
            'actionUpdateQuantity',
            'actionObjectCartRuleUpdateAfter',
            'actionOrderSlipAdd',
            'actionCategoryUpdate',
            'actionCartSave',
            'actionAuthentication',
        ];
    }

    private static function adminAddedHooks()
    {
        $hooks = [
            'actionProductDelete',
            'actionObjectCartRuleAddAfter',
            'actionObjectCartRuleDeleteAfter',
            'actionCategoryAdd',
            'actionCategoryDelete',
        ];
        if (version_compare(_PS_VERSION_, '8.0.0', '>=')) {
            $hooks[] = 'actionValidateOrderAfter';
        }

        return $hooks;
    }

    private function adminDeleteAllConfiguration()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT DISTINCT `name` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` LIKE \'FULLMETRIX\\_%\''
        );
        if (!is_array($rows)) {
            return;
        }
        foreach ($rows as $row) {
            if (isset($row['name'])) {
                Configuration::deleteByName($row['name']);
            }
        }
    }

    private function adminRenderActivity()
    {
        $labels = [
            'orders' => $this->l('Orders'),
            'customers' => $this->l('Customers'),
            'products' => $this->l('Products'),
            'categories' => $this->l('Categories'),
            'coupons' => $this->l('Coupons'),
            'refunds' => $this->l('Refunds'),
        ];
        $entities = [];
        $latest = 0;
        foreach ($labels as $entity => $label) {
            $data = json_decode((string) Configuration::get('FULLMETRIX_SYNC_' . Tools::strtoupper($entity)), true);
            if (!is_array($data) || !isset($data['t'])) {
                continue;
            }
            $latest = max($latest, (int) $data['t']);
            $count = isset($data['c']) ? (int) $data['c'] : 0;
            if ($count > 0) {
                $entities[] = ['label' => $label, 'count_formatted' => number_format($count, 0, ',', ' ')];
            }
        }

        $this->context->smarty->assign([
            'sync_in_progress' => false,
            'last_sync' => $latest > 0,
            'last_sync_entities' => $entities,
        ]);

        return $this->display(__FILE__, 'views/templates/admin/sync_summary.tpl');
    }

    private function adminConnectionConflict()
    {
        $conflict = json_decode((string) Configuration::getGlobalValue(FullmetrixJournal::key('conn_conflict')), true);

        return is_array($conflict) && isset($conflict['at']) && time() - (int) $conflict['at'] < 604800;
    }

    private function adminRegister()
    {
        try {
            $code = Configuration::get(FullmetrixJournal::key('code'));
            if (empty($code)) {
                return $this->l('Connection code missing');
            }

            $body = json_encode([
                'connectionCode' => $code,
                'siteUrl' => $this->adminShopUrl(),
                'storeCanonicalId' => hash('sha256', _COOKIE_KEY_ . ':' . (int) $this->context->shop->id),
                'pluginVersion' => self::pluginVersion(),
                'platform' => 'prestashop',
                'channel' => self::pluginChannel(),
                'storeSettings' => $this->getStoreSettings(),
            ]);

            $response = $this->adminPost(self::getApiBase() . '/register', $body);
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
                Configuration::updateValue(FullmetrixJournal::key('secret'), $result['connectionSecret']);
            }
            Configuration::updateValue(FullmetrixJournal::key('registered'), true);
            Configuration::updateValue(FullmetrixJournal::key('webhooks_enabled'), true);

            return true;
        } catch (Exception $e) {
            return $this->l('Connection error to Fullmetrix server');
        } catch (Throwable $e) {
            return $this->l('Connection error to Fullmetrix server');
        }
    }

    private function adminShopUrl()
    {
        try {
            $ssl = Configuration::get('PS_SSL_ENABLED') || Tools::usingSecureMode();

            return rtrim(($ssl ? 'https://' : 'http://') . $this->context->shop->domain . $this->context->shop->physical_uri, '/');
        } catch (Exception $e) {
            return Tools::getShopDomainSsl(true);
        } catch (Throwable $e) {
            return Tools::getShopDomainSsl(true);
        }
    }

    private function adminPost($url, $body)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return false;
        }

        return ['body' => $response, 'http_code' => $httpCode];
    }
}
