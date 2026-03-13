<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Events Controller - Receives tracking events from JS tracker
 * Enriches events with server-side data and forwards to Fullmetrix API
 */
class FullmetrixConnectorEventsModuleFrontController extends ModuleFrontController
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
        $events = json_decode($rawBody, true);

        if (!is_array($events) || empty($events)) {
            $this->jsonResponse(['error' => 'Invalid events data'], 400);
        }

        // Enrich and forward events
        $enrichedEvents = [];
        foreach ($events as $event) {
            $enriched = $this->enrichEvent($event);
            if ($enriched) {
                $enrichedEvents[] = $enriched;
            }
        }

        if (!empty($enrichedEvents)) {
            $this->forwardToFullmetrix($enrichedEvents);
        }

        $this->jsonResponse([
            'success' => true,
            'processed' => count($enrichedEvents),
        ]);
    }

    /**
     * Enrich event with server-side data
     */
    private function enrichEvent($event)
    {
        if (!isset($event['event_type'])) {
            return null;
        }

        // Add server timestamp
        $event['server_timestamp'] = round(microtime(true) * 1000);

        // Add IP address
        $event['ip_address'] = $this->getClientIp();

        // Add user agent
        $event['user_agent'] = isset($_SERVER['HTTP_USER_AGENT'])
            ? Tools::substr($_SERVER['HTTP_USER_AGENT'], 0, 512)
            : '';

        // Add visitor/session from cookies if not present
        if (empty($event['visitor_id'])) {
            $event['visitor_id'] = FullmetrixEventTracker::getVisitorId();
        }
        if (empty($event['session_id'])) {
            $event['session_id'] = FullmetrixEventTracker::getSessionId();
        }

        // Add contact from cookie if available
        $contact = FullmetrixEventTracker::getContactId();
        if ($contact && empty($event['contact_id'])) {
            $event['contact_id'] = $contact;
        }

        // Enrich product data if product_id is present
        if (isset($event['properties']['product_id']) && empty($event['properties']['product'])) {
            $productData = FullmetrixEventTracker::formatProductForEvent(
                (int) $event['properties']['product_id'],
                $this->context->language->id
            );
            if ($productData) {
                $event['properties']['product'] = $productData;
            }
        }

        // Add customer info if logged in
        if ($this->context->customer && $this->context->customer->id) {
            $customer = $this->context->customer;
            $event['customer'] = [
                'id' => $customer->id,
                'email' => $customer->email,
                'first_name' => $customer->firstname,
                'last_name' => $customer->lastname,
            ];
        }

        return $event;
    }

    /**
     * Forward events to Fullmetrix API
     */
    private function forwardToFullmetrix($events)
    {
        $secret = Configuration::get('FULLMETRIX_CONNECTION_SECRET');
        $code = Configuration::get('FULLMETRIX_CONNECTION_CODE');

        if (empty($secret) || empty($code)) {
            return false;
        }

        // Build payload
        $payload = json_encode([
            'events' => $events,
            'visitor_id' => $events[0]['visitor_id'] ?? FullmetrixEventTracker::getVisitorId(),
            'session_id' => $events[0]['session_id'] ?? FullmetrixEventTracker::getSessionId(),
            'contact_id' => $events[0]['contact_id'] ?? null,
            'source' => 'prestashop',
            'plugin_version' => FullmetrixConnector::FULLMETRIX_VERSION,
            'site_url' => Tools::getShopDomainSsl(true),
        ]);

        if ($payload === false) {
            return false;
        }

        // Create signed request
        $timestamp = round(microtime(true) * 1000);
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        $url = FullmetrixConnector::FULLMETRIX_API_BASE . '/../webhooks/events';

        // Send with cURL (non-blocking for performance)
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Fullmetrix-Connection-Code: ' . $code,
                'X-Fullmetrix-Signature: ' . $signature,
                'X-Fullmetrix-Timestamp: ' . $timestamp,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 300;
    }

    /**
     * Get client IP address (handles proxies)
     */
    private function getClientIp()
    {
        $ipKeys = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Handle comma-separated IPs
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
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
