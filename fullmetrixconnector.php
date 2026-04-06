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
require_once dirname(__FILE__) . '/classes/FullmetrixTrackingSender.php';
require_once dirname(__FILE__) . '/classes/FullmetrixLogger.php';

class FullmetrixConnector extends Module
{
    const FULLMETRIX_API_BASE = 'https://fullmetrix.com/api/plugin';
    const FULLMETRIX_VERSION = '1.0.2';

    public static function getApiBase()
    {
        $custom = Configuration::get('FULLMETRIX_API_BASE');
        return $custom ?: self::FULLMETRIX_API_BASE;
    }

    public function __construct()
    {
        $this->name = 'fullmetrixconnector';
        $this->tab = 'analytics_stats';
        $this->version = '1.0.1';
        $this->author = 'Fullmetrix';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => '8.99.99'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Fullmetrix');
        $this->description = $this->l('Connect your PrestaShop store to Fullmetrix to sync your orders.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall the Fullmetrix module?');

        $context = Context::getContext();
        $shopId = ($context && $context->shop) ? (int) $context->shop->id : 1;
        $link = ($context) ? $context->link : null;

        FullmetrixWebhookSender::init($shopId, $link);
        FullmetrixTrackingSender::init();
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
            && $this->registerHook('actionCartSave')
            && $this->registerHook('actionAuthentication')
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

    private static $cartRebuildDone = false;

    private function maybeRebuildCart()
    {
        if (self::$cartRebuildDone || empty(Tools::getValue('fm_cart')) || empty(Tools::getValue('fm_cart_sig'))) {
            return;
        }
        self::$cartRebuildDone = true;

        $payload = Tools::getValue('fm_cart');
        $signature = Tools::getValue('fm_cart_sig');

        $secret = Configuration::get('FULLMETRIX_CONNECTION_SECRET');
        if (empty($secret) || !hash_equals(hash_hmac('sha256', $payload, $secret), $signature)) {
            return;
        }

        $json = base64_decode(strtr($payload, '-_', '+/'));
        if (!is_string($json) || empty($json)) {
            return;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['items']) || !is_array($data['items'])) {
            return;
        }

        $context = Context::getContext();
        if (!$context || !$context->language || !$context->currency) {
            return;
        }

        $cart = $context->cart;
        if (!$cart || !Validate::isLoadedObject($cart)) {
            $cart = new Cart();
            $cart->id_lang = (int) $context->language->id;
            $cart->id_currency = (int) $context->currency->id;
            if ($context->customer && $context->customer->id) {
                $cart->id_customer = (int) $context->customer->id;
                $addressId = (int) Address::getFirstCustomerAddressId($context->customer->id);
                $cart->id_address_delivery = $addressId > 0 ? $addressId : 0;
            }
            $cart->add();
            $context->cart = $cart;
            $context->cookie->__set('id_cart', (int) $cart->id);
        }

        // Clear existing cart
        foreach ($cart->getProducts() as $product) {
            $cart->deleteProduct(
                (int) $product['id_product'],
                (int) $product['id_product_attribute']
            );
        }

        // Add items from recovery payload
        foreach ($data['items'] as $item) {
            $productId = isset($item['id']) ? (int) $item['id'] : 0;
            $variationId = isset($item['v']) ? (int) $item['v'] : 0;
            $quantity = isset($item['q']) ? max(1, (int) $item['q']) : 1;

            if ($productId > 0 && Product::existsInDatabase($productId, 'product')) {
                $cart->updateQty($quantity, $productId, $variationId);
            }
        }

        // Apply coupons
        if (!empty($data['c']) && is_array($data['c'])) {
            foreach ($data['c'] as $couponCode) {
                $couponCode = pSQL(trim($couponCode));
                if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $couponCode)) {
                    continue;
                }
                $cartRuleId = (int) CartRule::getIdByCode($couponCode);
                if ($cartRuleId > 0) {
                    $cart->addCartRule($cartRuleId);
                }
            }
        }

        // Redirect to cart — Tools::redirect sends header, must exit explicitly
        Tools::redirect($context->link->getPageLink('cart', true, null, ['action' => 'show']));
        exit;
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
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'X-Fullmetrix-Connection-Code: ' . $headers['X-Fullmetrix-Connection-Code'],
                'X-Fullmetrix-Signature: ' . $headers['X-Fullmetrix-Signature'],
                'X-Fullmetrix-Timestamp: ' . $headers['X-Fullmetrix-Timestamp'],
                'X-Fullmetrix-Plugin-Version: ' . self::FULLMETRIX_VERSION,
            ],
        ]);
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

    public function hookDisplayHeader()
    {
        // Handle cart recovery URL
        $this->maybeRebuildCart();

        $code = Configuration::get('FULLMETRIX_CONNECTION_CODE');
        $registered = Configuration::get('FULLMETRIX_REGISTERED');

        if (empty($code) || !$registered) {
            return '';
        }

        $apiBase = Configuration::get('FULLMETRIX_API_BASE');
        if (empty($apiBase)) {
            $apiBase = 'https://fullmetrix.com/api/plugin';
        }
        $origin = rtrim(str_replace('/api/plugin', '', $apiBase), '/');

        $this->context->smarty->assign([
            'origin' => $origin,
            'code' => $code,
            'version' => self::FULLMETRIX_VERSION . '.' . floor(time() / 300),
        ]);

        return $this->display(__FILE__, 'views/templates/hook/header.tpl');
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

        // Widget + forms loader is auto-loaded by the tracker script (t.js)
        return '';
    }

    // ─── Webhook hook handlers ────────────────────────────────────────

    public function hookActionValidateOrder($params)
    {
        if (isset($params['order'])) {
            $order = $params['order'];
            FullmetrixWebhookSender::enqueue('order', (int) $order->id);

            $customerObj = isset($params['customer']) && $params['customer'] instanceof Customer && Validate::isLoadedObject($params['customer'])
                ? $params['customer']
                : new Customer((int) $order->id_customer);
            if (Validate::isLoadedObject($customerObj) && !empty($customerObj->email)) {
                $contact = [
                    'email' => $customerObj->email,
                    'first_name' => $customerObj->firstname,
                    'last_name' => $customerObj->lastname,
                    'customer_id' => (int) $customerObj->id,
                ];
                $address = new Address((int) $order->id_address_invoice);
                if (Validate::isLoadedObject($address)) {
                    $phone = $address->phone_mobile ?: ($address->phone ?: null);
                    if ($phone) {
                        $contact['phone'] = $phone;
                    }
                    $countryIso = Country::getIsoById((int) $address->id_country);
                    if ($countryIso) {
                        $contact['country_code'] = $countryIso;
                    }
                }
                FullmetrixTrackingSender::enqueueEvent('identify', [], $contact);
            }
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

    // ─── Tracking hook handlers ─────────────────────────────────────

    public function hookActionCartSave($params)
    {
        $cart = isset($params['cart']) ? $params['cart'] : null;
        if (!$cart || !($cart instanceof Cart) || !$cart->id) {
            return;
        }

        $products = $cart->getProducts();
        if (empty($products)) {
            return;
        }

        $context = Context::getContext();
        $items = [];
        foreach ($products as $p) {
            $imageUrl = null;
            if (!empty($p['id_image'])) {
                $imageUrl = $context->link->getImageLink(
                    isset($p['link_rewrite']) ? $p['link_rewrite'] : '',
                    $p['id_image'],
                    'home_default'
                );
                if ($imageUrl && strpos($imageUrl, 'http') !== 0) {
                    $imageUrl = 'https://' . $imageUrl;
                }
            }

            $items[] = [
                'product_id' => (int) $p['id_product'],
                'variation_id' => !empty($p['id_product_attribute']) ? (int) $p['id_product_attribute'] : null,
                'name' => $p['name'],
                'quantity' => (int) $p['cart_quantity'],
                'price' => (float) $p['price_wt'],
                'line_total' => (float) $p['total_wt'],
                'sku' => !empty($p['reference']) ? $p['reference'] : null,
                'image_url' => $imageUrl,
                'url' => $context->link->getProductLink((int) $p['id_product']),
            ];
        }

        $recoveryUrl = $this->buildCartRecoveryUrl($cart);

        $cartSnapshot = [
            'currency' => $context->currency ? $context->currency->iso_code : 'EUR',
            'total' => (float) $cart->getOrderTotal(true, Cart::BOTH),
            'subtotal' => (float) $cart->getOrderTotal(true, Cart::ONLY_PRODUCTS),
            'discount_total' => abs((float) $cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS)),
            'shipping_total' => (float) $cart->getOrderTotal(true, Cart::ONLY_SHIPPING),
            'tax_total' => (float) ($cart->getOrderTotal(true) - $cart->getOrderTotal(false)),
            'coupon_codes' => array_map(function ($r) { return $r['name']; }, $cart->getCartRules()),
            'item_count' => (int) $cart->nbProducts(),
            'items' => $items,
            'recovery_url' => $recoveryUrl,
        ];

        FullmetrixTrackingSender::enqueueEvent('cart_updated', [
            'cart' => $cartSnapshot,
            'source' => 'server',
        ]);
    }

    public function hookActionAuthentication($params)
    {
        $customer = isset($params['customer']) && $params['customer'] instanceof Customer
            ? $params['customer']
            : null;
        if (!$customer || !Validate::isLoadedObject($customer) || empty($customer->email)) {
            return;
        }

        $contact = [
            'email' => $customer->email,
            'first_name' => $customer->firstname ?: null,
            'last_name' => $customer->lastname ?: null,
            'customer_id' => (int) $customer->id,
        ];

        $addressId = (int) Address::getFirstCustomerAddressId($customer->id);
        if ($addressId > 0) {
            $address = new Address($addressId);
            if (Validate::isLoadedObject($address)) {
                $phone = $address->phone_mobile ?: ($address->phone ?: null);
                if ($phone) {
                    $contact['phone'] = $phone;
                }
            }
        }

        FullmetrixTrackingSender::enqueueEvent('identify', [], $contact);
    }

    private function buildCartRecoveryUrl(Cart $cart)
    {
        $products = $cart->getProducts();
        if (empty($products)) {
            return null;
        }

        $itemsData = [];
        foreach ($products as $p) {
            $itemsData[] = [
                'id' => (int) $p['id_product'],
                'v' => !empty($p['id_product_attribute']) ? (int) $p['id_product_attribute'] : 0,
                'q' => (int) $p['cart_quantity'],
            ];
        }

        $coupons = array_map(function ($r) { return $r['name']; }, $cart->getCartRules());
        $payload = ['items' => $itemsData, 'c' => $coupons];
        $encoded = strtr(base64_encode(json_encode($payload)), '+/', '-_');
        $secret = Configuration::get('FULLMETRIX_CONNECTION_SECRET');
        $signature = hash_hmac('sha256', $encoded, $secret);

        $context = Context::getContext();
        $baseUrl = $context->link->getPageLink('cart', true);
        $separator = (strpos($baseUrl, '?') !== false) ? '&' : '?';
        return $baseUrl . $separator . 'fm_cart=' . $encoded . '&fm_cart_sig=' . $signature;
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
                } else {
                    $output .= $this->displayError($result);
                }
            }
        }

        if (Tools::isSubmit('submitFullmetrixDisconnect')) {
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
            $output .= $this->displayConfirmation($this->l('Logs cleared.'));
        }

        $isRegistered = (bool) Configuration::get('FULLMETRIX_REGISTERED');

        if (!$isRegistered) {
            $output .= $this->renderForm();
            return $output;
        }

        // Tabs for connected state — wrapper uses HelperForm output (already safe HTML)
        $formHtml = $this->renderForm();
        $syncHtml = $this->renderSyncActivity();
        $logsHtml = $this->renderLogsTab();

        $this->context->smarty->assign([
            'connection_label' => $this->l('Connection'),
            'logs_label' => $this->l('Logs'),
        ]);
        $output .= $this->display(__FILE__, 'views/templates/admin/tabs_header.tpl');
        $output .= $formHtml;
        $output .= $syncHtml;
        $this->context->smarty->assign(['is_logs_tab' => true]);
        $output .= $this->display(__FILE__, 'views/templates/admin/tabs_separator.tpl');
        $output .= $logsHtml;
        $output .= $this->display(__FILE__, 'views/templates/admin/tabs_footer.tpl');

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

        $syncInProgress = !empty($inProgress);
        $syncElapsed = '';
        $syncTypeLabel = '';

        if ($syncInProgress) {
            $elapsed = time() - (int) ($inProgress['started_at'] ?? time());
            $syncElapsed = $this->formatDuration($elapsed);
            if (isset($inProgress['type']) && $inProgress['type'] === 'bulk') {
                $syncTypeLabel = ' (full export)';
            }
        }

        $lastSyncData = null;
        $lastSyncTimeAgo = '';
        $lastSyncMode = '';
        $lastSyncEntities = [];

        if (!$syncInProgress && $lastSync && isset($lastSync['completed_at'])) {
            $lastSyncData = $lastSync;
            $lastSyncTimeAgo = $this->formatTimeAgo((int) $lastSync['completed_at']);

            if (!empty($lastSync['type'])) {
                $lastSyncMode = $lastSync['type'] === 'bulk'
                    ? $this->l('Full export')
                    : $this->l('Paginated export');
            }

            if (!empty($lastSync['entities']) && is_array($lastSync['entities'])) {
                foreach ($lastSync['entities'] as $label => $count) {
                    if ($count > 0) {
                        $lastSyncEntities[] = [
                            'label' => $label,
                            'count_formatted' => number_format($count, 0, ',', ' '),
                        ];
                    }
                }
            }
        }

        $this->context->smarty->assign([
            'sync_in_progress' => $syncInProgress,
            'sync_elapsed' => $syncElapsed,
            'sync_type_label' => $syncTypeLabel,
            'last_sync' => $lastSyncData,
            'last_sync_time_ago' => $lastSyncTimeAgo,
            'last_sync_mode' => $lastSyncMode,
            'last_sync_entities' => $lastSyncEntities,
            'export_count' => $exportCount,
            'export_count_formatted' => number_format($exportCount, 0, ',', ' '),
        ]);

        return $this->display(__FILE__, 'views/templates/admin/sync_activity.tpl');
    }

    protected function renderLogsTab()
    {
        $inProgressRaw = Configuration::get('FULLMETRIX_SYNC_IN_PROGRESS');
        $inProgress = !empty($inProgressRaw) ? json_decode($inProgressRaw, true) : null;

        $adminUrl = $this->context->link->getAdminLink('AdminModules', true)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;

        $badgeColors = [
            'registered' => '#27ae60',
            'disconnected' => '#95a5a6',
            'sync_start' => '#2980b9',
            'sync_complete' => '#27ae60',
            'sync_error' => '#e74c3c',
            'webhook' => '#2980b9',
        ];

        $typeLabels = [
            'registered' => 'Connected',
            'disconnected' => 'Disconnected',
            'sync_start' => 'Sync',
            'sync_complete' => 'Sync OK',
            'sync_error' => 'Error',
            'webhook' => 'Webhook',
        ];

        $logs = [];
        foreach ($rawLogs as $log) {
            $color = isset($badgeColors[$log['type']]) ? $badgeColors[$log['type']] : '#95a5a6';
            $label = isset($typeLabels[$log['type']]) ? $typeLabels[$log['type']] : $log['type'];

            $details = "\xE2\x80\x94"; // em dash
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
                $details = implode(' | ', $parts);
            }

            $logs[] = [
                'color' => $color,
                'label' => $label,
                'message' => $log['message'],
                'details' => $details,
                'date_added' => date('d/m/Y H:i:s', (int) $log['time']),
            ];
        }

        $this->context->smarty->assign([
            'logs' => $logs,
            'admin_url' => $adminUrl,
            'has_logs' => !empty($logs),
            'sync_in_progress' => !empty($inProgress),
        ]);

        return $this->display(__FILE__, 'views/templates/admin/logs.tpl');
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

        $format = (int) $currency->format;
        $position = in_array($format, [3, 4], true) ? 'left' : 'right';

        $decimalSeparator = in_array($format, [2, 4], true) ? ',' : '.';
        $thousandSeparator = in_array($format, [2, 4], true) ? ' ' : ',';
        if ($format === 5) {
            $thousandSeparator = "'";
            $decimalSeparator = '.';
        }

        $numDecimals = (int) $currency->precision;

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
