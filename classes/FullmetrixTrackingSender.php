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
    /** @var array<int, array<string, mixed>> */
    private static $events = [];

    /** @var bool */
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

        $event = [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'properties' => $properties,
            'occurred_at' => round(microtime(true) * 1000),
            'contact' => $contactData,
            'page' => [
                'url' => self::getCurrentUrl(),
            ],
        ];

        // cart_updated fires on every Cart::update in PrestaShop (5-10x per checkout step).
        // Keep only the latest snapshot to avoid sending duplicate identical payloads.
        if ($eventType === 'cart_updated') {
            foreach (self::$events as $idx => $existing) {
                if (isset($existing['event_type']) && $existing['event_type'] === 'cart_updated') {
                    self::$events[$idx] = $event;

                    return;
                }
            }
        }

        self::$events[] = $event;
    }

    public static function flush()
    {
        if (empty(self::$events)) {
            return;
        }

        FullmetrixWebhookSender::keepRunningAfterAbort();

        try {
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

            $connectTimeoutMs = FullmetrixWebhookSender::CONNECT_TIMEOUT_MS;
            $totalTimeoutMs = FullmetrixWebhookSender::TOTAL_TIMEOUT_MS;

            self::curlPost($apiUrl, $payload, $headers, $connectTimeoutMs, $totalTimeoutMs);
        } catch (Throwable $e) {
            FullmetrixLogger::logException('trackingSender_flush', $e);
        }

        self::$events = [];
    }

    private static function curlPost($url, $body, $signedHeaders, $connectTimeoutMs, $totalTimeoutMs)
    {
        if (!function_exists('curl_init')) {
            return;
        }

        $headerLines = ['Content-Type: application/json'];
        foreach ($signedHeaders as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $ch = curl_init($url);
        if (!$ch) {
            return;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_CONNECTTIMEOUT_MS => (int) $connectTimeoutMs,
            CURLOPT_TIMEOUT_MS => (int) $totalTimeoutMs,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        @curl_exec($ch);
        @curl_close($ch);
    }

    private static function readVisitorId()
    {
        if (!isset($_COOKIE['fm_vid'])) {
            return null;
        }

        return self::sanitizeId($_COOKIE['fm_vid']);
    }

    private static function readSessionId()
    {
        if (!isset($_COOKIE['fm_sid'])) {
            return null;
        }

        return self::sanitizeId($_COOKIE['fm_sid']);
    }

    private static function sanitizeId($value)
    {
        if (!is_string($value)) {
            return null;
        }
        $value = substr($value, 0, 64);

        return preg_match('/^[a-zA-Z0-9_\-]{1,64}$/', $value) ? $value : null;
    }

    private static function readContact()
    {
        if (!isset($_COOKIE['fm_cid'])) {
            return null;
        }
        $raw = urldecode($_COOKIE['fm_cid']);
        if (strlen($raw) > 8192) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        $sanitized = [];
        $allowedKeys = ['email', 'phone', 'first_name', 'last_name', 'customer_id', 'country_code', 'identified_at'];
        foreach ($allowedKeys as $key) {
            if (!isset($data[$key]) || $data[$key] === '') {
                continue;
            }
            $value = $data[$key];
            if (is_string($value)) {
                $value = substr($value, 0, 255);
            } elseif (is_int($value) || is_float($value)) {
                // OK as-is
            } else {
                continue;
            }
            $sanitized[$key] = $value;
        }

        return empty($sanitized) ? null : $sanitized;
    }

    private static function getCurrentUrl()
    {
        return FullmetrixConnector::buildPublicUrl();
    }
}
