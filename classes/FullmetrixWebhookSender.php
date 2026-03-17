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

/**
 * Fullmetrix Webhook Sender for PrestaShop
 *
 * Queue + flush design: hooks enqueue {entity_type, id} pairs,
 * then the shutdown hook deduplicates and sends one POST per entity.
 * Uses stream_context_create with timeout for fire-and-forget.
 */
class FullmetrixWebhookSender
{
    /** @var array<string, array{type: string, id: int|string}> */
    private static $queue = [];

    /** @var bool */
    private static $shutdownRegistered = false;

    /** @var int */
    private static $idShop = 1;

    /** @var \Link|null */
    private static $link;

    /**
     * @param int        $idShop Shop ID
     * @param \Link|null $link   PrestaShop Link instance
     */
    public static function init($idShop = 1, $link = null)
    {
        self::$idShop = (int) $idShop;
        self::$link = $link;

        // Only activate if webhooks flag is set
        if (!Configuration::get('FULLMETRIX_WEBHOOKS_ENABLED')) {
            return;
        }

        $secret = Configuration::get('FULLMETRIX_CONNECTION_SECRET');
        $code = Configuration::get('FULLMETRIX_CONNECTION_CODE');
        if (empty($secret) || empty($code)) {
            return;
        }

        if (!self::$shutdownRegistered) {
            register_shutdown_function([__CLASS__, 'flushQueue']);
            self::$shutdownRegistered = true;
        }
    }

    /**
     * Enqueue an entity to be sent as a webhook at shutdown.
     *
     * @param string     $entityType order|customer|product|category|coupon|refund
     * @param int|string $id
     */
    public static function enqueue($entityType, $id)
    {
        if (!Configuration::get('FULLMETRIX_WEBHOOKS_ENABLED')) {
            return;
        }

        $key = $entityType . ':' . $id;
        self::$queue[$key] = [
            'type' => $entityType,
            'id' => $id,
        ];

        if (!self::$shutdownRegistered) {
            register_shutdown_function([__CLASS__, 'flushQueue']);
            self::$shutdownRegistered = true;
        }
    }

    public static function flushQueue()
    {
        if (empty(self::$queue)) {
            return;
        }

        $secret = Configuration::get('FULLMETRIX_CONNECTION_SECRET');
        $code = Configuration::get('FULLMETRIX_CONNECTION_CODE');
        if (empty($secret) || empty($code)) {
            return;
        }

        $exporter = new FullmetrixStreamExporter(self::$idShop, self::$link);
        $apiUrl = FullmetrixConnector::getApiBase() . '/../webhooks/ecommerce';

        // Build summary for logging
        $webhookSummary = [];
        foreach (self::$queue as $entry) {
            $t = $entry['type'];
            $webhookSummary[$t] = isset($webhookSummary[$t]) ? $webhookSummary[$t] + 1 : 1;
        }
        FullmetrixLogger::log('webhook', 'Envoi de ' . count(self::$queue) . ' webhook(s)', $webhookSummary);

        foreach (self::$queue as $entry) {
            $data = $exporter->formatSingleEntity($entry['type'], $entry['id']);
            if ($data === null) {
                continue;
            }

            $payload = json_encode([
                'event' => $entry['type'] . '.updated',
                'entity_type' => $entry['type'],
                'data' => $data,
                'plugin_version' => FullmetrixConnector::FULLMETRIX_VERSION,
                'timestamp' => round(microtime(true) * 1000),
            ], JSON_UNESCAPED_UNICODE);

            if ($payload === false) {
                continue;
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

            // Send with 1 retry on failure
            $result = @file_get_contents($apiUrl, false, $context);
            if ($result === false) {
                usleep(500000); // 500ms backoff
                @file_get_contents($apiUrl, false, $context);
            }
        }

        self::$queue = [];
    }
}
