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

class FullmetrixConnectorKeepModuleFrontController extends ModuleFrontController
{
    public $ajax = true;
    public $content_only = true;
    public $display_header = false;
    public $display_footer = false;

    public function run()
    {
        @ini_set('display_errors', '0');
        $decision = ['status' => 404, 'value' => null, 'legacy' => false];
        $days = 0;
        $secure = false;
        try {
            require_once _PS_MODULE_DIR_ . 'fullmetrixconnector/classes/FullmetrixFormatContext.php';
            require_once _PS_MODULE_DIR_ . 'fullmetrixconnector/classes/FullmetrixKeeper.php';
            FullmetrixFormatContext::bind($this->context);
            FullmetrixFormatContext::lockCookie();
            $days = FullmetrixKeeper::days();
            $decision = FullmetrixKeeper::decide(
                $days,
                isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET',
                isset($_SERVER['HTTP_COOKIE']) ? $_SERVER['HTTP_COOKIE'] : '',
                $_COOKIE,
                $this->readBody(),
                isset($_SERVER['HTTP_SEC_FETCH_SITE']) ? (string) $_SERVER['HTTP_SEC_FETCH_SITE'] : null
            );
            $secure = (bool) Tools::usingSecureMode();
        } catch (Exception $e) {
            $decision = ['status' => 404, 'value' => null, 'legacy' => false];
        } catch (Throwable $e) {
            $decision = ['status' => 404, 'value' => null, 'legacy' => false];
        }
        $this->respond($decision, $days, $secure);
    }

    private function readBody()
    {
        $limit = FullmetrixKeeper::maxBodyBytes();
        if (isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > $limit) {
            return null;
        }
        $stream = @fopen('php://input', 'rb');
        if ($stream === false) {
            return null;
        }
        $body = @stream_get_contents($stream, $limit + 1);
        @fclose($stream);

        return is_string($body) && strlen($body) <= $limit ? $body : null;
    }

    private function respond($decision, $days, $secure)
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!headers_sent()) {
            if (function_exists('header_remove')) {
                header_remove('Set-Cookie');
            }
            http_response_code((int) $decision['status']);
            $lines = class_exists('FullmetrixKeeper', false)
                ? FullmetrixKeeper::headers($decision, $days, $secure)
                : ['Cache-Control: no-store, private', 'Content-Length: 0'];
            foreach ($lines as $line) {
                header($line, strpos($line, 'Set-Cookie: ') !== 0);
            }
        }
        exit;
    }
}
