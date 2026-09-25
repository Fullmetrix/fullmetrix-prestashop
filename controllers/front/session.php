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

class FullmetrixConnectorSessionModuleFrontController extends ModuleFrontController
{
    private static $maxBodyBytes = 1024;
    private static $visitorPattern = '/^[A-Za-z0-9._:-]{1,200}$/';

    public $ajax = true;
    public $content_only = true;
    public $display_header = false;
    public $display_footer = false;

    public function init()
    {
        @ini_set('display_errors', '0');
        if (FullmetrixLegacyPhp::unsupported()) {
            FullmetrixLegacyPhp::refuse();
        }
        require_once _PS_MODULE_DIR_ . 'fullmetrixconnector/classes/FullmetrixSignature.php';
        require_once _PS_MODULE_DIR_ . 'fullmetrixconnector/classes/FullmetrixChanges.php';
        FullmetrixFormatContext::bind($this->context);
        FullmetrixFormatContext::lockCookie();

        try {
            $this->handle();
        } catch (Exception $e) {
            $this->respond(204, null);
        } catch (Throwable $e) {
            $this->respond(204, null);
        }
        $this->respond(204, null);
    }

    private function handle()
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        if ($method !== 'POST') {
            $this->respond(405, null);
        }
        if (FullmetrixSignature::header('X-Requested-With') !== 'XMLHttpRequest') {
            $this->respond(204, null);
        }
        if (!self::isActive() || !FullmetrixChanges::flagEnabled('module') || !FullmetrixChanges::flagEnabled('session')) {
            $this->respond(204, null);
        }

        $cookie = $this->context->cookie;
        if (!is_object($cookie)) {
            $this->respond(204, null);
        }
        $idCart = (int) $cookie->__get('id_cart');
        $idGuest = (int) $cookie->__get('id_guest');
        $cookieCustomer = (int) $cookie->__get('id_customer');
        $logged = (bool) $cookie->__get('logged') && $cookieCustomer > 0;
        $idCustomer = $logged ? $cookieCustomer : 0;

        $row = null;
        if ($idCart > 0) {
            $candidate = FullmetrixCartSnapshot::fingerprintRow($idCart);
            if ($candidate !== null && self::cartBelongsToVisitor($candidate, $logged, $cookieCustomer, $idGuest)) {
                $row = $candidate;
            }
        }
        if ($row === null) {
            $idCart = 0;
        }
        if ($idCart === 0 && $idCustomer === 0 && $idGuest === 0) {
            $this->respond(204, null);
        }

        $fp = FullmetrixCartSnapshot::fingerprint($row, $idCustomer, $logged);
        $request = $this->readRequest();
        if ($request['fp'] !== null && hash_equals($fp, $request['fp'])) {
            $this->respond(204, null);
        }

        $events = [];
        if ($row !== null) {
            $built = FullmetrixCartSnapshot::build($idCart, $logged, null);
            if ($built['status'] === 'ok' && empty($built['ordered']) && empty($built['empty'])) {
                $events[] = ['type' => 'cart_updated', 'properties' => $built['payload']];
            }
        }
        if ($logged) {
            $contact = FullmetrixCartSnapshot::identify($idCustomer);
            if ($contact !== null) {
                $events[] = ['type' => 'identify', 'properties' => $contact];
            }
        }

        $response = [
            'v' => 1,
            'id_cart' => $idCart,
            'id_guest' => $idGuest,
            'id_customer' => $idCustomer,
            'fp' => $fp,
            'events' => $events,
        ];
        if ($request['vid'] !== null) {
            $timestamp = FullmetrixSignature::nowMs();
            $response['ts'] = $timestamp;
            $response['sig'] = FullmetrixSignature::sessionProof(
                Configuration::get('FULLMETRIX_CONNECTION_SECRET'),
                $timestamp,
                $idCart,
                $idGuest,
                $idCustomer,
                $request['vid']
            );
        }

        $this->respond(200, $response);
    }

    private static function isActive()
    {
        return (bool) Configuration::get('FULLMETRIX_REGISTERED')
            && Configuration::get('FULLMETRIX_CONNECTION_CODE') != ''
            && Configuration::get('FULLMETRIX_CONNECTION_SECRET') != '';
    }

    private static function cartBelongsToVisitor(array $row, $logged, $cookieCustomer, $idGuest)
    {
        if ((int) $row['id_customer'] > 0) {
            return $logged && (int) $row['id_customer'] === (int) $cookieCustomer;
        }

        return (int) $row['id_guest'] === (int) $idGuest;
    }

    private function readRequest()
    {
        $request = ['fp' => null, 'vid' => null];
        $stream = @fopen('php://input', 'rb');
        if ($stream === false) {
            return $request;
        }
        $body = @stream_get_contents($stream, self::$maxBodyBytes + 1);
        @fclose($stream);
        if (!is_string($body) || $body === '' || strlen($body) > self::$maxBodyBytes) {
            return $request;
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return $request;
        }
        if (isset($decoded['fp']) && is_string($decoded['fp']) && preg_match('/^[a-f0-9]{16}$/', $decoded['fp'])) {
            $request['fp'] = $decoded['fp'];
        }
        if (isset($decoded['vid']) && is_string($decoded['vid']) && preg_match(self::$visitorPattern, $decoded['vid'])) {
            $request['vid'] = $decoded['vid'];
        }

        return $request;
    }

    private function respond($status, $data)
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        $body = $data === null ? '' : FullmetrixChanges::json($data);
        if (!headers_sent()) {
            http_response_code((int) $status);
            header('Cache-Control: private, no-store, max-age=0');
            header('Pragma: no-cache');
            header('Vary: Cookie');
            header('X-Content-Type-Options: nosniff');
            if ($body !== '') {
                header('Content-Type: application/json; charset=utf-8');
            }
            header('Content-Length: ' . strlen($body));
        }
        echo $body;
        exit;
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
