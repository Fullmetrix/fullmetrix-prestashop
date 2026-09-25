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

class FullmetrixSignature
{
    public const TOLERANCE_MS = 300000;
    public const TICKET_VALIDITY_MS = 60000;
    public const TICKET_TOLERANCE_MS = 300000;
    public const HEADER_VERSION = 'X-Fullmetrix-Sig-Version';
    public const HEADER_CODE = 'X-Fullmetrix-Connection-Code';
    public const HEADER_TIMESTAMP = 'X-Fullmetrix-Timestamp';
    public const HEADER_NONCE = 'X-Fullmetrix-Nonce';
    public const HEADER_SIGNATURE = 'X-Fullmetrix-Signature';
    public const HEADER_BODY_SIGNATURE = 'X-Fullmetrix-Body-Signature';

    private static $excludedParams = ['fc', 'module', 'controller', 'isolang', 'id_lang'];

    public static function rawQueryString()
    {
        if (isset($_SERVER['QUERY_STRING']) && is_string($_SERVER['QUERY_STRING'])) {
            return $_SERVER['QUERY_STRING'];
        }
        if (isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])) {
            $pos = strpos($_SERVER['REQUEST_URI'], '?');
            if ($pos !== false) {
                return (string) substr($_SERVER['REQUEST_URI'], $pos + 1);
            }
        }

        return '';
    }

    public static function queryPairs($query)
    {
        $query = (string) $query;
        if ($query !== '' && $query[0] === '?') {
            $query = (string) substr($query, 1);
        }
        $pairs = [];
        foreach (explode('&', $query) as $segment) {
            if ($segment === '') {
                continue;
            }
            $eq = strpos($segment, '=');
            $name = $eq === false ? $segment : substr($segment, 0, $eq);
            $value = $eq === false ? '' : substr($segment, $eq + 1);
            $pairs[] = [urldecode($name), urldecode((string) $value)];
        }

        return $pairs;
    }

    public static function queryValue($query, $name)
    {
        foreach (self::queryPairs($query) as $pair) {
            if ($pair[0] === $name) {
                return $pair[1];
            }
        }

        return null;
    }

    public static function canonicalQuery($query)
    {
        $encoded = [];
        foreach (self::queryPairs($query) as $pair) {
            if (in_array($pair[0], self::$excludedParams, true)) {
                continue;
            }
            $encoded[] = [rawurlencode($pair[0]), rawurlencode($pair[1])];
        }
        usort($encoded, ['FullmetrixSignature', 'comparePairs']);
        $parts = [];
        foreach ($encoded as $pair) {
            $parts[] = $pair[0] . '=' . $pair[1];
        }

        return implode('&', $parts);
    }

    public static function comparePairs($a, $b)
    {
        $byName = strcmp($a[0], $b[0]);

        return $byName !== 0 ? $byName : strcmp($a[1], $b[1]);
    }

    public static function stringToSign($method, $query, $timestamp, $nonce, $body)
    {
        return "v2\n" . strtoupper((string) $method) . "\n" . self::canonicalQuery($query) . "\n"
            . $timestamp . "\n" . $nonce . "\n" . hash('sha256', (string) $body);
    }

    public static function signRequest($secret, $method, $query, $timestamp, $nonce, $body)
    {
        return hash_hmac('sha256', self::stringToSign($method, $query, $timestamp, $nonce, $body), (string) $secret);
    }

    public static function signResponse($secret, $requestNonce, $timestamp, $body)
    {
        return hash_hmac('sha256', $requestNonce . '.' . $timestamp . '.' . $body, (string) $secret);
    }

    public static function nowMs()
    {
        return sprintf('%.0f', floor(microtime(true) * 1000));
    }

    public static function randomHex($bytes)
    {
        $bytes = (int) $bytes;
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes($bytes));
        }
        if (function_exists('openssl_random_pseudo_bytes')) {
            return bin2hex(openssl_random_pseudo_bytes($bytes));
        }
        $out = '';
        for ($i = 0; $i < $bytes; ++$i) {
            $out .= sprintf('%02x', mt_rand(0, 255));
        }

        return $out;
    }

    public static function header($name)
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$serverKey]) && is_string($_SERVER[$serverKey]) && $_SERVER[$serverKey] !== '') {
            return $_SERVER[$serverKey];
        }
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $key => $value) {
                    if (strcasecmp($key, $name) === 0 && is_string($value)) {
                        return $value;
                    }
                }
            }
        }

        return null;
    }

    public static function isV2Request()
    {
        return self::header(self::HEADER_VERSION) === '2';
    }

    public static function verifyRequest($secret, $code, $method, $query, $body, $nowMs = null)
    {
        if (empty($secret) || empty($code)) {
            return ['ok' => false, 'reason' => 'not_configured', 'nonce' => null];
        }
        if (self::header(self::HEADER_VERSION) !== '2') {
            return ['ok' => false, 'reason' => 'version', 'nonce' => null];
        }
        $receivedCode = self::header(self::HEADER_CODE);
        $timestamp = self::header(self::HEADER_TIMESTAMP);
        $nonce = self::header(self::HEADER_NONCE);
        $signature = self::header(self::HEADER_SIGNATURE);
        if (!is_string($receivedCode) || !hash_equals((string) $code, $receivedCode)) {
            return ['ok' => false, 'reason' => 'code', 'nonce' => null];
        }
        if (!is_string($timestamp) || !preg_match('/^\d{1,16}$/', $timestamp)
            || !is_string($nonce) || !preg_match('/^[a-f0-9]{32}$/', $nonce)
            || !is_string($signature) || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return ['ok' => false, 'reason' => 'format', 'nonce' => null];
        }
        $now = $nowMs === null ? (float) self::nowMs() : (float) $nowMs;
        if (abs($now - (float) $timestamp) > self::TOLERANCE_MS) {
            return ['ok' => false, 'reason' => 'expired', 'nonce' => $nonce];
        }
        $expected = self::signRequest($secret, $method, $query, $timestamp, $nonce, $body);
        if (!hash_equals($expected, $signature)) {
            return ['ok' => false, 'reason' => 'signature', 'nonce' => $nonce];
        }

        return ['ok' => true, 'reason' => null, 'nonce' => $nonce];
    }

    public static function requestHeaders($secret, $code, $method, $query, $body)
    {
        $timestamp = self::nowMs();
        $nonce = self::randomHex(16);

        return [
            'nonce' => $nonce,
            'headers' => [
                self::HEADER_VERSION . ': 2',
                self::HEADER_CODE . ': ' . $code,
                self::HEADER_TIMESTAMP . ': ' . $timestamp,
                self::HEADER_NONCE . ': ' . $nonce,
                self::HEADER_SIGNATURE . ': ' . self::signRequest($secret, $method, $query, $timestamp, $nonce, $body),
            ],
        ];
    }

    public static function responseHeaders($secret, $requestNonce, $body)
    {
        $timestamp = self::nowMs();

        return [
            self::HEADER_TIMESTAMP => $timestamp,
            self::HEADER_BODY_SIGNATURE => self::signResponse($secret, $requestNonce, $timestamp, $body),
        ];
    }

    public static function verifyResponse($secret, $requestNonce, $timestamp, $bodySignature, $body)
    {
        if (!is_string($timestamp) || !preg_match('/^\d{1,16}$/', $timestamp)) {
            return false;
        }
        if (!is_string($bodySignature) || !preg_match('/^[a-f0-9]{64}$/', $bodySignature)) {
            return false;
        }

        return hash_equals(self::signResponse($secret, $requestNonce, $timestamp, (string) $body), $bodySignature);
    }

    public static function ticketMac($secret, $code, $expMs, $nonce, $src)
    {
        return hash_hmac('sha256', 'relay-ticket|v1|' . $code . '|' . $expMs . '|' . $nonce . '|' . $src, (string) $secret);
    }

    public static function sessionProof($secret, $timestamp, $idCart, $idGuest, $idCustomer, $visitorId)
    {
        return hash_hmac(
            'sha256',
            'session|v1|' . $timestamp . '|' . (int) $idCart . '|' . (int) $idGuest . '|' . (int) $idCustomer . '|' . $visitorId,
            (string) $secret
        );
    }

    public static function verifyTicket($secret, $code, $ticket, $nowMs = null)
    {
        if (!is_string($ticket) || empty($secret) || empty($code)) {
            return null;
        }
        if (!preg_match('/^v1\.(\d{10,16})\.([a-f0-9]{16})\.(t|bo)\.([a-f0-9]{64})$/', $ticket, $m)) {
            return null;
        }
        $now = $nowMs === null ? (float) self::nowMs() : (float) $nowMs;
        $exp = (float) $m[1];
        if ($now > $exp + self::TICKET_TOLERANCE_MS) {
            return null;
        }
        if ($exp > $now + self::TICKET_VALIDITY_MS + self::TICKET_TOLERANCE_MS) {
            return null;
        }
        if (!hash_equals(self::ticketMac($secret, $code, $m[1], $m[2], $m[3]), $m[4])) {
            return null;
        }

        return ['exp_ms' => $m[1], 'nonce' => $m[2], 'src' => $m[3]];
    }
}
