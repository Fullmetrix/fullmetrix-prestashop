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

require_once _PS_MODULE_DIR_ . 'fullmetrixconnector/classes/FullmetrixLegacyPhp.php';

class FullmetrixConnectorApiModuleFrontController extends ModuleFrontController
{
    private static $maxRequestBodyBytes = 1048576;
    private static $commandNoncesKept = 64;
    private static $cfgCommandNonces = 'FULLMETRIX_COMMAND_NONCES';
    private static $cfgHooksCheckedAt = 'FULLMETRIX_HOOKS_CHECKED_AT';
    private static $hooksCheckEveryS = 3600;

    private static $realtimeTypes = ['health', 'changes', 'entities', 'digest'];

    public $ssl = true;
    public $ajax = true;
    public $content_only = true;
    public $display_header = false;
    public $display_footer = false;
    public $display_column_left = false;
    public $display_column_right = false;

    private $cachedRequestBody;
    private $cachedRequestBodyRead = false;
    private $responseNonce;

    public function init()
    {
        @ini_set('display_errors', '0');
        @ini_set('display_startup_errors', '0');
        if (FullmetrixLegacyPhp::unsupported()) {
            FullmetrixLegacyPhp::refuse();
        }
        require_once _PS_MODULE_DIR_ . 'fullmetrixconnector/classes/FullmetrixSignature.php';

        $type = FullmetrixSignature::queryValue(FullmetrixSignature::rawQueryString(), 'type');
        if (in_array($type, self::$realtimeTypes, true)) {
            $this->handleRealtime($type);
            exit;
        }

        try {
            parent::init();
        } catch (Throwable $e) {
        }

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            $this->sendSecurityHeaders();
        }
    }

    protected function displayMaintenancePage()
    {
    }

    protected function displayRestrictedCountryPage()
    {
    }

    protected function sslRedirection()
    {
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
            $this->sendJsonError('Server error', 500);
        }
    }

    private function handleRealtime($type)
    {
        require_once _PS_MODULE_DIR_ . 'fullmetrixconnector/classes/FullmetrixHealth.php';
        require_once _PS_MODULE_DIR_ . 'fullmetrixconnector/classes/FullmetrixInstallation.php';
        FullmetrixFormatContext::bind($this->context);
        FullmetrixFormatContext::lockCookie();
        @set_time_limit(60);
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        if ($method !== 'POST') {
            $this->respondRealtime(405, ['success' => false, 'error' => 'Method not allowed']);
        }
        $body = $this->readRequestBody();
        if ($body === null) {
            $this->respondRealtime(413, ['success' => false, 'error' => 'Request body too large']);
        }
        $verified = FullmetrixSignature::verifyRequest(
            Configuration::get('FULLMETRIX_CONNECTION_SECRET'),
            Configuration::get('FULLMETRIX_CONNECTION_CODE'),
            $method,
            FullmetrixSignature::rawQueryString(),
            $body
        );
        if (!$verified['ok']) {
            $this->responseNonce = $verified['nonce'];
            $this->respondRealtime(401, ['success' => false, 'error' => 'Invalid signature', 'reason' => $verified['reason'], 'server_time' => time()]);
        }
        $this->responseNonce = $verified['nonce'];
        $ownership = FullmetrixInstallation::decide(Configuration::get('FULLMETRIX_CONNECTION_CODE'));
        if ($ownership === 'conflict') {
            $this->respondRealtime(409, FullmetrixInstallation::conflictResponse());
        }
        if ($ownership === 'busy') {
            $this->respondRealtime(503, ['success' => false, 'error' => 'Busy, retry', 'server_time' => time()]);
        }

        $request = $body === '' ? [] : json_decode($body, true);
        if (!is_array($request)) {
            $this->respondRealtime(400, ['success' => false, 'error' => 'Invalid JSON body']);
        }

        $data = null;
        try {
            switch ($type) {
                case 'health':
                    FullmetrixJournalStore::ensureLazy();
                    $repair = $this->ensureHooksRegistered();
                    $data = FullmetrixHealth::local();
                    $missing = $this->missingHooks();
                    if ($missing !== null) {
                        $data['hooks_missing'] = $missing;
                    }
                    if ($repair !== null && $repair['ran']) {
                        $data['hooks_repair'] = $repair;
                    }
                    break;
                case 'changes':
                    $data = FullmetrixChanges::changes($request);
                    break;
                case 'entities':
                    $data = FullmetrixChanges::entitiesResponse($request);
                    break;
                default:
                    $data = FullmetrixChanges::digest($request);
                    if ($data === null) {
                        $this->respondRealtime(400, ['success' => false, 'error' => 'Unknown digest table']);
                    }
                    break;
            }
        } catch (Throwable $e) {
            $this->respondRealtime(500, ['success' => false, 'error' => 'Server error']);
        }

        $this->respondRealtime(200, $data);
    }

    private function ensureHooksRegistered()
    {
        $module = $this->module;
        if (!is_object($module) || !method_exists($module, 'ensureHooksRegistered')) {
            return null;
        }
        if (time() - (int) Configuration::getGlobalValue(self::$cfgHooksCheckedAt) < self::$hooksCheckEveryS) {
            return null;
        }
        Configuration::updateGlobalValue(self::$cfgHooksCheckedAt, (string) time());
        try {
            $result = $module->ensureHooksRegistered();
        } catch (Exception $e) {
            return null;
        } catch (Throwable $e) {
            return null;
        }
        if (!is_array($result)) {
            return null;
        }

        return [
            'ran' => isset($result['ran']) && $result['ran'] === true,
            'registered' => isset($result['registered']) && is_array($result['registered']) ? array_values($result['registered']) : [],
            'failed' => isset($result['failed']) && is_array($result['failed']) ? array_values($result['failed']) : [],
        ];
    }

    private function missingHooks()
    {
        $module = $this->module;
        if (!is_object($module) || !method_exists($module, 'missingHooks')) {
            return null;
        }
        try {
            $missing = $module->missingHooks();
        } catch (Exception $e) {
            return null;
        } catch (Throwable $e) {
            return null;
        }

        return is_array($missing) ? array_values(array_filter($missing, 'is_string')) : null;
    }

    private function respondRealtime($status, $data)
    {
        $body = FullmetrixChanges::json($data);
        if (!headers_sent()) {
            http_response_code((int) $status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('Content-Length: ' . strlen($body));
            $this->sendVersionHeaders();
            $this->sendSignatureHeaders($body);
            $this->sendSecurityHeaders();
        }
        echo $body;
        exit;
    }

    private function sendVersionHeaders()
    {
        require_once _PS_MODULE_DIR_ . 'fullmetrixconnector/classes/FullmetrixHealth.php';
        header('X-Fullmetrix-Plugin-Version: ' . FullmetrixHealth::moduleVersion());
        header('X-Fullmetrix-Flags-Version: ' . FullmetrixChanges::flagsVersion());
    }

    private function sendSignatureHeaders($body)
    {
        $secret = Configuration::get('FULLMETRIX_CONNECTION_SECRET');
        if (!is_string($this->responseNonce) || $this->responseNonce === '' || empty($secret)) {
            return;
        }
        foreach (FullmetrixSignature::responseHeaders($secret, $this->responseNonce, $body) as $name => $value) {
            header($name . ': ' . $value);
        }
    }

    private function verifyRequest()
    {
        if (FullmetrixSignature::isV2Request()) {
            return $this->verifyV2Request();
        }
        if (!$this->v1Allowed()) {
            return ['error' => 'Signature v2 required', 'status' => 401];
        }

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

    private function v1Allowed()
    {
        $raw = Configuration::getGlobalValue('FULLMETRIX_FLAGS');
        $flags = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return !is_array($flags) || !array_key_exists('v1', $flags) || $flags['v1'] !== false;
    }

    private function verifyV2Request()
    {
        $body = $this->readRequestBody();
        if ($body === null) {
            return ['error' => 'Request body too large', 'status' => 413];
        }
        $verified = FullmetrixSignature::verifyRequest(
            Configuration::get('FULLMETRIX_CONNECTION_SECRET'),
            Configuration::get('FULLMETRIX_CONNECTION_CODE'),
            isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET',
            FullmetrixSignature::rawQueryString(),
            $body
        );
        $this->responseNonce = $verified['nonce'];
        if (!$verified['ok']) {
            return ['error' => 'Invalid or expired signature', 'status' => 401];
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

        if (!$this->rememberCommandNonce()) {
            $this->sendJsonError('Replayed request', 409);

            return;
        }

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
            case 'flags.set':
                $this->commandFlagsSet($payload);
                break;
            default:
                $this->sendJsonError('Unknown action: ' . $action, 400);
        }
    }

    private function rememberCommandNonce()
    {
        if (!is_string($this->responseNonce) || $this->responseNonce === '') {
            return true;
        }
        $raw = Configuration::getGlobalValue(self::$cfgCommandNonces);
        $seen = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($seen)) {
            $seen = [];
        }
        if (in_array($this->responseNonce, $seen, true)) {
            return false;
        }
        $seen[] = $this->responseNonce;
        Configuration::updateGlobalValue(
            self::$cfgCommandNonces,
            json_encode(array_values(array_slice($seen, -self::$commandNoncesKept)))
        );

        return true;
    }

    private function commandFlagsSet($payload)
    {
        require_once _PS_MODULE_DIR_ . 'fullmetrixconnector/classes/FullmetrixChanges.php';
        require_once _PS_MODULE_DIR_ . 'fullmetrixconnector/classes/FullmetrixInstallation.php';
        if (!FullmetrixSignature::isV2Request()) {
            $this->sendJsonError('Signature v2 required', 401);

            return;
        }
        $ownership = FullmetrixInstallation::decide(Configuration::get('FULLMETRIX_CONNECTION_CODE'));
        if ($ownership === 'busy') {
            $this->sendJsonError('Busy, retry', 503);

            return;
        }
        if ($ownership === 'conflict') {
            $this->sendJson(FullmetrixInstallation::conflictResponse(), 409);

            return;
        }
        if (!is_array($payload) || !isset($payload['v']) || !isset($payload['values']) || !is_array($payload['values'])) {
            $this->sendJsonError('Invalid flags payload', 400);

            return;
        }
        $version = FullmetrixChanges::setFlags($payload['v'], $payload['values']);

        $this->sendJson([
            'success' => true,
            'data' => ['v' => $version],
        ]);
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
        if (isset($payload['discountType'])) {
            $cartRule->reduction_percent = 0;
            $cartRule->reduction_amount = 0;
            $cartRule->free_shipping = false;
            $cartRule->reduction_tax = true;

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
            $cartRule->date_from = date('Y-m-d H:i:s');
        }

        if (array_key_exists('expiresAt', $payload)) {
            $cartRule->date_to = $payload['expiresAt'] ? date('Y-m-d H:i:s', strtotime($payload['expiresAt'])) : '0000-00-00 00:00:00';
        } elseif (!$cartRule->id) {
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
        if (FullmetrixSignature::isV2Request()) {
            return $this->verifyV2Request();
        }
        if (!$this->v1Allowed()) {
            return ['error' => 'Signature v2 required', 'status' => 401];
        }

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
        $fromId = max(0, (int) Tools::getValue('from_id', 0));
        $page = max(1, (int) Tools::getValue('page', 1));
        $perPage = min(500, max(1, (int) Tools::getValue('per_page', 100)));

        if ($type === 'command') {
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
            $this->handleStreamEntity($entity, $syncType, $since, $fromId);

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

    private function handleStreamEntity($entity, $syncType, $since, $fromId = 0)
    {
        require_once _PS_MODULE_DIR_ . 'fullmetrixconnector/classes/FullmetrixStreamExporter.php';

        $exporter = new FullmetrixStreamExporter(
            (int) $this->context->shop->id ?: 1,
            $this->context->link
        );
        $exporter->streamEntity($entity, $syncType, $since, $fromId);
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
        $module = $this->module;

        $this->sendJson([
            'success' => true,
            'settings' => $module instanceof FullmetrixConnector ? $module->getStoreSettings() : [],
        ]);
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

        if (isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > self::$maxRequestBodyBytes) {
            $this->cachedRequestBody = null;

            return null;
        }

        $stream = @fopen('php://input', 'rb');
        if ($stream === false) {
            $this->cachedRequestBody = '';

            return '';
        }
        $body = @stream_get_contents($stream, self::$maxRequestBodyBytes + 1);
        @fclose($stream);
        if (!is_string($body)) {
            $body = '';
        }
        if (strlen($body) > self::$maxRequestBodyBytes) {
            $this->cachedRequestBody = null;

            return null;
        }
        $this->cachedRequestBody = $body;

        return $body;
    }

    private function getHeader($name)
    {
        return FullmetrixSignature::header($name);
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

    private function sendJson($data, $statusCode = 200)
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code((int) $statusCode);
            $this->sendSecurityHeaders();
            $this->sendV2ResponseHeaders((string) $body);
        }
        echo $body;
        exit;
    }

    private function sendJsonError($message, $statusCode = 400)
    {
        $body = json_encode([
            'success' => false,
            'error' => $message,
        ], JSON_UNESCAPED_UNICODE);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($statusCode);
            $this->sendSecurityHeaders();
            $this->sendV2ResponseHeaders((string) $body);
        }
        echo $body;
        exit;
    }

    private function sendV2ResponseHeaders($body)
    {
        if (!is_string($this->responseNonce) || $this->responseNonce === '') {
            return;
        }
        require_once _PS_MODULE_DIR_ . 'fullmetrixconnector/classes/FullmetrixChanges.php';
        header('Cache-Control: no-store');
        $this->sendVersionHeaders();
        $this->sendSignatureHeaders($body);
    }
}
