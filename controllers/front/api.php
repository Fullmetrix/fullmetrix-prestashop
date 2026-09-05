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

class FullmetrixConnectorApiModuleFrontController extends ModuleFrontController
{
    public const MAX_REQUEST_BODY_BYTES = 1048576;

    public $ssl = true;
    public $ajax = true;
    public $content_only = true;
    public $display_header = false;
    public $display_footer = false;
    public $display_column_left = false;
    public $display_column_right = false;

    private $cachedRequestBody;
    private $cachedRequestBodyRead = false;

    public function init()
    {
        @ini_set('display_errors', '0');
        @ini_set('display_startup_errors', '0');

        try {
            parent::init();
        } catch (Throwable $e) {
            /* intentionally empty */
        }

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            $this->sendSecurityHeaders();
        }
    }

    public function initContent()
    {
    }

    public function postProcess()
    {
    }

    public function display()
    {
        $this->displayAjax();

        return true;
    }

    public function displayAjax()
    {
        try {
            $isCommand = Tools::getValue('type', '') === 'command';
            if (!$isCommand) {
                $verifyResult = $this->verifyRequest();
                if ($verifyResult !== true) {
                    $this->sendJsonError($verifyResult['error'], $verifyResult['status']);

                    return;
                }
            }

            $this->handleExport();
        } catch (Throwable $e) {
            require_once _PS_MODULE_DIR_ . 'fullmetrixconnector/classes/FullmetrixLogger.php';
            FullmetrixLogger::logException('api_displayAjax', $e);
            $this->sendJsonError('Server error', 500);
        }
    }

    private function verifyRequest()
    {
        $secret = Configuration::get('FULLMETRIX_CONNECTION_SECRET');

        if (empty($secret)) {
            return [
                'error' => 'Plugin not configured',
                'status' => 401,
            ];
        }

        $signature = $this->getHeader('X-Fullmetrix-Signature');
        $timestamp = $this->getHeader('X-Fullmetrix-Timestamp');
        $code = $this->getHeader('X-Fullmetrix-Connection-Code');

        if (empty($signature) || empty($timestamp) || empty($code)) {
            return [
                'error' => 'Missing authentication headers',
                'status' => 401,
            ];
        }

        $storedCode = Configuration::get('FULLMETRIX_CONNECTION_CODE');
        if ($code !== $storedCode) {
            return [
                'error' => 'Invalid connection code',
                'status' => 401,
            ];
        }

        require_once _PS_MODULE_DIR_ . 'fullmetrixconnector/classes/FullmetrixSecurity.php';

        $isValid = FullmetrixSecurity::verifySignature($secret, '', $signature, (int) $timestamp);

        if (!$isValid) {
            return [
                'error' => 'Invalid or expired signature',
                'status' => 401,
            ];
        }

        return true;
    }

    private function handleCommand()
    {
        $rawBody = $this->readRequestBody();
        if ($rawBody === null) {
            $this->sendJsonError('Request body too large', 413);

            return;
        }
        $body = json_decode($rawBody, true);

        if (!is_array($body) || empty($body['action'])) {
            $this->sendJsonError('Missing action', 400);

            return;
        }

        $action = $body['action'];
        $payload = isset($body['payload']) ? $body['payload'] : [];

        switch ($action) {
            case 'coupon.create':
                $this->commandCouponCreate($payload);
                break;
            case 'coupon.update':
                $this->commandCouponUpdate($payload);
                break;
            case 'coupon.delete':
                $this->commandCouponDelete($payload);
                break;
            default:
                $this->sendJsonError('Unknown action: ' . $action, 400);
        }
    }

    private function commandCouponCreate($payload)
    {
        if (empty($payload['code'])) {
            $this->sendJsonError('Missing coupon code', 400);

            return;
        }

        $cartRule = new CartRule();
        $cartRule->code = pSQL($payload['code']);
        $cartRule->active = true;

        // Name is multilang in PrestaShop
        $languages = Language::getLanguages(false);
        $name = !empty($payload['description']) ? $payload['description'] : $payload['code'];
        foreach ($languages as $lang) {
            $cartRule->name[$lang['id_lang']] = $name;
        }

        $this->applyCartRuleFields($cartRule, $payload);

        if (!$cartRule->add()) {
            $this->sendJsonError('Failed to create cart rule', 500);

            return;
        }

        $this->sendJson([
            'success' => true,
            'data' => [
                'id' => (int) $cartRule->id,
                'code' => $cartRule->code,
            ],
        ]);
    }

    private function commandCouponUpdate($payload)
    {
        if (empty($payload['id'])) {
            $this->sendJsonError('Missing coupon id', 400);

            return;
        }

        $cartRule = new CartRule((int) $payload['id']);
        if (!Validate::isLoadedObject($cartRule)) {
            $this->sendJsonError('Cart rule not found', 404);

            return;
        }

        if (isset($payload['code'])) {
            $cartRule->code = pSQL($payload['code']);
        }

        if (isset($payload['description'])) {
            $languages = Language::getLanguages(false);
            foreach ($languages as $lang) {
                $cartRule->name[$lang['id_lang']] = $payload['description'];
            }
        }

        $this->applyCartRuleFields($cartRule, $payload);

        if (!$cartRule->update()) {
            $this->sendJsonError('Failed to update cart rule', 500);

            return;
        }

        $this->sendJson([
            'success' => true,
            'data' => [
                'id' => (int) $cartRule->id,
                'code' => $cartRule->code,
            ],
        ]);
    }

    private function commandCouponDelete($payload)
    {
        if (empty($payload['id'])) {
            $this->sendJsonError('Missing coupon id', 400);

            return;
        }

        $cartRule = new CartRule((int) $payload['id']);
        if (!Validate::isLoadedObject($cartRule)) {
            $this->sendJsonError('Cart rule not found', 404);

            return;
        }

        if (!$cartRule->delete()) {
            $this->sendJsonError('Failed to delete cart rule', 500);

            return;
        }

        $this->sendJson([
            'success' => true,
            'data' => ['id' => (int) $payload['id']],
        ]);
    }

    private function applyCartRuleFields($cartRule, $payload)
    {
        // Discount type + amount
        if (isset($payload['discountType'])) {
            $cartRule->reduction_percent = 0;
            $cartRule->reduction_amount = 0;
            $cartRule->free_shipping = false;
            $cartRule->reduction_tax = true; // apply on tax-included price

            switch ($payload['discountType']) {
                case 'percentage':
                    $amount = isset($payload['amount']) ? (float) $payload['amount'] : 0;
                    $cartRule->reduction_percent = min(100, max(0, $amount));
                    break;
                case 'fixed_cart':
                case 'fixed_product':
                    $cartRule->reduction_amount = isset($payload['amount']) ? (float) $payload['amount'] : 0;
                    break;
                case 'free_shipping':
                    $cartRule->free_shipping = true;
                    break;
            }
        } elseif (isset($payload['amount'])) {
            // Update amount only without changing type
            if ($cartRule->reduction_percent > 0) {
                $cartRule->reduction_percent = min(100, max(0, (float) $payload['amount']));
            } else {
                $cartRule->reduction_amount = (float) $payload['amount'];
            }
        }

        if (isset($payload['freeShipping'])) {
            $cartRule->free_shipping = (bool) $payload['freeShipping'];
        }

        if (array_key_exists('usageLimit', $payload)) {
            $cartRule->quantity = $payload['usageLimit'] === null ? 0 : (int) $payload['usageLimit'];
        }

        if (array_key_exists('usageLimitPerUser', $payload)) {
            $cartRule->quantity_per_user = $payload['usageLimitPerUser'] === null ? 0 : (int) $payload['usageLimitPerUser'];
        }

        if (array_key_exists('minimumAmount', $payload)) {
            $cartRule->minimum_amount = $payload['minimumAmount'] === null ? 0 : (float) $payload['minimumAmount'];
            $cartRule->minimum_amount_tax = true;
        }

        if (array_key_exists('startsAt', $payload)) {
            $cartRule->date_from = $payload['startsAt'] ? date('Y-m-d H:i:s', strtotime($payload['startsAt'])) : date('Y-m-d H:i:s');
        } elseif (!$cartRule->id) {
            // New cart rule — default to now
            $cartRule->date_from = date('Y-m-d H:i:s');
        }

        if (array_key_exists('expiresAt', $payload)) {
            $cartRule->date_to = $payload['expiresAt'] ? date('Y-m-d H:i:s', strtotime($payload['expiresAt'])) : '0000-00-00 00:00:00';
        } elseif (!$cartRule->id) {
            // New cart rule — default to 1 year from now
            $cartRule->date_to = date('Y-m-d H:i:s', strtotime('+1 year'));
        }

        if (!$cartRule->id) {
            $cartRule->id_customer = 0;
        }

        if (isset($payload['emailRestrictions']) && is_array($payload['emailRestrictions']) && !empty($payload['emailRestrictions'])) {
            $email = trim($payload['emailRestrictions'][0]);
            if (!empty($email)) {
                $idCustomer = (int) Db::getInstance()->getValue(
                    'SELECT id_customer FROM ' . _DB_PREFIX_ . 'customer WHERE email = "' . pSQL($email) . '" AND deleted = 0 LIMIT 1'
                );
                if ($idCustomer > 0) {
                    $cartRule->id_customer = $idCustomer;
                }
            }
        }

        if (isset($payload['excludeSaleItems'])) {
            $cartRule->reduction_exclude_special = (bool) $payload['excludeSaleItems'];
        }

        if (isset($payload['productIds']) && is_array($payload['productIds']) && !empty($payload['productIds'])) {
            $firstId = (int) $payload['productIds'][0];
            if ($firstId > 0) {
                $cartRule->gift_product = $firstId;
            }
        }

        if (array_key_exists('productAttributeId', $payload)) {
            $attrId = $payload['productAttributeId'] === null ? 0 : (int) $payload['productAttributeId'];
            $cartRule->gift_product_attribute = $attrId > 0 ? $attrId : 0;
        }
    }

    private function verifyCommandRequest()
    {
        $secret = Configuration::get('FULLMETRIX_CONNECTION_SECRET');

        if (empty($secret)) {
            return ['error' => 'Plugin not configured', 'status' => 401];
        }

        $signature = $this->getHeader('X-Fullmetrix-Signature');
        $timestamp = $this->getHeader('X-Fullmetrix-Timestamp');
        $code = $this->getHeader('X-Fullmetrix-Connection-Code');

        if (empty($signature) || empty($timestamp) || empty($code)) {
            return ['error' => 'Missing authentication headers', 'status' => 401];
        }

        $storedCode = Configuration::get('FULLMETRIX_CONNECTION_CODE');
        if ($code !== $storedCode) {
            return ['error' => 'Invalid connection code', 'status' => 401];
        }

        require_once _PS_MODULE_DIR_ . 'fullmetrixconnector/classes/FullmetrixSecurity.php';

        $body = $this->readRequestBody();
        if ($body === null) {
            return ['error' => 'Request body too large', 'status' => 413];
        }
        $isValid = FullmetrixSecurity::verifySignature($secret, $body, $signature, (int) $timestamp);

        if (!$isValid) {
            return ['error' => 'Invalid or expired signature', 'status' => 401];
        }

        return true;
    }

    private function handleExport()
    {
        $type = Tools::getValue('type', 'orders');
        $syncType = Tools::getValue('sync_type', 'full');
        $since = Tools::getValue('since', '');
        $page = max(1, (int) Tools::getValue('page', 1));
        $perPage = min(500, max(1, (int) Tools::getValue('per_page', 100)));

        if ($type === 'command') {
            // Command requests have body-signed HMAC — re-verify
            $cmdVerify = $this->verifyCommandRequest();
            if ($cmdVerify !== true) {
                $this->sendJsonError($cmdVerify['error'], $cmdVerify['status']);

                return;
            }
            $this->handleCommand();

            return;
        }

        if ($type === 'stream' || $type === 'stream_orders') {
            $this->handleStream($type, $syncType, $since);

            return;
        }

        if ($type === 'stream_entity') {
            $entity = Tools::getValue('entity', '');
            $validEntities = ['orders', 'customers', 'products', 'categories', 'coupons', 'refunds', 'carts'];
            if (!in_array($entity, $validEntities, true)) {
                $this->sendJsonError('Invalid entity', 400);

                return;
            }
            $this->handleStreamEntity($entity, $syncType, $since);

            return;
        }

        if ($type === 'counts') {
            $this->handleCounts();

            return;
        }

        if ($type === 'settings') {
            $this->handleSettings();

            return;
        }

        if ($type === 'updated') {
            $this->handleUpdated();

            return;
        }

        $this->trackSyncStart($type, $syncType);

        require_once _PS_MODULE_DIR_ . 'fullmetrixconnector/classes/FullmetrixFastExporter.php';
        $exporter = new FullmetrixFastExporter(
            (int) $this->context->shop->id ?: 1,
            $this->context->link
        );

        switch ($type) {
            case 'customers':
                $result = $exporter->exportCustomersFast($page, $perPage);
                break;
            case 'products':
                $result = $exporter->exportProductsFast($page, $perPage);
                break;
            case 'categories':
                $result = $exporter->exportCategoriesFast($page, $perPage);
                break;
            case 'coupons':
                $result = $exporter->exportCouponsFast($page, $perPage);
                break;
            default:
                $result = $exporter->exportOrdersFast($page, $perPage, $since ?: null);
                break;
        }

        $this->trackSyncComplete($type, $result);

        $this->sendJson($result);
    }

    private function handleStream($type, $syncType, $since)
    {
        $this->trackSyncStart($type, $syncType);

        require_once _PS_MODULE_DIR_ . 'fullmetrixconnector/classes/FullmetrixStreamExporter.php';

        $exporter = new FullmetrixStreamExporter(
            (int) $this->context->shop->id ?: 1,
            $this->context->link
        );

        if ($type === 'stream_orders') {
            $exporter->streamOrdersOnly($syncType, $since);
        } else {
            $exporter->streamAll($syncType, $since);
        }
    }

    private function handleStreamEntity($entity, $syncType, $since)
    {
        $this->trackSyncStart($entity, $syncType);

        require_once _PS_MODULE_DIR_ . 'fullmetrixconnector/classes/FullmetrixStreamExporter.php';

        $exporter = new FullmetrixStreamExporter(
            (int) $this->context->shop->id ?: 1,
            $this->context->link
        );
        $exporter->streamEntity($entity, $syncType, $since);
    }

    private function handleUpdated()
    {
        require_once _PS_MODULE_DIR_ . 'fullmetrixconnector/classes/FullmetrixFastExporter.php';

        $type = Tools::getValue('entity', 'orders');
        $days = max(1, (int) Tools::getValue('days', 30));
        $hours = max(0, (int) Tools::getValue('hours', 0));
        $limit = min(500000, max(1, (int) Tools::getValue('limit', 200000)));
        $offset = max(0, (int) Tools::getValue('offset', 0));

        $exporter = new FullmetrixFastExporter(
            (int) $this->context->shop->id ?: 1,
            $this->context->link
        );
        $items = $exporter->getUpdatedIds($type, $days, $hours, $limit, $offset);

        $this->sendJson([
            'success' => true,
            'type' => $type,
            'from_days' => $days,
            'from_hours' => $hours,
            'count' => count($items),
            'items' => $items,
        ]);
    }

    private function handleCounts()
    {
        $db = Db::getInstance();
        $prefix = _DB_PREFIX_;

        $orders = (int) $db->getValue("SELECT COUNT(*) FROM `{$prefix}orders` WHERE `id_order` > 0");
        $customers = (int) $db->getValue("SELECT COUNT(*) FROM `{$prefix}customer` WHERE `deleted` = 0 AND `id_customer` > 0");
        $products = (int) $db->getValue("SELECT COUNT(*) FROM `{$prefix}product` WHERE `id_product` > 0");
        $categories = (int) $db->getValue("SELECT COUNT(*) FROM `{$prefix}category` WHERE `id_category` > 0");
        $coupons = (int) $db->getValue("SELECT COUNT(*) FROM `{$prefix}cart_rule` WHERE `id_cart_rule` > 0");
        $carts = (int) $db->getValue("SELECT COUNT(*) FROM `{$prefix}cart` WHERE `id_cart` > 0");
        $refunds = (int) $db->getValue("SELECT COUNT(*) FROM `{$prefix}order_slip` WHERE `id_order_slip` > 0");

        $this->sendJson([
            'success' => true,
            'counts' => [
                'orders' => $orders,
                'customers' => $customers,
                'products' => $products,
                'categories' => $categories,
                'coupons' => $coupons,
                'carts' => $carts,
                'refunds' => $refunds,
            ],
        ]);
    }

    private function handleSettings()
    {
        $this->sendJson([
            'success' => true,
            'settings' => $this->getStoreSettings(),
        ]);
    }

    private function getStoreSettings()
    {
        // Currency
        $currencyId = (int) Configuration::get('PS_CURRENCY_DEFAULT');
        $currency = new Currency($currencyId);
        $isoCode = $currency->iso_code ?: 'EUR';

        // Timezone
        $timezone = Configuration::get('PS_TIMEZONE') ?: 'Europe/Paris';

        // Locale
        $langId = (int) Configuration::get('PS_LANG_DEFAULT');
        $lang = new Language($langId);
        $locale = $lang->locale ?: $lang->language_code ?: 'fr-FR';

        // Currency format
        $format = (int) $currency->format;
        // PS format: 1 = X.XX€, 2 = X,XX€, 3 = €X.XX, 4 = €X,XX, 5 = X'XX CHF
        $position = in_array($format, [3, 4], true) ? 'left' : 'right';

        // PS doesn't expose thousand/decimal separators directly per-currency
        // Use CLDR-based detection from locale
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

    private function trackSyncStart($type, $syncType)
    {
        $count = (int) Configuration::get('FULLMETRIX_EXPORT_COUNT');
        Configuration::updateValue('FULLMETRIX_EXPORT_COUNT', $count + 1);

        Configuration::updateValue('FULLMETRIX_SYNC_IN_PROGRESS', json_encode([
            'type' => $type,
            'sync_type' => $syncType,
            'started_at' => time(),
        ]));
    }

    private function trackSyncComplete($type, $result)
    {
        if (!is_array($result) || empty($result['success'])) {
            return;
        }

        $entityLabels = [
            'orders' => 'Orders',
            'products' => 'Products',
            'categories' => 'Categories',
            'customers' => 'Customers',
            'coupons' => 'Coupons',
        ];

        $stats = [
            'completed_at' => time(),
            'type' => $type === 'bulk' ? 'bulk' : 'paginated',
            'entities' => [],
        ];

        if ($type === 'bulk') {
            if (isset($result['meta']['counts']) && is_array($result['meta']['counts'])) {
                foreach ($result['meta']['counts'] as $key => $count) {
                    if ($count > 0 && isset($entityLabels[$key])) {
                        $stats['entities'][$entityLabels[$key]] = (int) $count;
                    }
                }
            }
            Configuration::updateValue('FULLMETRIX_SYNC_IN_PROGRESS', '');
        } else {
            $existing = json_decode(Configuration::get('FULLMETRIX_LAST_SYNC'), true);
            if (is_array($existing) && isset($existing['entities'])) {
                $stats['entities'] = $existing['entities'];
            }

            $total = 0;
            if (isset($result['meta']['total'])) {
                $total = (int) $result['meta']['total'];
            } elseif (isset($result['meta']['totalOrders'])) {
                $total = (int) $result['meta']['totalOrders'];
            }

            if ($total > 0 && isset($entityLabels[$type])) {
                $stats['entities'][$entityLabels[$type]] = $total;
            }
        }

        Configuration::updateValue('FULLMETRIX_LAST_SYNC', json_encode($stats));
    }

    private function readRequestBody()
    {
        if ($this->cachedRequestBodyRead) {
            return $this->cachedRequestBody;
        }
        $this->cachedRequestBodyRead = true;

        if (isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > self::MAX_REQUEST_BODY_BYTES) {
            $this->cachedRequestBody = null;

            return null;
        }

        $stream = @fopen('php://input', 'rb');
        if ($stream === false) {
            $this->cachedRequestBody = '';

            return '';
        }
        $body = @stream_get_contents($stream, self::MAX_REQUEST_BODY_BYTES + 1);
        @fclose($stream);
        if (!is_string($body)) {
            $body = '';
        }
        if (strlen($body) > self::MAX_REQUEST_BODY_BYTES) {
            $this->cachedRequestBody = null;

            return null;
        }
        $this->cachedRequestBody = $body;

        return $body;
    }

    private function getHeader($name)
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        if (!empty($_SERVER[$serverKey])) {
            return $_SERVER[$serverKey];
        }

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $key => $value) {
                    if (strcasecmp($key, $name) === 0) {
                        return $value;
                    }
                }
            }
        }

        return null;
    }

    private function sendSecurityHeaders()
    {
        if (headers_sent()) {
            return;
        }
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }

    private function sendJson($data)
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            $this->sendSecurityHeaders();
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    }

    private function sendJsonError($message, $statusCode = 400)
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($statusCode);
            $this->sendSecurityHeaders();
        }
        echo json_encode([
            'success' => false,
            'error' => $message,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
