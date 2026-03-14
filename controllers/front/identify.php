<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Identify Controller - Handles contact identification from JS tracker
 */
class FullmetrixConnectorIdentifyModuleFrontController extends ModuleFrontController
{
    public $ajax = true;
    public $ssl = true;

    public function init()
    {
        $this->ajax = true;
    }

    public function postProcess()
    {
        // Set JSON headers
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

        // Handle CORS preflight
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }

        // Only POST allowed
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['error' => 'Method not allowed'], 405);
        }

        // Get JSON body
        $rawBody = file_get_contents('php://input');
        $data = json_decode($rawBody, true);

        $email = isset($data['email']) ? trim($data['email']) : '';
        $phone = isset($data['phone']) ? trim($data['phone']) : '';
        $source = isset($data['source']) ? $data['source'] : 'manual';

        if (empty($email) && empty($phone)) {
            $this->jsonResponse(['error' => 'Email or phone required'], 400);
        }

        // Validate email
        if (!empty($email) && !Validate::isEmail($email)) {
            $this->jsonResponse(['error' => 'Invalid email'], 400);
        }

        // Just set the cookie — the JS tracker already sent the identify event
        if (!empty($email)) {
            $this->setContactCookie($email, $phone);
        }

        $this->jsonResponse(['success' => true]);
    }

    /**
     * Set contact cookie (same as WooCommerce format)
     */
    private function setContactCookie($email, $phone = null)
    {
        $contactData = [
            'email' => $email,
            'phone' => $phone,
            'identified_at' => time(),
        ];

        $encoded = base64_encode(json_encode($contactData));
        $expires = time() + 63072000; // 2 years

        $secure = Tools::usingSecureMode();

        if (PHP_VERSION_ID >= 70300) {
            setcookie(FullmetrixEventTracker::COOKIE_CONTACT_ID, $encoded, [
                'expires' => $expires,
                'path' => '/',
                'domain' => '',
                'secure' => $secure,
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        } else {
            setcookie(
                FullmetrixEventTracker::COOKIE_CONTACT_ID,
                $encoded,
                $expires,
                '/; SameSite=Lax',
                '',
                $secure,
                false
            );
        }

        $_COOKIE[FullmetrixEventTracker::COOKIE_CONTACT_ID] = $encoded;
    }

    /**
     * Send identify event to Fullmetrix API
     */
    private function sendIdentifyEvent($email, $phone, $source)
    {
        $secret = Configuration::get('FULLMETRIX_CONNECTION_SECRET');
        $code = Configuration::get('FULLMETRIX_CONNECTION_CODE');

        if (empty($secret) || empty($code)) {
            return false;
        }

        $event = [
            'event_id' => $this->generateUuid(),
            'event_type' => 'identify',
            'visitor_id' => FullmetrixEventTracker::getVisitorId(),
            'session_id' => FullmetrixEventTracker::getSessionId(),
            'properties' => [
                'email' => $email,
                'phone' => $phone,
                'source' => $source,
            ],
            'occurred_at' => round(microtime(true) * 1000),
        ];

        // Add customer info if logged in
        if ($this->context->customer && $this->context->customer->id) {
            $event['properties']['customer_id'] = $this->context->customer->id;
            $event['properties']['first_name'] = $this->context->customer->firstname;
            $event['properties']['last_name'] = $this->context->customer->lastname;
        }

        $payload = json_encode([
            'events' => [$event],
            'visitor_id' => $event['visitor_id'],
            'session_id' => $event['session_id'],
            'plugin_version' => FullmetrixConnector::FULLMETRIX_VERSION,
        ]);

        $timestamp = round(microtime(true) * 1000);
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        $url = FullmetrixConnector::getApiBase() . '/../webhooks/events';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Fullmetrix-Connection-Code: ' . $code,
                'X-Fullmetrix-Signature: ' . $signature,
                'X-Fullmetrix-Timestamp: ' . $timestamp,
            ],
        ]);

        curl_exec($ch);
        curl_close($ch);

        return true;
    }

    /**
     * Generate UUID v4
     */
    private function generateUuid()
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Send JSON response and exit
     */
    private function jsonResponse($data, $httpCode = 200)
    {
        http_response_code($httpCode);
        echo json_encode($data);
        exit;
    }
}
