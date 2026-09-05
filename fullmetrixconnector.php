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
    public const FULLMETRIX_API_BASE = 'https://fullmetrix.com/api/plugin';
    public const FULLMETRIX_VERSION = '1.6.0';
    public const FULLMETRIX_CHANNEL = 'community';

    /** Lifetime of a signed cart-recovery link, in seconds (30 days). */
    public const CART_LINK_TTL = 2592000;

    /** @var array<string, mixed> Per-request Configuration cache (avoids hot-path DB reads) */
    private static $configCache = [];

    /** @var array<string, mixed>|false|null Cached plugin config for the current request */
    private static $cachedConfigThisRequest = false;

    /** @var array<int, array{endpoint: string, body: string, headers: array<int, string>}> */
    private static $pendingConsents = [];

    /** @var bool */
    private static $consentShutdownRegistered = false;

    /**
     * Read a Configuration value with per-request memoisation.
     */
    public static function getConfig($key)
    {
        if (!array_key_exists($key, self::$configCache)) {
            self::$configCache[$key] = Configuration::get($key);
        }

        return self::$configCache[$key];
    }

    /**
     * Invalidate the in-memory Configuration cache (called from admin handlers
     * that change connection state during the same request).
     */
    public static function clearConfigCache()
    {
        self::$configCache = [];
        self::$cachedConfigThisRequest = false;
    }

    public static function getApiBase()
    {
        $custom = self::getConfig('FULLMETRIX_API_BASE');

        return $custom ?: self::FULLMETRIX_API_BASE;
    }

    /**
     * Returns true when the plugin is fully configured and active.
     * All frontend hooks should early-return when this is false.
     */
    public static function isActive()
    {
        if (!self::getConfig('FULLMETRIX_REGISTERED')) {
            return false;
        }
        $code = self::getConfig('FULLMETRIX_CONNECTION_CODE');
        $secret = self::getConfig('FULLMETRIX_CONNECTION_SECRET');

        return !empty($code) && !empty($secret);
    }

    public function __construct()
    {
        $this->name = 'fullmetrixconnector';
        $this->tab = 'analytics_stats';
        $this->version = '1.6.0';
        $this->author = 'Fullmetrix';
        $this->module_key = '9cc46e05bb451f6ed601277b8096d019';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7.4.0', 'max' => '9.99.99'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Fullmetrix');
        $this->description = $this->l('Connect your PrestaShop store to Fullmetrix to sync your orders.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall the Fullmetrix module?');

        try {
            $context = $this->context;
            $shopId = $context->shop ? (int) $context->shop->id : 1;
            $link = $context->link;

            FullmetrixWebhookSender::init($shopId, $link);
            FullmetrixTrackingSender::init();
        } catch (Throwable $e) {
            FullmetrixLogger::logException('module_construct', $e);
        }
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
            && $this->registerHook('actionObjectCombinationAddAfter')
            && $this->registerHook('actionObjectCombinationUpdateAfter')
            && $this->registerHook('actionObjectCombinationDeleteAfter')
            && $this->registerHook('actionObjectSpecificPriceAddAfter')
            && $this->registerHook('actionObjectSpecificPriceUpdateAfter')
            && $this->registerHook('actionObjectSpecificPriceDeleteAfter')
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
        foreach (['ORDERS', 'CUSTOMERS', 'PRODUCTS', 'CATEGORIES', 'COUPONS', 'REFUNDS'] as $entity) {
            Configuration::deleteByName('FULLMETRIX_SYNC_' . $entity);
        }

        return parent::uninstall()
            && Configuration::deleteByName('FULLMETRIX_CONNECTION_CODE')
            && Configuration::deleteByName('FULLMETRIX_CONNECTION_SECRET')
            && Configuration::deleteByName('FULLMETRIX_REGISTERED')
            && Configuration::deleteByName('FULLMETRIX_WEBHOOKS_ENABLED')
            && Configuration::deleteByName('FULLMETRIX_LAST_SYNC')
            && Configuration::deleteByName('FULLMETRIX_EXPORT_COUNT')
            && Configuration::deleteByName('FULLMETRIX_SYNC_IN_PROGRESS')
            && Configuration::deleteByName('FULLMETRIX_LOGS')
            && Configuration::deleteByName('FULLMETRIX_PLUGIN_CONFIG');
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

        try {
            $payload = Tools::getValue('fm_cart');
            $signature = Tools::getValue('fm_cart_sig');

            if (!is_string($payload) || !is_string($signature)) {
                return;
            }

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

            // Signed links carry no nonce, so an archived or forwarded URL would
            // replay forever. Links issued from 1.5.6 onwards expire; older ones
            // have no timestamp and stay accepted so in-flight emails keep working.
            if (!empty($data['ts']) && (time() - (int) $data['ts']) > self::CART_LINK_TTL) {
                return;
            }

            $context = $this->context;
            if (!$context->language || !$context->currency || !$context->link) {
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
                if (!$cart->add()) {
                    return;
                }
                $context->cart = $cart;
                if ($context->cookie) {
                    $context->cookie->__set('id_cart', (int) $cart->id);
                }
            }

            // A recovery link must never destroy what the shopper put in the cart
            // themselves. Lines already present win; only missing ones are added.
            $existingKeys = [];
            $existingProducts = $cart->getProducts();
            if (is_array($existingProducts)) {
                foreach ($existingProducts as $product) {
                    $existingKeys[(int) $product['id_product'] . '_' . (int) $product['id_product_attribute']] = true;
                }
            }

            foreach ($data['items'] as $item) {
                try {
                    $productId = isset($item['id']) ? (int) $item['id'] : 0;
                    $variationId = isset($item['v']) ? (int) $item['v'] : 0;
                    $quantity = isset($item['q']) ? max(1, (int) $item['q']) : 1;

                    if (isset($existingKeys[$productId . '_' . $variationId])) {
                        continue;
                    }

                    if ($productId > 0 && Product::existsInDatabase($productId, 'product')) {
                        $cart->updateQty($quantity, $productId, $variationId);
                    }
                } catch (Throwable $e) {
                    // Skip this item
                }
            }

            if (!empty($data['c']) && is_array($data['c'])) {
                foreach ($data['c'] as $couponCode) {
                    try {
                        if (!is_string($couponCode)) {
                            continue;
                        }
                        $couponCode = trim($couponCode);
                        if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $couponCode)) {
                            continue;
                        }
                        $cartRuleId = (int) CartRule::getIdByCode($couponCode);
                        if ($cartRuleId > 0) {
                            $cart->addCartRule($cartRuleId);
                        }
                    } catch (Throwable $e) {
                        // Skip this coupon
                    }
                }
            }

            if (headers_sent()) {
                return;
            }

            $cartUrl = $context->link->getPageLink('cart', true, null, ['action' => 'show']);
            if (is_string($cartUrl) && $cartUrl !== '') {
                Tools::redirect($cartUrl);
                exit;
            }
        } catch (Throwable $e) {
            FullmetrixLogger::logException('maybeRebuildCart', $e);
        }
    }

    /**
     * Get cached plugin config from Fullmetrix API (cached 5 min)
     */
    private function getCachedConfig()
    {
        if (self::$cachedConfigThisRequest !== false) {
            return self::$cachedConfigThisRequest;
        }

        try {
            $cacheKey = 'FULLMETRIX_PLUGIN_CONFIG';
            $cached = self::getConfig($cacheKey);
            $cachedData = null;
            if ($cached) {
                $cachedData = json_decode($cached, true);
                if (is_array($cachedData) && isset($cachedData['_ts']) && (time() - $cachedData['_ts']) < 1800) {
                    self::$cachedConfigThisRequest = $cachedData;

                    return $cachedData;
                }
                if (is_array($cachedData) && isset($cachedData['_failed_at']) && (time() - $cachedData['_failed_at']) < 300) {
                    self::$cachedConfigThisRequest = $cachedData;

                    return $cachedData;
                }
            }

            $secret = self::getConfig('FULLMETRIX_CONNECTION_SECRET');
            $code = self::getConfig('FULLMETRIX_CONNECTION_CODE');
            if (empty($secret) || empty($code)) {
                self::$cachedConfigThisRequest = is_array($cachedData) ? $cachedData : null;

                return self::$cachedConfigThisRequest;
            }

            // This runs inside hookDisplayHeader, so the visitor is still waiting
            // on the page. Stay well under what a shopper would notice; the config
            // only toggles the tracker and a miss is cached for 5 minutes anyway.
            $connectTimeoutMs = 200;
            $totalTimeoutMs = 500;

            $apiBase = self::getConfig('FULLMETRIX_API_BASE');
            if (empty($apiBase)) {
                $apiBase = 'https://fullmetrix.com/api/plugin';
            }

            $headers = FullmetrixSecurity::createSignedHeaders($secret, $code, '');

            $ch = curl_init($apiBase . '/config');
            if (!$ch) {
                self::$cachedConfigThisRequest = is_array($cachedData) ? $cachedData : null;

                return self::$cachedConfigThisRequest;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutMs,
                CURLOPT_TIMEOUT_MS => $totalTimeoutMs,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER => [
                    'X-Fullmetrix-Connection-Code: ' . $headers['X-Fullmetrix-Connection-Code'],
                    'X-Fullmetrix-Signature: ' . $headers['X-Fullmetrix-Signature'],
                    'X-Fullmetrix-Timestamp: ' . $headers['X-Fullmetrix-Timestamp'],
                    'X-Fullmetrix-Plugin-Version: ' . self::FULLMETRIX_VERSION,
                ],
            ]);
            $body = @curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || empty($body)) {
                $negative = is_array($cachedData) ? $cachedData : [];
                $negative['_failed_at'] = time();
                $negativeJson = json_encode($negative);
                if (is_string($negativeJson)) {
                    Configuration::updateValue($cacheKey, $negativeJson, false, 0, 0);
                    self::$configCache[$cacheKey] = $negativeJson;
                }
                self::$cachedConfigThisRequest = is_array($cachedData) ? $cachedData : null;

                return self::$cachedConfigThisRequest;
            }

            $config = json_decode($body, true);
            if (!is_array($config)) {
                $negative = is_array($cachedData) ? $cachedData : [];
                $negative['_failed_at'] = time();
                $negativeJson = json_encode($negative);
                if (is_string($negativeJson)) {
                    Configuration::updateValue($cacheKey, $negativeJson, false, 0, 0);
                    self::$configCache[$cacheKey] = $negativeJson;
                }
                self::$cachedConfigThisRequest = is_array($cachedData) ? $cachedData : null;

                return self::$cachedConfigThisRequest;
            }

            $config['_ts'] = time();
            Configuration::updateValue($cacheKey, json_encode($config), false, 0, 0);
            self::$configCache[$cacheKey] = json_encode($config);
            self::$cachedConfigThisRequest = $config;

            return $config;
        } catch (Throwable $e) {
            FullmetrixLogger::logException('getCachedConfig', $e);
            self::$cachedConfigThisRequest = null;

            return null;
        }
    }

    public function hookDisplayHeader()
    {
        try {
            if (!self::isActive()) {
                return '';
            }

            $config = $this->getCachedConfig();
            if (is_array($config) && isset($config['trackerEnabled']) && $config['trackerEnabled'] === false) {
                return '';
            }

            $this->maybeRebuildCart();

            $apiBase = self::getConfig('FULLMETRIX_API_BASE');
            if (empty($apiBase)) {
                $apiBase = 'https://fullmetrix.com/api/plugin';
            }
            $origin = rtrim(str_replace('/api/plugin', '', $apiBase), '/');

            $this->context->smarty->assign([
                'origin' => $origin,
                'code' => self::getConfig('FULLMETRIX_CONNECTION_CODE'),
                'version' => self::FULLMETRIX_VERSION . '.' . floor(time() / 300),
            ]);

            return $this->display(__FILE__, 'views/templates/hook/header.tpl');
        } catch (Throwable $e) {
            FullmetrixLogger::logException('hookDisplayHeader', $e);

            return '';
        }
    }

    public function hookDisplayFooter()
    {
        try {
            if (!self::isActive()) {
                return '';
            }
            $this->getCachedConfig();

            return '';
        } catch (Throwable $e) {
            FullmetrixLogger::logException('hookDisplayFooter', $e);

            return '';
        }
    }

    // ─── Webhook hook handlers ────────────────────────────────────────

    public function hookActionValidateOrder($params)
    {
        try {
            if (!self::isActive()) {
                return;
            }
            if (!isset($params['order'])) {
                return;
            }
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
                $phone = null;
                try {
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
                } catch (Throwable $e) {
                    // Address lookup failed, continue with bare contact data
                }
                FullmetrixTrackingSender::enqueueEvent('identify', [], $contact);

                $this->forwardCheckoutConsent($customerObj, $phone);
            }
        } catch (Throwable $e) {
            FullmetrixLogger::logException('hookActionValidateOrder', $e);
        }
    }

    private function forwardCheckoutConsent(Customer $customer, $phone)
    {
        try {
            $code = self::getConfig('FULLMETRIX_CONNECTION_CODE');
            $secret = self::getConfig('FULLMETRIX_CONNECTION_SECRET');
            if (empty($code) || empty($secret)) {
                return;
            }

            $config = $this->getCachedConfig();
            $channels = ['email'];
            if (is_array($config) && !empty($config['checkoutConsent']['channels']) && is_array($config['checkoutConsent']['channels'])) {
                $channels = $config['checkoutConsent']['channels'];
            }

            $apiBase = self::getConfig('FULLMETRIX_API_BASE');
            if (empty($apiBase)) {
                $apiBase = self::FULLMETRIX_API_BASE;
            }
            $endpoint = rtrim(str_replace('/api/plugin', '', $apiBase), '/') . '/api/checkout-consent';

            $pageUrl = self::buildPublicUrl();

            $payload = [
                'key' => $code,
                'email' => (string) $customer->email,
                'consent' => (bool) $customer->newsletter,
                'channels' => $channels,
                'pageUrl' => $pageUrl,
            ];
            if (!empty($phone)) {
                $payload['phone'] = $phone;
            }

            $body = json_encode($payload);
            if ($body === false) {
                return;
            }

            $signed = FullmetrixSecurity::createSignedHeaders($secret, $code, $body);
            $consentHeaders = [
                'Content-Type: application/json',
                'X-Fullmetrix-Connection-Code: ' . $signed['X-Fullmetrix-Connection-Code'],
                'X-Fullmetrix-Signature: ' . $signed['X-Fullmetrix-Signature'],
                'X-Fullmetrix-Timestamp: ' . $signed['X-Fullmetrix-Timestamp'],
                'X-Fullmetrix-Plugin-Version: ' . self::FULLMETRIX_VERSION,
            ];

            self::$pendingConsents[] = [
                'endpoint' => $endpoint,
                'body' => $body,
                'headers' => $consentHeaders,
            ];

            if (!self::$consentShutdownRegistered) {
                register_shutdown_function([__CLASS__, 'flushPendingConsents']);
                self::$consentShutdownRegistered = true;
            }
        } catch (Throwable $e) {
            FullmetrixLogger::logException('forwardCheckoutConsent', $e);
        }
    }

    /**
     * Shutdown handler that posts all queued checkout consents.
     * Registered once per request even if forwardCheckoutConsent fires multiple times.
     */
    public static function flushPendingConsents()
    {
        if (empty(self::$pendingConsents)) {
            return;
        }

        FullmetrixWebhookSender::keepRunningAfterAbort();

        try {
            if (!function_exists('curl_init')) {
                self::$pendingConsents = [];

                return;
            }

            $connectTimeoutMs = 200;
            $totalTimeoutMs = 500;

            foreach (self::$pendingConsents as $consent) {
                try {
                    $ch = curl_init($consent['endpoint']);
                    if (!$ch) {
                        continue;
                    }
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => $consent['body'],
                        CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutMs,
                        CURLOPT_TIMEOUT_MS => $totalTimeoutMs,
                        CURLOPT_NOSIGNAL => true,
                        CURLOPT_SSL_VERIFYPEER => true,
                        CURLOPT_FOLLOWLOCATION => false,
                        CURLOPT_HTTPHEADER => $consent['headers'],
                    ]);
                    @curl_exec($ch);
                    @curl_close($ch);
                } catch (Throwable $e) {
                    // Skip this consent, keep flushing the rest
                }
            }
        } catch (Throwable $e) {
            FullmetrixLogger::logException('flushPendingConsents', $e);
        }

        self::$pendingConsents = [];
    }

    /**
     * Build a public URL for the current request using PrestaShop's trusted
     * shop domain (avoids exposing attacker-controlled $_SERVER['HTTP_HOST']).
     */
    public static function buildPublicUrl()
    {
        try {
            $useSsl = function_exists('Tools::usingSecureMode') ? Tools::usingSecureMode() : false;
            $domain = method_exists('Tools', 'getShopDomainSsl')
                ? Tools::getShopDomainSsl()
                : (method_exists('Tools', 'getShopDomain') ? Tools::getShopDomain() : '');
            if (!$domain) {
                return '';
            }
            $scheme = $useSsl ? 'https://' : 'http://';
            $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
            $uri = substr($uri, 0, 2048);

            return $scheme . $domain . $uri;
        } catch (Throwable $e) {
            return '';
        }
    }

    public function hookActionOrderStatusUpdate($params)
    {
        try {
            if (!self::isActive()) {
                return;
            }
            if (isset($params['id_order'])) {
                FullmetrixWebhookSender::enqueue('order', (int) $params['id_order']);
            }
        } catch (Throwable $e) {
            FullmetrixLogger::logException('hookActionOrderStatusUpdate', $e);
        }
    }

    public function hookActionCustomerAccountUpdate($params)
    {
        try {
            if (!self::isActive()) {
                return;
            }
            if (isset($params['customer']) && is_object($params['customer'])) {
                FullmetrixWebhookSender::enqueue('customer', (int) $params['customer']->id);
            }
        } catch (Throwable $e) {
            FullmetrixLogger::logException('hookActionCustomerAccountUpdate', $e);
        }
    }

    public function hookActionObjectCustomerUpdateAfter($params)
    {
        try {
            if (!self::isActive()) {
                return;
            }
            if (isset($params['object']) && is_object($params['object'])) {
                FullmetrixWebhookSender::enqueue('customer', (int) $params['object']->id);
            }
        } catch (Throwable $e) {
            FullmetrixLogger::logException('hookActionObjectCustomerUpdateAfter', $e);
        }
    }

    public function hookActionProductUpdate($params)
    {
        try {
            if (!self::isActive()) {
                return;
            }
            if (isset($params['id_product'])) {
                $this->enqueueProductAndCombinations((int) $params['id_product']);
            } elseif (isset($params['product']) && is_object($params['product'])) {
                $this->enqueueProductAndCombinations((int) $params['product']->id);
            }
        } catch (Throwable $e) {
            FullmetrixLogger::logException('hookActionProductUpdate', $e);
        }
    }

    public function hookActionProductAdd($params)
    {
        try {
            if (!self::isActive()) {
                return;
            }
            if (isset($params['id_product'])) {
                $this->enqueueProductAndCombinations((int) $params['id_product']);
            } elseif (isset($params['product']) && is_object($params['product'])) {
                $this->enqueueProductAndCombinations((int) $params['product']->id);
            }
        } catch (Throwable $e) {
            FullmetrixLogger::logException('hookActionProductAdd', $e);
        }
    }

    public function hookActionObjectCombinationAddAfter($params)
    {
        $this->enqueueProductPriceObject($params);
    }

    public function hookActionObjectCombinationUpdateAfter($params)
    {
        $this->enqueueProductPriceObject($params);
    }

    public function hookActionObjectCombinationDeleteAfter($params)
    {
        $this->enqueueProductPriceObject($params);
    }

    public function hookActionObjectSpecificPriceAddAfter($params)
    {
        $this->enqueueProductPriceObject($params);
    }

    public function hookActionObjectSpecificPriceUpdateAfter($params)
    {
        $this->enqueueProductPriceObject($params);
    }

    public function hookActionObjectSpecificPriceDeleteAfter($params)
    {
        $this->enqueueProductPriceObject($params);
    }

    private function enqueueProductPriceObject($params)
    {
        try {
            if (!self::isActive() || !isset($params['object']) || !is_object($params['object'])) {
                return;
            }
            $productId = isset($params['object']->id_product) ? (int) $params['object']->id_product : 0;
            if ($productId > 0) {
                $attributeId = isset($params['object']->id_product_attribute)
                    ? (int) $params['object']->id_product_attribute
                    : (isset($params['object']->id) && $params['object'] instanceof Combination ? (int) $params['object']->id : 0);
                $this->enqueueProductAndCombinations($productId, $attributeId);
            }
        } catch (Throwable $e) {
            FullmetrixLogger::logException('enqueueProductPriceObject', $e);
        }
    }

    private function enqueueProductAndCombinations($productId, $attributeId = 0)
    {
        if ($productId <= 0) {
            return;
        }
        FullmetrixWebhookSender::enqueue('product', $productId);
        if ($attributeId > 0) {
            FullmetrixWebhookSender::enqueue('product', $productId . '_' . $attributeId);

            return;
        }

        $rows = Db::getInstance()->executeS(
            'SELECT id_product_attribute FROM ' . _DB_PREFIX_ . 'product_attribute WHERE id_product = ' . (int) $productId
        );
        if (!is_array($rows)) {
            return;
        }
        foreach ($rows as $row) {
            $combinationId = (int) ($row['id_product_attribute'] ?? 0);
            if ($combinationId > 0) {
                FullmetrixWebhookSender::enqueue('product', $productId . '_' . $combinationId);
            }
        }
    }

    public function hookActionUpdateQuantity($params)
    {
        try {
            if (!self::isActive()) {
                return;
            }
            if (isset($params['id_product'])) {
                FullmetrixWebhookSender::enqueue('product', (int) $params['id_product']);
            }
        } catch (Throwable $e) {
            FullmetrixLogger::logException('hookActionUpdateQuantity', $e);
        }
    }

    public function hookActionObjectCartRuleUpdateAfter($params)
    {
        try {
            if (!self::isActive()) {
                return;
            }
            if (isset($params['object']) && is_object($params['object'])) {
                FullmetrixWebhookSender::enqueue('coupon', (int) $params['object']->id);
            }
        } catch (Throwable $e) {
            FullmetrixLogger::logException('hookActionObjectCartRuleUpdateAfter', $e);
        }
    }

    public function hookActionOrderSlipAdd($params)
    {
        try {
            if (!self::isActive()) {
                return;
            }
            if (isset($params['order']) && is_object($params['order'])) {
                $orderId = (int) $params['order']->id;
                if ($orderId <= 0) {
                    return;
                }
                $sql = 'SELECT MAX(id_order_slip) FROM ' . _DB_PREFIX_ . 'order_slip WHERE id_order = ' . $orderId;
                $slipId = (int) Db::getInstance()->getValue($sql);
                if ($slipId > 0) {
                    FullmetrixWebhookSender::enqueue('refund', $slipId);
                }
            }
        } catch (Throwable $e) {
            FullmetrixLogger::logException('hookActionOrderSlipAdd', $e);
        }
    }

    public function hookActionCategoryUpdate($params)
    {
        try {
            if (!self::isActive()) {
                return;
            }
            if (isset($params['category']) && is_object($params['category'])) {
                FullmetrixWebhookSender::enqueue('category', (int) $params['category']->id);
            }
        } catch (Throwable $e) {
            FullmetrixLogger::logException('hookActionCategoryUpdate', $e);
        }
    }

    // ─── Tracking hook handlers ─────────────────────────────────────

    public function hookActionCartSave($params)
    {
        try {
            if (!self::isActive()) {
                return;
            }
            $cart = isset($params['cart']) ? $params['cart'] : null;
            if (!$cart || !($cart instanceof Cart) || !$cart->id) {
                return;
            }

            try {
                if (method_exists($cart, 'orderExists') && $cart->orderExists()) {
                    return;
                }
            } catch (Throwable $e) {
                // If orderExists check fails, continue with cart_updated emit
            }

            $products = $cart->getProducts();
            if (empty($products) || !is_array($products)) {
                return;
            }

            $context = $this->context;

            $link = $context->link;
            $items = [];
            foreach ($products as $p) {
                $imageUrl = null;
                if ($link && !empty($p['id_image'])) {
                    try {
                        $imageUrl = $link->getImageLink(
                            isset($p['link_rewrite']) ? $p['link_rewrite'] : '',
                            $p['id_image'],
                            'home_default'
                        );
                        if ($imageUrl && strpos($imageUrl, 'http') !== 0) {
                            $imageUrl = 'https://' . $imageUrl;
                        }
                    } catch (Throwable $e) {
                        $imageUrl = null;
                    }
                }

                $productUrl = null;
                if ($link) {
                    try {
                        $productUrl = $link->getProductLink((int) $p['id_product']);
                    } catch (Throwable $e) {
                        $productUrl = null;
                    }
                }

                $items[] = [
                    'product_id' => (int) $p['id_product'],
                    'variation_id' => !empty($p['id_product_attribute']) ? (int) $p['id_product_attribute'] : null,
                    'name' => isset($p['name']) ? $p['name'] : '',
                    'quantity' => isset($p['cart_quantity']) ? (int) $p['cart_quantity'] : 0,
                    'price' => isset($p['price_wt']) ? (float) $p['price_wt'] : 0.0,
                    'line_total' => isset($p['total_wt']) ? (float) $p['total_wt'] : 0.0,
                    'sku' => !empty($p['reference']) ? $p['reference'] : null,
                    'image_url' => $imageUrl,
                    'url' => $productUrl,
                ];
            }

            $total = 0.0;
            $subtotal = 0.0;
            $discountTotal = 0.0;
            $shippingTotal = 0.0;
            $taxTotal = 0.0;
            try {
                $totalWithTax = (float) $cart->getOrderTotal(true, Cart::BOTH);
                $totalNoTax = (float) $cart->getOrderTotal(false, Cart::BOTH);
                $total = $totalWithTax;
                $subtotal = (float) $cart->getOrderTotal(true, Cart::ONLY_PRODUCTS);
                $discountTotal = abs((float) $cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS));
                $shippingTotal = (float) $cart->getOrderTotal(true, Cart::ONLY_SHIPPING);
                $taxTotal = $totalWithTax - $totalNoTax;
            } catch (Throwable $e) {
                // Totals fail when no address/carrier is set (guest visitors).
                // Fall back to summed line totals so we still have a usable snapshot.
                foreach ($items as $i) {
                    $subtotal += (float) $i['line_total'];
                }
                $total = $subtotal;
            }

            $couponCodes = [];
            try {
                $rules = $cart->getCartRules();
                if (is_array($rules)) {
                    foreach ($rules as $r) {
                        if (isset($r['name'])) {
                            $couponCodes[] = $r['name'];
                        }
                    }
                }
            } catch (Throwable $e) {
                $couponCodes = [];
            }

            $recoveryUrl = null;
            try {
                $recoveryUrl = $this->buildCartRecoveryUrl($cart);
            } catch (Throwable $e) {
                $recoveryUrl = null;
            }

            $itemCount = 0;
            try {
                $itemCount = (int) $cart->nbProducts();
            } catch (Throwable $e) {
                foreach ($items as $i) {
                    $itemCount += (int) $i['quantity'];
                }
            }

            $cartSnapshot = [
                'currency' => $context->currency ? $context->currency->iso_code : 'EUR',
                'total' => $total,
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'shipping_total' => $shippingTotal,
                'tax_total' => $taxTotal,
                'coupon_codes' => $couponCodes,
                'item_count' => $itemCount,
                'items' => $items,
                'recovery_url' => $recoveryUrl,
            ];

            FullmetrixTrackingSender::enqueueEvent('cart_updated', [
                'cart' => $cartSnapshot,
                'source' => 'server',
            ]);
        } catch (Throwable $e) {
            FullmetrixLogger::logException('hookActionCartSave', $e);
        }
    }

    public function hookActionAuthentication($params)
    {
        try {
            if (!self::isActive()) {
                return;
            }
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

            try {
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
            } catch (Throwable $e) {
                // Address lookup failed, continue with bare contact data
            }

            FullmetrixTrackingSender::enqueueEvent('identify', [], $contact);
        } catch (Throwable $e) {
            FullmetrixLogger::logException('hookActionAuthentication', $e);
        }
    }

    private function buildCartRecoveryUrl(Cart $cart)
    {
        try {
            $products = $cart->getProducts();
            if (empty($products) || !is_array($products)) {
                return null;
            }

            $itemsData = [];
            foreach ($products as $p) {
                $itemsData[] = [
                    'id' => (int) $p['id_product'],
                    'v' => !empty($p['id_product_attribute']) ? (int) $p['id_product_attribute'] : 0,
                    'q' => isset($p['cart_quantity']) ? (int) $p['cart_quantity'] : 1,
                ];
            }

            $coupons = [];
            try {
                $rules = $cart->getCartRules();
                if (is_array($rules)) {
                    foreach ($rules as $r) {
                        if (isset($r['name'])) {
                            $coupons[] = $r['name'];
                        }
                    }
                }
            } catch (Throwable $e) {
                $coupons = [];
            }

            $secret = Configuration::get('FULLMETRIX_CONNECTION_SECRET');
            if (empty($secret)) {
                return null;
            }

            $payloadJson = json_encode(['items' => $itemsData, 'c' => $coupons, 'ts' => time()]);
            if ($payloadJson === false) {
                return null;
            }
            $encoded = strtr(base64_encode($payloadJson), '+/', '-_');
            $signature = hash_hmac('sha256', $encoded, $secret);

            $context = $this->context;
            if (!$context->link) {
                return null;
            }
            $baseUrl = $context->link->getPageLink('cart', true);
            if (!is_string($baseUrl) || $baseUrl === '') {
                return null;
            }
            $separator = (strpos($baseUrl, '?') !== false) ? '&' : '?';

            return $baseUrl . $separator . 'fm_cart=' . $encoded . '&fm_cart_sig=' . $signature;
        } catch (Throwable $e) {
            FullmetrixLogger::logException('buildCartRecoveryUrl', $e);

            return null;
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
                self::clearConfigCache();

                $result = $this->registerWithFullmetrix();
                self::clearConfigCache();

                if ($result === true) {
                    $output .= $this->displayConfirmation($this->l('Connection successful! Your store is now connected to Fullmetrix.'));
                } else {
                    $output .= $this->displayError($result);
                }
            }
        }

        if (Tools::isSubmit('submitFullmetrixDisconnect')) {
            Configuration::updateValue('FULLMETRIX_CONNECTION_SECRET', '');
            Configuration::updateValue('FULLMETRIX_REGISTERED', false);
            Configuration::updateValue('FULLMETRIX_WEBHOOKS_ENABLED', false);
            Configuration::updateValue('FULLMETRIX_LAST_SYNC', '');
            Configuration::updateValue('FULLMETRIX_EXPORT_COUNT', 0);
            Configuration::updateValue('FULLMETRIX_SYNC_IN_PROGRESS', '');
            foreach (['ORDERS', 'CUSTOMERS', 'PRODUCTS', 'CATEGORIES', 'COUPONS', 'REFUNDS'] as $entity) {
                Configuration::deleteByName('FULLMETRIX_SYNC_' . $entity);
            }
            Configuration::deleteByName('FULLMETRIX_PLUGIN_CONFIG');
            self::clearConfigCache();
            $output .= $this->displayConfirmation($this->l('Successfully disconnected.'));
        }

        $isRegistered = (bool) Configuration::get('FULLMETRIX_REGISTERED');

        if (!$isRegistered) {
            $output .= $this->renderForm();

            return $output;
        }

        $output .= $this->renderForm();
        $output .= $this->renderSyncActivity();

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

    protected function getConfigFormAction()
    {
        return $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name
            . '&token=' . Tools::getAdminTokenLite('AdminModules');
    }

    protected function renderConnectedForm($connectionCode)
    {
        $this->context->smarty->assign([
            'fullmetrix_logo' => $this->_path . 'logo.png',
            'connection_code' => $connectionCode,
            'form_action' => $this->getConfigFormAction(),
        ]);

        return $this->display(__FILE__, 'views/templates/admin/connected.tpl');
    }

    protected function renderConnectForm($connectionCode)
    {
        $this->context->smarty->assign([
            'fullmetrix_logo' => $this->_path . 'logo.png',
            'connection_code' => $connectionCode,
            'form_action' => $this->getConfigFormAction(),
        ]);

        return $this->display(__FILE__, 'views/templates/admin/connect.tpl');
    }

    protected function renderSyncActivity()
    {
        $inProgressRaw = Configuration::get('FULLMETRIX_SYNC_IN_PROGRESS');
        $inProgress = !empty($inProgressRaw) ? json_decode($inProgressRaw, true) : null;
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

        $entityLabels = [
            'orders' => $this->l('Orders'),
            'customers' => $this->l('Customers'),
            'products' => $this->l('Products'),
            'categories' => $this->l('Categories'),
            'coupons' => $this->l('Coupons'),
            'refunds' => $this->l('Refunds'),
        ];

        $lastSyncEntities = [];
        $latestCompletedAt = 0;

        foreach ($entityLabels as $entity => $label) {
            $raw = Configuration::get('FULLMETRIX_SYNC_' . Tools::strtoupper($entity));
            if (empty($raw)) {
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data) || !isset($data['t'])) {
                continue;
            }
            $latestCompletedAt = max($latestCompletedAt, (int) $data['t']);
            $count = (int) ($data['c'] ?? 0);
            if ($count > 0) {
                $lastSyncEntities[] = [
                    'label' => $label,
                    'count_formatted' => number_format($count, 0, ',', ' '),
                ];
            }
        }

        // Fallback to legacy single-key record (paginated export path)
        if ($latestCompletedAt === 0) {
            $lastSyncRaw = Configuration::get('FULLMETRIX_LAST_SYNC');
            $lastSync = !empty($lastSyncRaw) ? json_decode($lastSyncRaw, true) : null;
            if (is_array($lastSync) && isset($lastSync['completed_at'])) {
                $latestCompletedAt = (int) $lastSync['completed_at'];
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
        }

        $hasLastSync = $latestCompletedAt > 0;

        $this->context->smarty->assign([
            'sync_in_progress' => $syncInProgress,
            'sync_elapsed' => $syncElapsed,
            'sync_type_label' => $syncTypeLabel,
            'last_sync' => $hasLastSync,
            'last_sync_time_ago' => $hasLastSync ? $this->formatTimeAgo($latestCompletedAt) : '',
            'last_sync_entities' => $lastSyncEntities,
            'export_count' => $exportCount,
            'export_count_formatted' => number_format($exportCount, 0, ',', ' '),
        ]);

        return $this->display(__FILE__, 'views/templates/admin/sync_activity.tpl');
    }

    protected function formatTimeAgo($timestamp)
    {
        try {
            $diff = time() - (int) $timestamp;

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

            return date('d/m/Y H:i', (int) $timestamp);
        } catch (Throwable $e) {
            return '';
        }
    }

    protected function formatDuration($seconds)
    {
        try {
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
        } catch (Throwable $e) {
            return '';
        }
    }

    protected function validateCodeFormat($code)
    {
        return preg_match('/^FMTX-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $code);
    }

    protected function registerWithFullmetrix()
    {
        try {
            $code = Configuration::get('FULLMETRIX_CONNECTION_CODE');

            if (empty($code)) {
                return $this->l('Connection code missing');
            }

            $data = [
                'connectionCode' => $code,
                'siteUrl' => $this->getShopUrl(),
                'storeCanonicalId' => hash('sha256', _COOKIE_KEY_ . ':' . (int) $this->context->shop->id),
                'pluginVersion' => self::FULLMETRIX_VERSION,
                'platform' => 'prestashop',
                'channel' => self::FULLMETRIX_CHANNEL,
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
        } catch (Throwable $e) {
            FullmetrixLogger::logException('registerWithFullmetrix', $e);

            return $this->l('Connection error to Fullmetrix server');
        }
    }

    protected function getStoreSettings()
    {
        $currencyId = (int) Configuration::get('PS_CURRENCY_DEFAULT');
        $currency = new Currency($currencyId);
        $isoCode = $currency->iso_code ?: 'EUR';

        $timezone = Configuration::get('PS_TIMEZONE') ?: 'Europe/Paris';

        $langId = (int) Configuration::get('PS_LANG_DEFAULT');
        $lang = new Language($langId);
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
        } catch (Throwable $e) {
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
