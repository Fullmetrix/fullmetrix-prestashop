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

require_once dirname(__FILE__) . '/FullmetrixSignature.php';
require_once dirname(__FILE__) . '/FullmetrixJournalStore.php';
require_once dirname(__FILE__) . '/FullmetrixChanges.php';
require_once dirname(__FILE__) . '/FullmetrixHealth.php';
require_once dirname(__FILE__) . '/FullmetrixInstallation.php';

class FullmetrixRelay
{
    public const DEFAULT_API_BASE = 'https://fullmetrix.com/api/plugin';
    public const MIN_INTERVAL_S = 20;
    public const CLAIM_FAILURE_PAUSE_S = 60;
    public const DEFAULT_BUDGET_MS = 4000;
    public const MAX_BUDGET_MS = 6000;
    public const DEFAULT_MAX_BYTES = 1500000;
    public const MAX_PUSH_BYTES = 4000000;
    public const MAX_TICKET_BYTES = 600;
    public const PUSH_ENVELOPE_BYTES = 65536;

    private static $lockName;

    public static function isActive()
    {
        return (bool) Configuration::get('FULLMETRIX_REGISTERED')
            && Configuration::get('FULLMETRIX_CONNECTION_CODE') != ''
            && Configuration::get('FULLMETRIX_CONNECTION_SECRET') != '';
    }

    public static function apiBase()
    {
        $custom = Configuration::get('FULLMETRIX_API_BASE');

        return rtrim($custom ? (string) $custom : self::DEFAULT_API_BASE, '/');
    }

    public static function lockName()
    {
        return 'fm_relay_' . preg_replace('/[^A-Za-z0-9_-]/', '', substr((string) Configuration::get('FULLMETRIX_CONNECTION_CODE'), 0, 8));
    }

    public static function acquireLock()
    {
        try {
            $got = FullmetrixJournalStore::value('SELECT GET_LOCK(\'' . pSQL(self::lockName()) . '\', 0)');
        } catch (Exception $e) {
            return false;
        }
        if ((string) $got !== '1') {
            return false;
        }
        self::$lockName = self::lockName();

        return true;
    }

    public static function releaseLock()
    {
        if (self::$lockName === null) {
            return;
        }
        try {
            FullmetrixJournalStore::value('SELECT RELEASE_LOCK(\'' . pSQL(self::$lockName) . '\')');
        } catch (Exception $e) {
            self::$lockName = null;

            return;
        }
        self::$lockName = null;
    }

    public static function lastRelay()
    {
        $raw = FullmetrixJournalStore::config(FullmetrixJournal::key('relay_last'));
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            $decoded = [];
        }

        return [
            'nonce' => isset($decoded['nonce']) ? (string) $decoded['nonce'] : '',
            'started_at' => isset($decoded['started_at']) ? (int) $decoded['started_at'] : 0,
            'claim_failed_at' => isset($decoded['claim_failed_at']) ? (int) $decoded['claim_failed_at'] : 0,
        ];
    }

    public static function admit($nonce)
    {
        $last = self::lastRelay();
        $now = time();
        if ($last['nonce'] !== '' && hash_equals($last['nonce'], (string) $nonce)) {
            return false;
        }
        if ($now - $last['started_at'] < self::MIN_INTERVAL_S) {
            return false;
        }
        if ($now - $last['claim_failed_at'] < self::CLAIM_FAILURE_PAUSE_S) {
            return false;
        }

        return FullmetrixJournalStore::setConfig(FullmetrixJournal::key('relay_last'), FullmetrixChanges::json([
            'nonce' => (string) $nonce,
            'started_at' => $now,
            'claim_failed_at' => $last['claim_failed_at'],
        ]));
    }

    private static function markClaimFailed()
    {
        $last = self::lastRelay();
        $last['claim_failed_at'] = time();
        FullmetrixJournalStore::setConfig(FullmetrixJournal::key('relay_last'), FullmetrixChanges::json($last));
    }

    public static function guardedRun($src, $nonce)
    {
        FullmetrixFormatContext::lockCookie();
        if (!self::isActive() || !FullmetrixChanges::flagEnabled('relay')) {
            return 'inactive';
        }
        $ownership = FullmetrixInstallation::decide(Configuration::get('FULLMETRIX_CONNECTION_CODE'));
        if ($ownership !== 'ok') {
            return $ownership === 'busy' ? 'locked' : 'conflict';
        }
        if (!self::acquireLock()) {
            return 'locked';
        }
        try {
            if (!self::admit($nonce)) {
                self::releaseLock();

                return 'throttled';
            }
            $status = self::run($src, $nonce);
        } catch (Exception $e) {
            $status = 'error';
        } catch (Throwable $e) {
            $status = 'error';
        }
        self::releaseLock();

        return $status;
    }

    public static function run($src, $nonce)
    {
        $secret = (string) Configuration::get('FULLMETRIX_CONNECTION_SECRET');
        $code = (string) Configuration::get('FULLMETRIX_CONNECTION_CODE');

        FullmetrixJournalStore::ensureLazy();
        if (FullmetrixJournalStore::isUsable()) {
            FullmetrixJournalStore::prepareSession();
            FullmetrixJournalStore::enforceLocalCap();
        }

        $claimBody = [
            'v' => 1,
            'nonce' => (string) $nonce,
            'src' => (string) $src,
            'module_version' => FullmetrixHealth::moduleVersion(),
            'ps_version' => _PS_VERSION_,
            'php_version' => PHP_VERSION,
            'flags_v' => FullmetrixChanges::flagsVersion(),
            'journal' => FullmetrixHealth::claimSummary(),
            'breaker' => FullmetrixChanges::breaker(),
            'hooks' => FullmetrixHealth::hooks(),
            'statsdata' => (bool) Module::isEnabled('statsdata'),
        ];
        $claim = self::post($secret, $code, '/relay/claim', FullmetrixChanges::json($claimBody), 2000, 4000);
        if ($claim['status'] === 204) {
            return 'nothing';
        }
        if ($claim['status'] !== 200 || !is_array($claim['json'])) {
            self::markClaimFailed();

            return 'claim_failed';
        }
        $response = $claim['json'];
        $fence = isset($response['fence']) && is_numeric($response['fence']) ? (int) $response['fence'] : 0;
        $plan = isset($response['plan']) && is_array($response['plan']) ? $response['plan'] : null;
        if ($fence <= 0 || $plan === null) {
            self::markClaimFailed();

            return 'claim_failed';
        }
        if (isset($response['flags']['v'], $response['flags']['values'])) {
            FullmetrixChanges::setFlags($response['flags']['v'], $response['flags']['values']);
        }

        $budgetMs = isset($plan['budget_ms']) && is_numeric($plan['budget_ms']) ? (int) $plan['budget_ms'] : self::DEFAULT_BUDGET_MS;
        $budgetMs = max(500, min(self::MAX_BUDGET_MS, $budgetMs));
        $maxBytes = isset($plan['max_bytes']) && is_numeric($plan['max_bytes']) ? (int) $plan['max_bytes'] : self::DEFAULT_MAX_BYTES;
        $maxBytes = max(10000, min(self::MAX_PUSH_BYTES, $maxBytes));

        $changes = FullmetrixChanges::changes([
            'ack' => isset($plan['ack']) ? $plan['ack'] : [],
            'cursors' => isset($plan['cursors']) ? $plan['cursors'] : [],
            'pending_orders' => isset($plan['pending_orders']) ? $plan['pending_orders'] : [],
            'recheck_orders' => isset($plan['recheck_orders']) ? $plan['recheck_orders'] : [],
            'since_upd' => isset($plan['since_upd']) ? $plan['since_upd'] : null,
            'since_upd_from' => isset($plan['since_upd_from']) ? $plan['since_upd_from'] : null,
            'max_rows' => FullmetrixJournalStore::MAX_READ_ROWS,
        ]);

        $request = FullmetrixChanges::normalizeRequest($plan);
        $firstDeadline = microtime(true) + $budgetMs / 1000;
        $firstBytes = min($maxBytes, self::MAX_PUSH_BYTES - strlen(FullmetrixChanges::json($changes)) - self::PUSH_ENVELOPE_BYTES);
        $first = FullmetrixChanges::entities(self::freshOrderItems($request, $changes), [], $firstDeadline, max(0, $firstBytes));

        $push1 = self::post($secret, $code, '/relay/push', FullmetrixChanges::json([
            'v' => 1,
            'nonce' => (string) $nonce,
            'fence' => $fence,
            'part' => 1,
            'changes' => $changes,
            'results' => $first['results'],
        ]), 2000, 8000);
        if ($push1['status'] !== 200) {
            return 'push1_failed';
        }

        $deadline = microtime(true) + $budgetMs / 1000;
        $items = self::remainingItems(
            FullmetrixChanges::normalizeItems(isset($plan['items']) ? $plan['items'] : [], 10000),
            $changes,
            $first['deferred']
        );
        $rest = FullmetrixChanges::entities($items, isset($plan['carts']) ? $plan['carts'] : [], $deadline, $maxBytes);
        $deferred = array_merge($first['deferred'], $rest['deferred']);
        $digest = null;
        if (isset($plan['digest']) && is_array($plan['digest']) && microtime(true) < $deadline) {
            try {
                $digest = FullmetrixChanges::digest($plan['digest']);
            } catch (Exception $e) {
                $digest = null;
            }
        }

        $push2 = self::post($secret, $code, '/relay/push', FullmetrixChanges::json([
            'v' => 1,
            'nonce' => (string) $nonce,
            'fence' => $fence,
            'part' => 2,
            'results' => $rest['results'],
            'cart_results' => $rest['cart_results'],
            'deferred' => $deferred,
            'digest' => $digest,
            'health' => FullmetrixHealth::local(),
        ]), 2000, 8000);

        return $push2['status'] === 200 ? 'ok' : 'push2_failed';
    }

    public static function freshOrderItems(array $request, array $changes)
    {
        $items = [];
        $cursors = $request['cursors'];
        $wanted = [];
        foreach ($request['pending_orders'] as $id) {
            $wanted[$id] = true;
        }
        foreach ($request['recheck_orders'] as $id) {
            $wanted[$id] = true;
        }
        foreach ($changes['orders'] as $order) {
            $isNew = $order['id'] > $cursors['order'];
            $stateChanged = $order['state_seq'] > $cursors['history'];
            if ($isNew || $stateChanged || isset($wanted[$order['id']])) {
                $items[] = ['type' => 'order', 'id' => $order['id'], 'sub' => 0, 'shop' => $order['id_shop'], 'offset' => 0];
            }
        }
        foreach ($changes['slips'] as $slip) {
            if ($slip['id'] > $cursors['slip']) {
                $items[] = ['type' => 'refund', 'id' => $slip['id'], 'sub' => 0, 'shop' => 0, 'offset' => 0];
            }
        }

        return $items;
    }

    public static function journalItems(array $changes)
    {
        $items = [];
        foreach ($changes['journal'] as $row) {
            $shop = (int) $row[2];
            $entity = (int) $row[3];
            $id = (int) $row[4];
            $sub = (int) $row[5];
            if ($id <= 0) {
                continue;
            }
            switch ($entity) {
                case FullmetrixJournal::entity('customer'):
                case FullmetrixJournal::entity('customer_login'):
                    $items[] = ['type' => 'customer', 'id' => $id, 'sub' => 0, 'shop' => $shop, 'offset' => 0];
                    break;
                case FullmetrixJournal::entity('product'):
                case FullmetrixJournal::entity('product_deleted'):
                    $items[] = ['type' => 'product_family', 'id' => $id, 'sub' => 0, 'shop' => $shop, 'offset' => 0];
                    break;
                case FullmetrixJournal::entity('combination'):
                case FullmetrixJournal::entity('stock'):
                case FullmetrixJournal::entity('combination_deleted'):
                    if ($sub > 0) {
                        $items[] = ['type' => 'variant', 'id' => $id, 'sub' => $sub, 'shop' => $shop, 'offset' => 0];
                    } elseif ($entity === FullmetrixJournal::entity('stock')) {
                        $items[] = ['type' => 'product', 'id' => $id, 'sub' => 0, 'shop' => $shop, 'offset' => 0];
                    } else {
                        $items[] = ['type' => 'product_family', 'id' => $id, 'sub' => 0, 'shop' => $shop, 'offset' => 0];
                    }
                    break;
                case FullmetrixJournal::entity('category'):
                case FullmetrixJournal::entity('category_deleted'):
                    $items[] = ['type' => 'category', 'id' => $id, 'sub' => 0, 'shop' => $shop, 'offset' => 0];
                    break;
                case FullmetrixJournal::entity('coupon'):
                case FullmetrixJournal::entity('coupon_deleted'):
                    $items[] = ['type' => 'coupon', 'id' => $id, 'sub' => 0, 'shop' => $shop, 'offset' => 0];
                    break;
            }
        }
        foreach ($changes['customers_new'] as $idCustomer) {
            $items[] = ['type' => 'customer', 'id' => (int) $idCustomer, 'sub' => 0, 'shop' => 0, 'offset' => 0];
        }

        return $items;
    }

    public static function remainingItems(array $planItems, array $changes, array $alreadyDeferred)
    {
        $seen = [];
        $out = [];
        foreach ($alreadyDeferred as $item) {
            $seen[self::itemKey($item)] = true;
        }
        foreach (array_merge($planItems, self::journalItems($changes)) as $item) {
            $key = self::itemKey($item);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $item;
        }

        return $out;
    }

    private static function itemKey(array $item)
    {
        return $item['type'] . ':' . $item['id'] . ':' . $item['sub'] . ':' . $item['shop'] . ':' . $item['offset'];
    }

    public static function post($secret, $code, $path, $body, $connectMs, $totalMs)
    {
        $result = ['status' => 0, 'json' => null];
        if (!function_exists('curl_init') || strlen($body) > self::MAX_PUSH_BYTES) {
            return $result;
        }
        $signed = FullmetrixSignature::requestHeaders($secret, $code, 'POST', '', $body);
        $headers = array_merge([
            'Content-Type: application/json',
            'X-Fullmetrix-Plugin-Version: ' . FullmetrixHealth::moduleVersion(),
        ], $signed['headers']);
        $responseHeaders = [];
        $ch = curl_init(self::apiBase() . $path);
        if (!$ch) {
            return $result;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_CONNECTTIMEOUT_MS => (int) $connectMs,
            CURLOPT_TIMEOUT_MS => (int) $totalMs,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($handle, $line) use (&$responseHeaders) {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }

                return strlen($line);
            },
        ]);
        $response = @curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($response)) {
            return $result;
        }
        if ($status !== 200 && $status !== 204) {
            $result['status'] = $status;

            return $result;
        }
        $timestamp = isset($responseHeaders['x-fullmetrix-timestamp']) ? $responseHeaders['x-fullmetrix-timestamp'] : null;
        $signature = isset($responseHeaders['x-fullmetrix-body-signature']) ? $responseHeaders['x-fullmetrix-body-signature'] : null;
        if (!FullmetrixSignature::verifyResponse($secret, $signed['nonce'], $timestamp, $signature, $response)) {
            $result['status'] = -1;

            return $result;
        }
        $result['status'] = $status;
        if ($status === 200) {
            $decoded = json_decode($response, true);
            $result['json'] = is_array($decoded) ? $decoded : null;
            if ($result['json'] === null) {
                $result['status'] = -1;
            }
        }

        return $result;
    }
}
