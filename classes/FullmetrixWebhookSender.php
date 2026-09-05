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
 * On FPM: fastcgi_finish_request releases the client before any HTTP call.
 * On non-FPM: aggressive timeouts cap the worst-case latency for the client.
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

    /** @var bool Shared flag so fastcgi_finish_request is only called once per request */
    public static $responseFinished = false;

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
     * Persist the PrestaShop cookie before the response is detached.
     *
     * Cookie::write() bails out on headers_sent(), and PrestaShop only writes
     * the cookie from Cookie::__destruct(), which PHP runs *after* the shutdown
     * functions we register. On login, Context::updateCustomer() writes the
     * cookie and only then adds session_id/session_token via registerSession(),
     * so those two keys are still pending at shutdown. Detaching the response
     * first drops them, isSessionAlive() then fails on the next request and the
     * customer is bounced back to the login page, forever.
     */
    private static function flushPendingCookie()
    {
        try {
            $context = Context::getContext();
            if ($context && isset($context->cookie) && $context->cookie instanceof Cookie) {
                $context->cookie->write();
            }
        } catch (Throwable $e) {
            // Never let cookie persistence break the response
        }
    }

    /**
     * Release the client response if we're under FPM. Idempotent across senders.
     */
    public static function finishResponse()
    {
        if (self::$responseFinished) {
            return;
        }
        self::$responseFinished = true;

        self::flushPendingCookie();

        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
    }

    /**
     * Returns true if the response has actually been flushed to the client.
     *
     * Callers must ask this, never `function_exists('fastcgi_finish_request')`:
     * the latter only says FPM is available, not that the visitor has been
     * released. Anything still on the rendering path is waited on by a browser.
     */
    public static function isClientDetached()
    {
        return self::$responseFinished && function_exists('fastcgi_finish_request');
    }

    public static function flushQueue()
    {
        if (empty(self::$queue)) {
            return;
        }

        self::finishResponse();

        try {
            $secret = Configuration::get('FULLMETRIX_CONNECTION_SECRET');
            $code = Configuration::get('FULLMETRIX_CONNECTION_CODE');
            if (empty($secret) || empty($code)) {
                self::$queue = [];

                return;
            }

            $apiUrl = str_replace('/api/plugin', '/api/webhooks/ecommerce', FullmetrixConnector::getApiBase());

            $clientDetached = self::isClientDetached();
            $connectTimeoutMs = $clientDetached ? 2000 : 300;
            $totalTimeoutMs = $clientDetached ? 3000 : 800;

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
