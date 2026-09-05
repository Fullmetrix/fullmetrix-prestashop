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
 *
 * The response is never detached. fastcgi_finish_request would run before
 * PrestaShop writes its session cookie from Cookie::__destruct(), silently
 * dropping the session_id/session_token that registerSession() adds on login,
 * which locks the customer out on the next request. Aggressive timeouts cap
 * the worst-case latency for the client instead: a flush measures under 200ms
 * (payload build plus one POST), bounded at 800ms per entity if the API is
 * unreachable.
 *
 * Only requests that actually queued something reach the flush, so page views
 * are untouched: the cost falls on cart updates, login and order validation.
 */
class FullmetrixWebhookSender
{
    /** @var array<string, array{type: string, id: int|string, shop_id: int}> */
    private static $queue = [];

    /** @var bool */
    private static $shutdownRegistered = false;

    /** @var int */
    private static $idShop = 1;

    /** @var Link|null */
    private static $link;

    /** Connect timeout, in ms, while the visitor waits on the response. */
    public const CONNECT_TIMEOUT_MS = 300;

    /** Total timeout, in ms, while the visitor waits on the response. */
    public const TOTAL_TIMEOUT_MS = 800;

    public static function init($idShop = 1, $link = null)
    {
        self::$idShop = (int) $idShop;
        self::$link = $link;

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

    public static function enqueue($entityType, $id)
    {
        if (!Configuration::get('FULLMETRIX_WEBHOOKS_ENABLED')) {
            return;
        }

        $shopId = self::$idShop;
        try {
            $contextShopId = (int) Shop::getContextShopID();
            if ($contextShopId) {
                $shopId = $contextShopId;
            }
        } catch (Throwable $e) {
            // Fall back to the static idShop captured at init
        }

        $key = $entityType . ':' . $id . ':' . $shopId;
        self::$queue[$key] = [
            'type' => $entityType,
            'id' => $id,
            'shop_id' => (int) $shopId,
        ];
    }

    /**
     * Keep flushing even if the visitor navigates away mid-request, otherwise
     * an abort during the POST would lose the entity for good.
     */
    public static function keepRunningAfterAbort()
    {
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
    }

    public static function flushQueue()
    {
        if (empty(self::$queue)) {
            return;
        }

        self::keepRunningAfterAbort();

        try {
            $secret = Configuration::get('FULLMETRIX_CONNECTION_SECRET');
            $code = Configuration::get('FULLMETRIX_CONNECTION_CODE');
            if (empty($secret) || empty($code)) {
                self::$queue = [];

                return;
            }

            $apiUrl = str_replace('/api/plugin', '/api/webhooks/ecommerce', FullmetrixConnector::getApiBase());

            $connectTimeoutMs = self::CONNECT_TIMEOUT_MS;
            $totalTimeoutMs = self::TOTAL_TIMEOUT_MS;

            $exporters = [];
            foreach (self::$queue as $entry) {
                try {
                    $entryShopId = $entry['shop_id'] > 0 ? $entry['shop_id'] : self::$idShop;
                    if (!isset($exporters[$entryShopId])) {
                        $exporters[$entryShopId] = new FullmetrixStreamExporter($entryShopId, self::$link);
                    }
                    $exporter = $exporters[$entryShopId];
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
                    self::curlPost($apiUrl, $payload, $headers, $connectTimeoutMs, $totalTimeoutMs);
                } catch (Throwable $e) {
                    FullmetrixLogger::logException('webhookSender_entity', $e);
                }
            }
        } catch (Throwable $e) {
            FullmetrixLogger::logException('webhookSender_flushQueue', $e);
        }

        self::$queue = [];
    }

    /**
     * Fire-and-forget HTTP POST with strict DNS + connect + total timeouts.
     */
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
}
