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

class FullmetrixLegacyPhp
{
    public static function unsupported()
    {
        return PHP_VERSION_ID < 70100;
    }

    public static function refuse()
    {
        if (!headers_sent()) {
            header('HTTP/1.1 503 Service Unavailable');
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo '{"error":"php_unsupported"}';
        exit;
    }
}
