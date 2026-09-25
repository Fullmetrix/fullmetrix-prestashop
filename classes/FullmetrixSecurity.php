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

class FullmetrixSecurity
{
    public const TIMESTAMP_TOLERANCE = 300000;

    public static function verifySignature($secret, $body, $signature, $timestamp)
    {
        $now = round(microtime(true) * 1000);

        if (abs($now - $timestamp) > self::TIMESTAMP_TOLERANCE) {
            return false;
        }

        $expected = self::signRequest($secret, $body, $timestamp);

        return hash_equals($expected, $signature);
    }

    public static function signRequest($secret, $body, $timestamp)
    {
        $message = $timestamp . '.' . $body;

        return hash_hmac('sha256', $message, $secret);
    }

    public static function createSignedHeaders($secret, $connectionCode, $body = '')
    {
        $timestamp = round(microtime(true) * 1000);
        $signature = self::signRequest($secret, $body, $timestamp);

        return [
            'X-Fullmetrix-Connection-Code' => $connectionCode,
            'X-Fullmetrix-Signature' => $signature,
            'X-Fullmetrix-Timestamp' => (string) $timestamp,
        ];
    }
}
