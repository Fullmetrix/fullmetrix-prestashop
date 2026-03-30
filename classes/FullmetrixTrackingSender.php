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

class FullmetrixTrackingSender
{
    private static $events = [];
    private static $shutdownRegistered = false;

    public static function init()
    {
        if (!Configuration::get('FULLMETRIX_REGISTERED')) {
            return;
        }

        if (!self::$shutdownRegistered) {
            register_shutdown_function([__CLASS__, 'flush']);
            self::$shutdownRegistered = true;
        }
    }

    public static function enqueueEvent($eventType, $properties = [], $contact = null)
    {
        $visitorId = self::readVisitorId();
        $sessionId = self::readSessionId();
        if (!$visitorId || !$sessionId) {
            return;
        }

        if ($eventType === 'identify' && empty($contact) && empty($properties)) {
            return;
        }

        $contactData = $contact ?: self::readContact();

        $uuid = bin2hex(random_bytes(16));
        $eventId = 'srv_' . substr($uuid, 0, 8) . '-' . substr($uuid, 8, 4) . '-' . substr($uuid, 12, 4) . '-' . substr($uuid, 16, 4) . '-' . substr($uuid, 20);

        self::$events[] = [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'properties' => $properties,
            'occurred_at' => round(microtime(true) * 1000),
            'contact' => $contactData,
            'page' => [
                'url' => self::getCurrentUrl(),
            ],
        ];
    }

    public static function flush()
    {
        if (empty(self::$events)) {
            return;
        }

        $visitorId = self::readVisitorId();
        $sessionId = self::readSessionId();
        if (!$visitorId || !$sessionId) {
            self::$events = [];
            return;
        }

        $secret = Configuration::get('FULLMETRIX_CONNECTION_SECRET');
        $code = Configuration::get('FULLMETRIX_CONNECTION_CODE');
        if (empty($secret) || empty($code)) {
            self::$events = [];
            return;
        }

        $apiUrl = str_replace('/api/plugin', '/api/webhooks/events', FullmetrixConnector::getApiBase());

        $payload = json_encode([
            'events' => self::$events,
            'visitor_id' => $visitorId,
            'session_id' => $sessionId,
            'plugin_version' => 'server-' . FullmetrixConnector::FULLMETRIX_VERSION,
            'timestamp' => round(microtime(true) * 1000),
        ], JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            self::$events = [];
            return;
        }

        $headers = FullmetrixSecurity::createSignedHeaders($secret, $code, $payload);

        $httpHeaders = "Content-Type: application/json\r\n";
        foreach ($headers as $name => $value) {
            $httpHeaders .= $name . ': ' . $value . "\r\n";
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $httpHeaders,
                'content' => $payload,
                'timeout' => 5,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
            ],
        ]);

        $result = @file_get_contents($apiUrl, false, $context);

        $summary = [];
        foreach (self::$events as $e) {
            $t = $e['event_type'];
            $summary[$t] = isset($summary[$t]) ? $summary[$t] + 1 : 1;
        }
        FullmetrixLogger::log('tracking', 'Sent ' . count(self::$events) . ' tracking event(s)', $summary);

        if ($result === false) {
            FullmetrixLogger::log('tracking', 'Tracking delivery failed', $summary);
        }

        self::$events = [];
    }

    private static function readVisitorId()
    {
        return isset($_COOKIE['fm_vid']) ? pSQL($_COOKIE['fm_vid']) : null;
    }

    private static function readSessionId()
    {
        return isset($_COOKIE['fm_sid']) ? pSQL($_COOKIE['fm_sid']) : null;
    }

    private static function readContact()
    {
        if (!isset($_COOKIE['fm_cid'])) {
            return null;
        }
        $raw = urldecode($_COOKIE['fm_cid']);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        $sanitized = [];
        $allowedKeys = ['email', 'phone', 'first_name', 'last_name', 'customer_id', 'country_code', 'identified_at'];
        foreach ($allowedKeys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                $sanitized[$key] = pSQL($data[$key]);
            }
        }
        return empty($sanitized) ? null : $sanitized;
    }

    private static function getCurrentUrl()
    {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        return $protocol . '://' . $host . $uri;
    }
}
