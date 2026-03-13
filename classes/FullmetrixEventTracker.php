<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Fullmetrix Event Tracker for PrestaShop
 *
 * Handles frontend tracking script injection and REST API endpoints
 * for behavioral tracking (page views, product views, cart adds, etc.)
 */
class FullmetrixEventTracker
{
    const COOKIE_VISITOR_ID = 'fm_vid';
    const COOKIE_SESSION_ID = 'fm_sid';
    const COOKIE_CONTACT_ID = 'fm_cid';
    const VISITOR_COOKIE_DURATION = 63072000; // 2 years (same as WooCommerce)
    const SESSION_DURATION = 1800; // 30 minutes

    private static $instance = null;
    private static $page_data = null;

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize tracking hooks
     */
    public static function init()
    {
        // REST API routes are handled via the controller
    }

    /**
     * Get or generate visitor ID (persistent - 2 years)
     */
    public static function getVisitorId()
    {
        if (isset($_COOKIE[self::COOKIE_VISITOR_ID])) {
            $vid = $_COOKIE[self::COOKIE_VISITOR_ID];
            // Validate UUID format
            if (preg_match('/^[a-f0-9\-]{36}$/', $vid)) {
                return $vid;
            }
        }

        $visitor_id = self::generateUuid();
        self::setCookie(self::COOKIE_VISITOR_ID, $visitor_id, time() + self::VISITOR_COOKIE_DURATION);
        return $visitor_id;
    }

    /**
     * Get or generate session ID (expires after 30 min inactivity)
     * Format: "uuid.timestamp" to sync with JS tracker
     */
    public static function getSessionId()
    {
        if (isset($_COOKIE[self::COOKIE_SESSION_ID])) {
            $sid_data = $_COOKIE[self::COOKIE_SESSION_ID];
            $parts = explode('.', $sid_data);

            if (count($parts) === 2) {
                $sid = $parts[0];
                $timestamp = intval($parts[1]);

                // Session still valid (within 30 min)
                if ((time() - $timestamp) < self::SESSION_DURATION) {
                    // Refresh session timestamp
                    $new_sid_data = $sid . '.' . time();
                    self::setCookie(self::COOKIE_SESSION_ID, $new_sid_data, 0);
                    return $sid;
                }
            }
        }

        // Create new session with timestamp
        $session_id = self::generateUuid();
        $sid_data = $session_id . '.' . time();
        self::setCookie(self::COOKIE_SESSION_ID, $sid_data, 0);
        return $session_id;
    }

    /**
     * Set a cookie with proper settings (SameSite=Lax, Secure if HTTPS)
     */
    private static function setCookie($name, $value, $expires)
    {
        if (headers_sent()) {
            return false;
        }

        $secure = Tools::usingSecureMode();
        $domain = '';

        // Use modern cookie options if PHP 7.3+
        if (PHP_VERSION_ID >= 70300) {
            setcookie($name, $value, array(
                'expires' => $expires,
                'path' => '/',
                'domain' => $domain,
                'secure' => $secure,
                'httponly' => false, // Accessible by JS
                'samesite' => 'Lax',
            ));
        } else {
            setcookie($name, $value, $expires, '/; SameSite=Lax', $domain, $secure, false);
        }

        // Also set in $_COOKIE for immediate access
        $_COOKIE[$name] = $value;
        return true;
    }

    /**
     * Get contact ID if set
     */
    public static function getContactId()
    {
        return isset($_COOKIE[self::COOKIE_CONTACT_ID]) ? $_COOKIE[self::COOKIE_CONTACT_ID] : null;
    }

    /**
     * Generate UUID v4
     */
    private static function generateUuid()
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Get current page type
     */
    public static function getPageType()
    {
        $controller = Tools::getValue('controller');

        $page_types = array(
            'index' => 'home',
            'category' => 'category',
            'product' => 'product',
            'cart' => 'cart',
            'order' => 'checkout',
            'orderopc' => 'checkout',
            'supercheckout' => 'checkout',
            'order-confirmation' => 'thank_you',
            'orderconfirmation' => 'thank_you',
            'search' => 'search',
            'cms' => 'page',
            'contact' => 'contact',
            'my-account' => 'account',
            'authentication' => 'login',
        );

        return isset($page_types[$controller]) ? $page_types[$controller] : 'other';
    }

    /**
     * Get page data for frontend tracker
     */
    public static function getPageData($context)
    {
        if (self::$page_data !== null) {
            return self::$page_data;
        }

        $data = array(
            'page_type' => self::getPageType(),
            'visitor_id' => self::getVisitorId(),
            'session_id' => self::getSessionId(),
            'contact_id' => self::getContactId(),
        );

        // Add customer info if logged in
        if ($context->customer && $context->customer->id) {
            $customer = $context->customer;
            $data['customer'] = array(
                'id' => $customer->id,
                'email' => $customer->email,
                'first_name' => $customer->firstname,
                'last_name' => $customer->lastname,
            );
        }

        // Add page-specific data
        switch ($data['page_type']) {
            case 'product':
                $data['product'] = self::getProductData($context);
                break;

            case 'category':
                $data['category'] = self::getCategoryData($context);
                break;

            case 'cart':
                $data['cart'] = self::getCartData($context);
                break;

            case 'checkout':
                $data['cart'] = self::getCartData($context);
                $data['checkout_step'] = self::getCheckoutStep();
                break;

            case 'search':
                $data['search'] = array(
                    'query' => Tools::getValue('s') ?: Tools::getValue('search_query'),
                );
                break;
        }

        self::$page_data = $data;
        return $data;
    }

    /**
     * Get product data for current page
     */
    private static function getProductData($context)
    {
        $id_product = (int) Tools::getValue('id_product');
        if (!$id_product) {
            return null;
        }

        $product = new Product($id_product, true, $context->language->id);
        if (!Validate::isLoadedObject($product)) {
            return null;
        }

        $cover = Product::getCover($id_product);
        $image_url = '';
        if ($cover) {
            $image = new Image($cover['id_image']);
            $image_url = $context->link->getImageLink(
                $product->link_rewrite,
                $cover['id_image'],
                'home_default'
            );
        }

        $price = Product::getPriceStatic($id_product, true);
        $regular_price = Product::getPriceStatic($id_product, true, null, 6, null, false, false);

        return array(
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->reference,
            'price' => round($price, 2),
            'regular_price' => round($regular_price, 2),
            'currency' => $context->currency->iso_code,
            'image_url' => $image_url,
            'url' => $context->link->getProductLink($product),
            'in_stock' => $product->quantity > 0,
            'categories' => self::getProductCategories($product, $context),
        );
    }

    /**
     * Get product categories
     */
    private static function getProductCategories($product, $context)
    {
        $categories = array();
        $cats = Product::getProductCategoriesFull($product->id, $context->language->id);
        foreach ($cats as $cat) {
            $categories[] = $cat['name'];
        }
        return $categories;
    }

    /**
     * Get category data for current page
     */
    private static function getCategoryData($context)
    {
        $id_category = (int) Tools::getValue('id_category');
        if (!$id_category) {
            return null;
        }

        $category = new Category($id_category, $context->language->id);
        if (!Validate::isLoadedObject($category)) {
            return null;
        }

        return array(
            'id' => $category->id,
            'name' => $category->name,
            'url' => $context->link->getCategoryLink($category),
        );
    }

    /**
     * Get cart data
     */
    private static function getCartData($context)
    {
        $cart = $context->cart;
        if (!$cart || !$cart->id) {
            return null;
        }

        $products = $cart->getProducts();
        $items = array();

        foreach ($products as $product) {
            $items[] = array(
                'product_id' => $product['id_product'],
                'variation_id' => $product['id_product_attribute'] ?: null,
                'name' => $product['name'],
                'sku' => $product['reference'],
                'quantity' => $product['cart_quantity'],
                'price' => round($product['price_wt'], 2),
                'total' => round($product['total_wt'], 2),
            );
        }

        return array(
            'id' => $cart->id,
            'items' => $items,
            'item_count' => count($products),
            'total' => round($cart->getOrderTotal(true), 2),
            'currency' => $context->currency->iso_code,
        );
    }

    /**
     * Detect checkout step
     */
    private static function getCheckoutStep()
    {
        $step = Tools::getValue('step');
        if ($step !== false) {
            return (int) $step;
        }

        // Try to detect from controller
        $controller = Tools::getValue('controller');
        if ($controller === 'order') {
            return 1;
        }
        if ($controller === 'orderopc') {
            return 1;
        }

        return 1;
    }

    /**
     * Format product for event payload (called from API)
     */
    public static function formatProductForEvent($id_product, $id_lang)
    {
        $product = new Product($id_product, true, $id_lang);
        if (!Validate::isLoadedObject($product)) {
            return null;
        }

        $context = Context::getContext();
        $cover = Product::getCover($id_product);
        $image_url = '';
        if ($cover) {
            $image_url = $context->link->getImageLink(
                $product->link_rewrite,
                $cover['id_image'],
                'home_default'
            );
        }

        return array(
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->reference,
            'price' => round(Product::getPriceStatic($id_product, true), 2),
            'currency' => $context->currency->iso_code,
            'image_url' => $image_url,
            'url' => $context->link->getProductLink($product),
        );
    }

    /**
     * Handle add to cart event (server-side)
     */
    public static function onAddToCart($params)
    {
        $id_product = isset($params['id_product']) ? $params['id_product'] : null;
        $quantity = isset($params['quantity']) ? $params['quantity'] : 1;

        if (!$id_product) {
            return;
        }

        $context = Context::getContext();
        $product_data = self::formatProductForEvent($id_product, $context->language->id);

        if (!$product_data) {
            return;
        }

        $event = array(
            'event_id' => self::generateUuid(),
            'event_type' => 'product_added',
            'visitor_id' => self::getVisitorId(),
            'session_id' => self::getSessionId(),
            'contact_id' => self::getContactId(),
            'properties' => array(
                'product_id' => $product_data['id'],
                'product_name' => $product_data['name'],
                'product_sku' => $product_data['sku'],
                'product_price' => $product_data['price'],
                'product_image' => $product_data['image_url'],
                'quantity' => $quantity,
            ),
            'occurred_at' => round(microtime(true) * 1000),
        );

        self::sendEvent($event);
    }

    /**
     * Handle customer login (server-side)
     */
    public static function onCustomerLogin($params)
    {
        $customer = isset($params['customer']) ? $params['customer'] : null;
        if (!$customer) {
            return;
        }

        $event = array(
            'event_id' => self::generateUuid(),
            'event_type' => 'identify',
            'visitor_id' => self::getVisitorId(),
            'session_id' => self::getSessionId(),
            'contact_id' => self::getContactId(),
            'properties' => array(
                'email' => $customer->email,
                'first_name' => $customer->firstname,
                'last_name' => $customer->lastname,
                'customer_id' => $customer->id,
                'source' => 'login',
            ),
            'occurred_at' => round(microtime(true) * 1000),
        );

        self::sendEvent($event);
    }

    /**
     * Send event to Fullmetrix API
     */
    private static function sendEvent($event)
    {
        $secret = Configuration::get('FULLMETRIX_CONNECTION_SECRET');
        $code = Configuration::get('FULLMETRIX_CONNECTION_CODE');

        if (empty($secret) || empty($code)) {
            return;
        }

        $payload = json_encode(array(
            'events' => array($event),
            'visitor_id' => $event['visitor_id'],
            'session_id' => $event['session_id'],
            'contact_id' => $event['contact_id'],
            'plugin_version' => FullmetrixConnector::FULLMETRIX_VERSION,
        ));

        $timestamp = round(microtime(true) * 1000);
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        $url = FullmetrixConnector::FULLMETRIX_API_BASE . '/../webhooks/events';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'X-Fullmetrix-Connection-Code: ' . $code,
            'X-Fullmetrix-Signature: ' . $signature,
            'X-Fullmetrix-Timestamp: ' . $timestamp,
        ));

        // Fire and forget
        curl_exec($ch);
        curl_close($ch);
    }
}
