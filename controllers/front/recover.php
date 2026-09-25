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

class FullmetrixConnectorRecoverModuleFrontController extends ModuleFrontController
{
    private static $cartParam = 'fm_cart_id';
    private static $cartMaxItems = 25;
    private static $cartLinkTtl = 2592000;
    private static $downTtl = 60;
    private static $failuresToOpen = 2;
    private static $defaultApiBase = 'https://fullmetrix.com/api/plugin';

    private static $droppedParams = ['fm_cart_id', 'fm_cart', 'fm_cart_sig', 'fc', 'module', 'controller'];

    public function init()
    {
        parent::init();
        if (FullmetrixLegacyPhp::unsupported()) {
            Tools::redirect($this->context->link->getPageLink('index', true));
        }
        require_once _PS_MODULE_DIR_ . 'fullmetrixconnector/classes/FullmetrixSecurity.php';
        require_once _PS_MODULE_DIR_ . 'fullmetrixconnector/classes/FullmetrixChanges.php';
        require_once _PS_MODULE_DIR_ . 'fullmetrixconnector/classes/FullmetrixHealth.php';

        $target = 'cart';
        try {
            $target = $this->rebuild();
        } catch (Exception $e) {
            $target = 'cart';
        } catch (Throwable $e) {
            $target = 'cart';
        }
        $this->redirectTo($target);
    }

    private static function isActive()
    {
        return (bool) Configuration::get('FULLMETRIX_REGISTERED')
            && Configuration::get('FULLMETRIX_CONNECTION_CODE') != ''
            && Configuration::get('FULLMETRIX_CONNECTION_SECRET') != '';
    }

    private function rebuild()
    {
        if (!self::isActive() || !FullmetrixChanges::flagEnabled('module') || !FullmetrixChanges::flagEnabled('recover')) {
            return 'cart';
        }

        $linkId = Tools::getValue(self::$cartParam);
        $payload = Tools::getValue('fm_cart');
        $signature = Tools::getValue('fm_cart_sig');
        if (is_string($linkId) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $linkId)) {
            $data = self::resolveCartLink($linkId);
        } elseif (is_string($payload) && strlen($payload) <= 4096 && preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $payload)
            && is_string($signature) && preg_match('/^[a-f0-9]{16,128}$/', $signature)) {
            $data = self::decodeCartPayload($payload, $signature);
        } else {
            return 'cart';
        }

        if (!is_array($data) || empty($data['items']) || !is_array($data['items'])) {
            return 'cart';
        }

        $context = $this->context;
        if (!$context->language || !$context->currency) {
            return 'cart';
        }

        $cart = $context->cart;
        if (!$cart || !Validate::isLoadedObject($cart)) {
            $cart = new Cart();
            $cart->id_lang = (int) $context->language->id;
            $cart->id_currency = (int) $context->currency->id;
            if ($context->cookie && (int) $context->cookie->id_guest > 0) {
                $cart->id_guest = (int) $context->cookie->id_guest;
            }
            if ($context->customer && $context->customer->id) {
                $cart->id_customer = (int) $context->customer->id;
                $addressId = (int) Address::getFirstCustomerAddressId($context->customer->id);
                $cart->id_address_delivery = $addressId > 0 ? $addressId : 0;
            }
            if (!$cart->add()) {
                return 'cart';
            }
            $context->cart = $cart;
            if ($context->cookie) {
                $context->cookie->__set('id_cart', (int) $cart->id);
            }
        }

        $existingKeys = [];
        $existingProducts = $cart->getProducts();
        if (is_array($existingProducts)) {
            foreach ($existingProducts as $product) {
                $existingKeys[(int) $product['id_product'] . '_' . (int) $product['id_product_attribute']] = true;
            }
        }

        foreach (array_slice($data['items'], 0, self::$cartMaxItems) as $item) {
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
            } catch (Exception $e) {
                continue;
            } catch (Throwable $e) {
                continue;
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
                } catch (Exception $e) {
                    continue;
                } catch (Throwable $e) {
                    continue;
                }
            }
        }

        return isset($data['target']) && $data['target'] === 'checkout' ? 'checkout' : 'cart';
    }

    private function redirectTo($target)
    {
        $params = [];
        foreach ($_GET as $key => $value) {
            if (!is_string($key) || in_array($key, self::$droppedParams, true) || !is_scalar($value)) {
                continue;
            }
            $params[$key] = preg_replace('/[\x00-\x1F\x7F]/', '', (string) $value);
        }

        $link = $this->context->link ? $this->context->link : new Link();
        if (FullmetrixCartSnapshot::hasStandaloneCartPage()) {
            $url = $target === 'checkout'
                ? $link->getPageLink('order', true, null, $params)
                : $link->getPageLink('cart', true, null, array_merge($params, ['action' => 'show']));
        } else {
            $url = $link->getPageLink(FullmetrixCartSnapshot::legacyCartController(), true, null, $params);
        }
        if (!is_string($url) || $url === '') {
            $url = defined('__PS_BASE_URI__') ? __PS_BASE_URI__ : '/';
        }
        Tools::redirect($url);
        exit;
    }

    private static function decodeCartPayload($payload, $signature)
    {
        $secret = Configuration::get('FULLMETRIX_CONNECTION_SECRET');
        if (empty($secret) || !hash_equals(hash_hmac('sha256', $payload, $secret), $signature)) {
            return null;
        }
        $json = base64_decode(strtr($payload, '-_', '+/'));
        if (!is_string($json) || $json === '') {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['items']) || !is_array($data['items'])) {
            return null;
        }
        if (!empty($data['ts']) && (time() - (int) $data['ts']) > self::$cartLinkTtl) {
            return null;
        }

        return $data;
    }

    private static function breakerState()
    {
        $raw = Configuration::getGlobalValue(FullmetrixJournal::key('recover_down'));
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return [
            'fails' => is_array($decoded) && isset($decoded['fails']) ? (int) $decoded['fails'] : 0,
            'until' => is_array($decoded) && isset($decoded['until']) ? (int) $decoded['until'] : 0,
        ];
    }

    private static function recordFailure()
    {
        $state = self::breakerState();
        $fails = $state['fails'] + 1;
        $until = $state['until'];
        if ($fails >= self::$failuresToOpen) {
            $fails = 0;
            $until = time() + self::$downTtl;
        }
        Configuration::updateGlobalValue(FullmetrixJournal::key('recover_down'), json_encode(['fails' => $fails, 'until' => $until]), true);
    }

    private static function recordSuccess()
    {
        $state = self::breakerState();
        if ($state['fails'] > 0) {
            Configuration::updateGlobalValue(FullmetrixJournal::key('recover_down'), json_encode(['fails' => 0, 'until' => $state['until']]), true);
        }
    }

    private static function resolveCartLink($linkId)
    {
        $secret = Configuration::get('FULLMETRIX_CONNECTION_SECRET');
        $code = Configuration::get('FULLMETRIX_CONNECTION_CODE');
        if (empty($secret) || empty($code) || !function_exists('curl_init')) {
            return null;
        }
        if (self::breakerState()['until'] > time()) {
            return null;
        }

        $apiBase = Configuration::get('FULLMETRIX_API_BASE');
        if (empty($apiBase)) {
            $apiBase = self::$defaultApiBase;
        }
        $body = json_encode(['id' => $linkId]);
        if (!is_string($body)) {
            return null;
        }
        $headers = FullmetrixSecurity::createSignedHeaders($secret, $code, $body);

        $ch = curl_init(rtrim($apiBase, '/') . '/cart/resolve');
        if (!$ch) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_CONNECTTIMEOUT_MS => 300,
            CURLOPT_TIMEOUT_MS => 1500,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Fullmetrix-Connection-Code: ' . $headers['X-Fullmetrix-Connection-Code'],
                'X-Fullmetrix-Signature: ' . $headers['X-Fullmetrix-Signature'],
                'X-Fullmetrix-Timestamp: ' . $headers['X-Fullmetrix-Timestamp'],
                'X-Fullmetrix-Plugin-Version: ' . FullmetrixHealth::moduleVersion(),
            ],
        ]);
        $response = @curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 0 || $httpCode >= 500) {
            self::recordFailure();

            return null;
        }
        self::recordSuccess();

        if ($httpCode !== 200 || !is_string($response) || $response === '') {
            return null;
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded) || empty($decoded['items']) || !is_array($decoded['items'])) {
            return null;
        }

        $items = [];
        foreach ($decoded['items'] as $item) {
            $items[] = [
                'id' => isset($item['id']) ? $item['id'] : 0,
                'v' => isset($item['variation']) ? $item['variation'] : 0,
                'q' => isset($item['quantity']) ? $item['quantity'] : 1,
            ];
        }

        return [
            'items' => $items,
            'c' => isset($decoded['coupons']) && is_array($decoded['coupons']) ? $decoded['coupons'] : [],
            'target' => isset($decoded['target']) ? $decoded['target'] : 'cart',
        ];
    }

    public function initContent()
    {
    }

    public function postProcess()
    {
    }

    public function display()
    {
    }
}
