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

class FullmetrixConnectorRelayModuleFrontController extends ModuleFrontController
{
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
        require_once _PS_MODULE_DIR_ . 'fullmetrixconnector/classes/FullmetrixRelay.php';
        FullmetrixFormatContext::bind($this->context);
        FullmetrixFormatContext::lockCookie();
        @ignore_user_abort(true);
        @set_time_limit(30);

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        if ($method !== 'POST') {
            $this->finish(405, true);
        }
        if (!FullmetrixRelay::isActive()) {
            $this->finish(204, true);
        }

        $ticket = $this->readTicket();
        $parsed = FullmetrixSignature::verifyTicket(
            Configuration::get('FULLMETRIX_CONNECTION_SECRET'),
            Configuration::get('FULLMETRIX_CONNECTION_CODE'),
            $ticket
        );
        if ($parsed === null) {
            $this->finish(204, true);
        }

        $this->finish(204, false);
        try {
            FullmetrixRelay::guardedRun($parsed['src'], $parsed['nonce']);
        } catch (Throwable $e) {
            FullmetrixRelay::releaseLock();
        }
        exit;
    }

    private function readTicket()
    {
        if (isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > FullmetrixRelay::MAX_TICKET_BYTES) {
            return null;
        }
        $stream = @fopen('php://input', 'rb');
        if ($stream === false) {
            return null;
        }
        $body = @stream_get_contents($stream, FullmetrixRelay::MAX_TICKET_BYTES + 1);
        @fclose($stream);
        if (!is_string($body) || strlen($body) > FullmetrixRelay::MAX_TICKET_BYTES) {
            return null;
        }

        return trim($body);
    }

    private function finish($status, $stop)
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code((int) $status);
            header('Cache-Control: private, no-store');
            header('Content-Length: 0');
            header('Connection: close');
        }
        if ($stop) {
            exit;
        }
        @flush();
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
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
    }
}
