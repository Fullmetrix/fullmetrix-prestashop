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

require_once dirname(__FILE__) . '/FullmetrixJournalStore.php';
require_once dirname(__FILE__) . '/FullmetrixFormatContext.php';
require_once dirname(__FILE__) . '/FullmetrixCartSnapshot.php';
require_once dirname(__FILE__) . '/FullmetrixStreamExporter.php';

class FullmetrixChanges
{
    public const ORDER_OVERLAP = 200;
    public const HISTORY_OVERLAP = 500;
    public const SLIP_OVERLAP = 50;
    public const CUSTOMER_OVERLAP = 200;
    public const CURSOR_LIMIT = 1000;
    public const ORDER_IDS_LIMIT = 3000;
    public const UPDATED_LIMIT = 5000;
    public const CHANGES_BUDGET_S = 3.0;
    public const ENTITIES_BUDGET_S = 10.0;
    public const DIGEST_BUDGET_S = 2.0;
    public const FAMILY_PAGE = 100;
    public const MAX_ITEMS = 100;
    public const MAX_CARTS = 5;
    public const MAX_BUCKETS = 64;
    public const DIGEST_ROW_LIMIT = 5000;
    public const DIGEST_MAX_BUCKET_WIDTH = 256;
    public const DIGEST_STATEMENT_ROWS = 5000;
    public const DIGEST_COLUMNS_TTL_S = 86400;
    public const CFG_DIGEST_COLUMNS = 'FULLMETRIX_DIGEST_COLUMNS';
    public const ERR_UNKNOWN_COLUMN = 1054;
    public const UPDATED_SLICE = 5000;
    public const UPDATED_BUDGET_S = 4.0;
    public const UPDATED_MYISAM_EVERY_S = 1800;
    public const CFG_UPDATED_AT = 'FULLMETRIX_UPDATED_AT';

    private static $flagKeys = ['module', 'journal', 'tracker', 'session', 'recover', 'relay', 'relayBo', 'sessionSignals', 'v1'];
    private static $itemTypes = ['order', 'refund', 'customer', 'product', 'product_family', 'variant', 'category', 'coupon'];
    private static $exporters = [];
    private static $enteredShop;

    public static function obj($value)
    {
        return is_array($value) && !empty($value) ? $value : new stdClass();
    }

    public static function json($data)
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $json = json_encode($data, $flags);
        if ($json === false && defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $json = json_encode($data, $flags | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        if ($json === false) {
            $json = json_encode($data, $flags | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        return is_string($json) ? $json : '{}';
    }

    public static function toIso($value)
    {
        if (!is_string($value) || $value === '' || strpos($value, '0000-00-00') === 0) {
            return null;
        }
        $ts = strtotime($value);

        return $ts === false ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    public static function flags()
    {
        $raw = FullmetrixJournalStore::config(FullmetrixJournal::key('flags'));
        $decoded = $raw !== '' ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    public static function flagsVersion()
    {
        $flags = self::flags();

        return isset($flags['v']) && is_numeric($flags['v']) ? max(0, (int) $flags['v']) : 0;
    }

    public static function flagEnabled($key)
    {
        $flags = self::flags();

        return !array_key_exists($key, $flags) || $flags[$key] !== false;
    }

    public static function sanitizeFlagValues($values)
    {
        $clean = [];
        if (!is_array($values)) {
            return $clean;
        }
        foreach (self::$flagKeys as $key) {
            if (array_key_exists($key, $values) && is_bool($values[$key])) {
                $clean[$key] = $values[$key];
            }
        }
        if (isset($values['hooks']) && is_array($values['hooks'])) {
            $hooks = [];
            foreach ($values['hooks'] as $hook => $enabled) {
                if (is_string($hook) && preg_match('/^[A-Za-z0-9_]{1,64}$/', $hook) && is_bool($enabled)) {
                    $hooks[$hook] = $enabled;
                }
            }
            if (!empty($hooks)) {
                $clean['hooks'] = $hooks;
            }
        }

        return $clean;
    }

    public static function setFlags($version, $values)
    {
        $local = self::flagsVersion();
        if (!is_numeric($version) || (int) $version <= $local) {
            return $local;
        }
        $version = (int) $version;
        $stored = ['v' => $version] + self::sanitizeFlagValues($values);
        if (!FullmetrixJournalStore::setConfig(FullmetrixJournal::key('flags'), self::json($stored))) {
            return $local;
        }
        FullmetrixJournalStore::setConfig(FullmetrixJournal::key('flags_v'), (string) $version);

        return $version;
    }

    public static function breaker()
    {
        $raw = FullmetrixJournalStore::config(FullmetrixJournal::key('breaker'));
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $out = ['open' => new stdClass()];
        if (!is_array($decoded)) {
            return $out;
        }
        $open = [];
        if (isset($decoded['open']) && is_array($decoded['open'])) {
            foreach ($decoded['open'] as $hook => $until) {
                if (is_string($hook) && is_numeric($until)) {
                    $open[$hook] = max(0, (int) $until);
                }
            }
        }
        $out['open'] = self::obj($open);
        if (isset($decoded['trips']) && is_array($decoded['trips'])) {
            $trips = [];
            foreach ($decoded['trips'] as $hook => $times) {
                if (is_string($hook) && is_array($times)) {
                    $list = [];
                    foreach ($times as $time) {
                        if (is_numeric($time)) {
                            $list[] = max(0, (int) $time);
                        }
                    }
                    $trips[$hook] = $list;
                }
            }
            $out['trips'] = self::obj($trips);
        }
        if (isset($decoded['err']) && is_array($decoded['err'])) {
            $err = [];
            foreach ($decoded['err'] as $hook => $code) {
                if (is_string($hook) && is_numeric($code)) {
                    $err[$hook] = (int) $code;
                }
            }
            $out['err'] = self::obj($err);
        }

        return $out;
    }

    public static function autoIncrementStep()
    {
        try {
            $step = (int) FullmetrixJournalStore::value('SELECT @@auto_increment_increment');
        } catch (Exception $e) {
            $step = 1;
        }

        return max(1, $step);
    }

    private static function intList($values, $limit)
    {
        $out = [];
        if (!is_array($values)) {
            return $out;
        }
        foreach ($values as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                $out[(int) $value] = (int) $value;
                if (count($out) >= $limit) {
                    break;
                }
            }
        }

        return array_values($out);
    }

    private static function cursor($cursors, $key)
    {
        return is_array($cursors) && isset($cursors[$key]) && is_numeric($cursors[$key]) ? max(0, (int) $cursors[$key]) : 0;
    }

    public static function normalizeRequest($request)
    {
        $request = is_array($request) ? $request : [];
        $cursors = isset($request['cursors']) ? $request['cursors'] : [];
        $since = isset($request['since_upd']) && is_string($request['since_upd'])
            && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $request['since_upd']) ? $request['since_upd'] : null;
        $maxRows = isset($request['max_rows']) && is_numeric($request['max_rows']) ? (int) $request['max_rows'] : FullmetrixJournalStore::MAX_READ_ROWS;

        return [
            'ack' => isset($request['ack']) && is_array($request['ack']) ? $request['ack'] : [],
            'cursors' => [
                'order' => self::cursor($cursors, 'order'),
                'history' => self::cursor($cursors, 'history'),
                'slip' => self::cursor($cursors, 'slip'),
                'customer' => self::cursor($cursors, 'customer'),
            ],
            'pending_orders' => self::intList(isset($request['pending_orders']) ? $request['pending_orders'] : [], self::ORDER_IDS_LIMIT),
            'recheck_orders' => self::intList(isset($request['recheck_orders']) ? $request['recheck_orders'] : [], self::ORDER_IDS_LIMIT),
            'since_upd' => $since,
            'since_upd_from' => self::updatedFrom(isset($request['since_upd_from']) ? $request['since_upd_from'] : null),
            'max_rows' => max(1, min(FullmetrixJournalStore::MAX_READ_ROWS, $maxRows)),
        ];
    }

    private static function updatedSources()
    {
        return [
            'customer' => ['customer', 'id_customer'],
            'cart_rule' => ['cart_rule', 'id_cart_rule'],
            'category' => ['category', 'id_category'],
            'product' => ['product', 'id_product'],
        ];
    }

    private static function updatedFrom($from)
    {
        $out = [];
        if (!is_array($from)) {
            return $out;
        }
        foreach (array_keys(self::updatedSources()) as $key) {
            if (isset($from[$key]) && is_numeric($from[$key]) && (int) $from[$key] >= 0) {
                $out[$key] = (int) $from[$key];
            }
        }

        return $out;
    }

    public static function changes($request)
    {
        $request = self::normalizeRequest($request);
        $deadline = microtime(true) + self::CHANGES_BUDGET_S;
        FullmetrixJournalStore::ensureLazy();

        $acked = [];
        $journal = [];
        $journalMore = false;
        $heartbeat = false;
        $isolation = null;
        $journalState = FullmetrixJournalStore::state();
        $journalAckAt = FullmetrixJournalStore::ackAt();
        if (FullmetrixJournalStore::isUsable()) {
            $isolation = FullmetrixJournalStore::prepareSession();
            $acked = FullmetrixJournalStore::ack($request['ack'], $deadline);
            try {
                $read = FullmetrixJournalStore::read($request['max_rows']);
                $journal = $read['rows'];
                $journalMore = $read['more'];
                FullmetrixJournalStore::updateSleep($journal);
                $heartbeat = FullmetrixJournalStore::heartbeat();
            } catch (Exception $e) {
                $journal = [];
            }
            $isolation = FullmetrixJournalStore::isolation();
        } else {
            $acked = FullmetrixJournalStore::normalizeKeys($request['ack']);
        }

        $cursorData = self::readCursors($request);
        $orders = self::orderSummaries(array_merge(
            $cursorData['order_ids'],
            $cursorData['history_order_ids'],
            $request['pending_orders'],
            $request['recheck_orders']
        ));

        $updated = $request['since_upd'] !== null ? self::updatedSince($request['since_upd'], $request['since_upd_from']) : null;

        $response = [
            'v' => 1,
            'acked' => $acked,
            'journal' => $journal,
            'journal_more' => $journalMore,
            'orders' => $orders,
            'slips' => $cursorData['slips'],
            'customers_new' => $cursorData['customers'],
            'updated' => $updated === null ? null : $updated['ids'],
            'cursors_out' => $cursorData['cursors_out'],
            'breaker' => self::breaker(),
            'flags_v' => self::flagsVersion(),
            'journal_state' => $journalState,
            'journal_ack_at' => $journalAckAt,
            'isolation' => $isolation,
            'isolation_fallback' => FullmetrixJournalStore::isolationFallback(),
            'heartbeat_written' => $heartbeat,
            'server_time' => time(),
        ];
        if ($updated !== null && !empty($updated['resume'])) {
            $response['updated_resume'] = $updated['resume'];
        }

        return $response;
    }

    public static function readCursors(array $request)
    {
        $p = _DB_PREFIX_;
        $step = self::autoIncrementStep();
        $cursors = $request['cursors'];

        $orderIds = [];
        $maxOrder = $cursors['order'];
        foreach (FullmetrixJournalStore::rows(
            'SELECT `id_order` FROM `' . $p . 'orders` WHERE `id_order` > ' . max(0, $cursors['order'] - self::ORDER_OVERLAP * $step)
            . ' ORDER BY `id_order` LIMIT ' . self::CURSOR_LIMIT
        ) as $row) {
            $orderIds[] = (int) $row['id_order'];
            $maxOrder = max($maxOrder, (int) $row['id_order']);
        }

        $historyOrderIds = [];
        $maxHistory = $cursors['history'];
        foreach (FullmetrixJournalStore::rows(
            'SELECT `id_order_history`, `id_order` FROM `' . $p . 'order_history` WHERE `id_order_history` > '
            . max(0, $cursors['history'] - self::HISTORY_OVERLAP * $step) . ' ORDER BY `id_order_history` LIMIT ' . (self::CURSOR_LIMIT * 2)
        ) as $row) {
            $historyOrderIds[(int) $row['id_order']] = (int) $row['id_order'];
            $maxHistory = max($maxHistory, (int) $row['id_order_history']);
        }

        $slips = [];
        $maxSlip = $cursors['slip'];
        foreach (FullmetrixJournalStore::rows(
            'SELECT `id_order_slip`, `id_order` FROM `' . $p . 'order_slip` WHERE `id_order_slip` > '
            . max(0, $cursors['slip'] - self::SLIP_OVERLAP * $step) . ' ORDER BY `id_order_slip` LIMIT ' . self::CURSOR_LIMIT
        ) as $row) {
            if ((int) $row['id_order'] > 0) {
                $slips[] = ['id' => (int) $row['id_order_slip'], 'id_order' => (int) $row['id_order']];
            }
            $maxSlip = max($maxSlip, (int) $row['id_order_slip']);
        }

        $customers = [];
        $maxCustomer = $cursors['customer'];
        foreach (FullmetrixJournalStore::rows(
            'SELECT `id_customer` FROM `' . $p . 'customer` WHERE `id_customer` > '
            . max(0, $cursors['customer'] - self::CUSTOMER_OVERLAP * $step) . ' ORDER BY `id_customer` LIMIT ' . self::CURSOR_LIMIT
        ) as $row) {
            $customers[] = (int) $row['id_customer'];
            $maxCustomer = max($maxCustomer, (int) $row['id_customer']);
        }

        return [
            'order_ids' => $orderIds,
            'history_order_ids' => array_values($historyOrderIds),
            'slips' => $slips,
            'customers' => $customers,
            'cursors_out' => [
                'order' => $maxOrder,
                'history' => $maxHistory,
                'slip' => $maxSlip,
                'customer' => $maxCustomer,
            ],
        ];
    }

    public static function orderSummaries(array $ids)
    {
        $ids = self::intList($ids, self::ORDER_IDS_LIMIT);
        if (empty($ids)) {
            return [];
        }
        $p = _DB_PREFIX_;
        $rows = FullmetrixJournalStore::rows(
            'SELECT o.`id_order`, o.`id_shop`, o.`id_cart`, o.`current_state`, o.`date_add`, o.`date_upd`,'
            . ' (SELECT MAX(oh.`id_order_history`) FROM `' . $p . 'order_history` oh WHERE oh.`id_order` = o.`id_order`) AS state_seq'
            . ' FROM `' . $p . 'orders` o WHERE o.`id_order` IN (' . implode(',', $ids) . ') ORDER BY o.`id_order`'
        );
        $now = time();
        $out = [];
        foreach ($rows as $row) {
            $dateAdd = self::toIso($row['date_add']);
            $dateUpd = self::toIso($row['date_upd']);
            $addTs = $dateAdd === null ? 0 : (int) strtotime((string) $row['date_add']);
            $stateSeq = $row['state_seq'] === null ? 0 : (int) $row['state_seq'];
            $out[] = [
                'id' => (int) $row['id_order'],
                'id_shop' => (int) $row['id_shop'],
                'id_cart' => (int) $row['id_cart'],
                'current_state' => (int) $row['current_state'],
                'state_seq' => $stateSeq,
                'date_add' => $dateAdd === null ? '1970-01-01T00:00:00Z' : $dateAdd,
                'date_upd' => $dateUpd === null ? ($dateAdd === null ? '1970-01-01T00:00:00Z' : $dateAdd) : $dateUpd,
                'has_history' => $stateSeq > 0,
                'age_s' => $addTs > 0 ? $now - $addTs : 0,
            ];
        }

        return $out;
    }

    public static function updatedSince($since, array $from = [])
    {
        $p = _DB_PREFIX_;
        $sources = self::updatedSources();
        if (empty($from)) {
            $myisam = false;
            foreach ($sources as $source) {
                $engine = FullmetrixJournalStore::engineOf($source[0]);
                if ($engine !== null && strtolower($engine) === 'myisam') {
                    $myisam = true;
                }
            }
            if ($myisam && time() - (int) FullmetrixJournalStore::config(self::CFG_UPDATED_AT) < self::UPDATED_MYISAM_EVERY_S) {
                return null;
            }
            FullmetrixJournalStore::setConfig(self::CFG_UPDATED_AT, (string) time());
        }
        $deadline = microtime(true) + self::UPDATED_BUDGET_S;
        $ids = [];
        $resume = [];
        foreach ($sources as $key => $source) {
            $scan = self::updatedIds($p . $source[0], $source[1], $since, isset($from[$key]) ? $from[$key] : 0, $deadline);
            $ids[$key] = $scan['ids'];
            if ($scan['resume'] !== null) {
                $resume[$key] = $scan['resume'];
            }
        }

        return ['ids' => $ids, 'resume' => $resume];
    }

    private static function updatedIds($table, $key, $since, $from, $deadline)
    {
        $ids = [];
        try {
            $max = (int) FullmetrixJournalStore::value('SELECT MAX(`' . $key . '`) FROM `' . $table . '`');
        } catch (Exception $e) {
            return ['ids' => $ids, 'resume' => null];
        }
        for ($last = $from; $last < $max; $last += self::UPDATED_SLICE) {
            if (microtime(true) > $deadline) {
                return ['ids' => $ids, 'resume' => $last];
            }
            try {
                $rows = FullmetrixJournalStore::rows(
                    'SELECT `' . $key . '` AS id FROM `' . $table . '` WHERE `' . $key . '` > ' . $last . ' AND `' . $key . '` <= '
                    . ($last + self::UPDATED_SLICE) . ' AND `date_upd` > \'' . pSQL($since) . '\' ORDER BY `' . $key . '`'
                );
            } catch (Exception $e) {
                return ['ids' => $ids, 'resume' => null];
            }
            foreach ($rows as $row) {
                if ((int) $row['id'] <= 0) {
                    continue;
                }
                if (count($ids) >= self::UPDATED_LIMIT) {
                    return ['ids' => $ids, 'resume' => $ids[count($ids) - 1]];
                }
                $ids[] = (int) $row['id'];
            }
        }

        return ['ids' => $ids, 'resume' => null];
    }

    public static function normalizeItems($items, $limit)
    {
        $out = [];
        if (!is_array($items)) {
            return $out;
        }
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['type'], $item['id']) || !in_array($item['type'], self::$itemTypes, true)) {
                continue;
            }
            if (!is_numeric($item['id']) || (int) $item['id'] <= 0) {
                continue;
            }
            $out[] = [
                'type' => $item['type'],
                'id' => (int) $item['id'],
                'sub' => isset($item['sub']) && is_numeric($item['sub']) ? max(0, (int) $item['sub']) : 0,
                'shop' => isset($item['shop']) && is_numeric($item['shop']) ? max(0, (int) $item['shop']) : 0,
                'offset' => isset($item['offset']) && is_numeric($item['offset']) ? max(0, (int) $item['offset']) : 0,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    public static function normalizeCarts($carts, $limit)
    {
        $out = [];
        if (!is_array($carts)) {
            return $out;
        }
        foreach ($carts as $cart) {
            if (is_array($cart) && isset($cart['id_cart']) && is_numeric($cart['id_cart']) && (int) $cart['id_cart'] > 0) {
                $out[(int) $cart['id_cart']] = (int) $cart['id_cart'];
                if (count($out) >= $limit) {
                    break;
                }
            }
        }

        return array_values($out);
    }

    private static function exporter($shop)
    {
        $key = (int) $shop;
        if (self::$enteredShop !== $key) {
            FullmetrixFormatContext::enter($key);
            self::$enteredShop = $key;
        }
        $context = FullmetrixFormatContext::context();
        if (!isset(self::$exporters[$key])) {
            $idShop = isset($context->shop->id) ? (int) $context->shop->id : 1;
            self::$exporters[$key] = new FullmetrixStreamExporter($idShop > 0 ? $idShop : 1, $context->link);
        }

        return self::$exporters[$key];
    }

    private static function result(array $item, $type, $sub, $payload, $stateSeq = null)
    {
        $result = ['type' => $type, 'id' => $item['id'], 'sub' => (int) $sub, 'shop' => $item['shop']];
        if ($payload === null) {
            $result['status'] = 'missing';

            return $result;
        }
        $result['status'] = 'ok';
        $result['payload'] = self::obj($payload);
        if ($stateSeq !== null) {
            $result['state_seq'] = (int) $stateSeq;
        }

        return $result;
    }

    private static function errorResult(array $item, $e)
    {
        $code = FullmetrixJournalStore::errorCode($e);

        return [
            'type' => $item['type'],
            'id' => $item['id'],
            'sub' => $item['sub'],
            'shop' => $item['shop'],
            'status' => 'error',
            'error' => substr(get_class($e) . ': ' . $e->getMessage(), 0, 300),
            'code' => $code > 0 ? $code : null,
        ];
    }

    public static function formatItem(array $item, &$deferred)
    {
        $exporter = self::exporter($item['shop']);
        switch ($item['type']) {
            case 'order':
                $payload = $exporter->formatSingleEntity('order', $item['id']);
                $seqs = $payload === null ? [] : $exporter->getStateSeqs([$item['id']]);

                return [self::result($item, 'order', 0, $payload, $payload === null ? null : (isset($seqs[$item['id']]) ? $seqs[$item['id']] : 0))];
            case 'variant':
                if ($item['sub'] <= 0) {
                    return [self::result($item, 'product', 0, $exporter->formatSingleEntity('product', $item['id']))];
                }
                $variants = $exporter->formatProductVariants($item['id'], [$item['sub']]);

                return [self::result($item, 'variant', $item['sub'], isset($variants[$item['sub']]) ? $variants[$item['sub']] : null)];
            case 'product_family':
                return self::formatFamily($exporter, $item, $deferred);
            case 'product':
                if ($item['sub'] > 0) {
                    $variants = $exporter->formatProductVariants($item['id'], [$item['sub']]);

                    return [self::result($item, 'variant', $item['sub'], isset($variants[$item['sub']]) ? $variants[$item['sub']] : null)];
                }

                return [self::result($item, 'product', 0, $exporter->formatSingleEntity('product', $item['id']))];
            default:
                return [self::result($item, $item['type'], $item['sub'], $exporter->formatSingleEntity($item['type'], $item['id']))];
        }
    }

    private static function formatFamily($exporter, array $item, &$deferred)
    {
        $results = [];
        $attributeIds = $exporter->listProductAttributeIds($item['id'], $item['offset'], self::FAMILY_PAGE);
        $family = $exporter->formatProductFamily($item['id'], $attributeIds);
        $product = $family['product'];
        if ($item['offset'] === 0 || $product === null) {
            $results[] = self::result($item, 'product', 0, $product);
        }
        if ($product === null) {
            return $results;
        }
        $variants = $family['variants'];
        foreach ($attributeIds as $attributeId) {
            $results[] = self::result($item, 'variant', $attributeId, isset($variants[$attributeId]) ? $variants[$attributeId] : null);
        }
        $more = count($attributeIds) >= self::FAMILY_PAGE;
        if ($more) {
            $deferred[] = [
                'type' => 'product_family',
                'id' => $item['id'],
                'sub' => 0,
                'shop' => $item['shop'],
                'offset' => $item['offset'] + self::FAMILY_PAGE,
            ];
        }
        $results[] = self::result($item, 'product_family', 0, [
            'offset' => $item['offset'],
            'count' => count($attributeIds),
            'more' => $more,
        ]);

        return $results;
    }

    public static function entities($items, $carts, $deadline, $maxBytes = null)
    {
        $items = self::normalizeItems($items, self::MAX_ITEMS);
        $carts = self::normalizeCarts($carts, self::MAX_CARTS);
        usort($items, ['FullmetrixChanges', 'compareItemShop']);

        $results = [];
        $cartResults = [];
        $deferred = [];
        $bytes = 0;
        foreach ($items as $item) {
            if (microtime(true) > $deadline || ($maxBytes !== null && $bytes >= $maxBytes)) {
                $deferred[] = $item;
                continue;
            }
            try {
                $formatted = self::formatItem($item, $deferred);
            } catch (Exception $e) {
                $formatted = [self::errorResult($item, $e)];
            } catch (Throwable $e) {
                $formatted = [self::errorResult($item, $e)];
            }
            foreach ($formatted as $result) {
                $bytes += strlen(self::json($result));
                $results[] = $result;
            }
        }

        foreach ($carts as $idCart) {
            if (microtime(true) > $deadline || ($maxBytes !== null && $bytes >= $maxBytes)) {
                break;
            }
            $cartResult = self::cartResult($idCart);
            $bytes += strlen(self::json($cartResult));
            $cartResults[] = $cartResult;
        }

        return ['results' => $results, 'cart_results' => $cartResults, 'deferred' => $deferred, 'bytes' => $bytes];
    }

    public static function compareItemShop($a, $b)
    {
        return $a['shop'] === $b['shop'] ? 0 : ($a['shop'] < $b['shop'] ? -1 : 1);
    }

    public static function cartResult($idCart)
    {
        self::$enteredShop = null;
        try {
            $row = FullmetrixCartSnapshot::fingerprintRow($idCart);
            $fp = FullmetrixCartSnapshot::fingerprint($row, $row === null ? 0 : (int) $row['id_customer'], false);
            $built = FullmetrixCartSnapshot::build($idCart, false, $fp);
            if ($built['status'] === 'missing') {
                return ['id_cart' => (int) $idCart, 'status' => 'missing'];
            }

            return ['id_cart' => (int) $idCart, 'status' => 'ok', 'payload' => $built['payload']];
        } catch (Exception $e) {
            $error = $e;
        } catch (Throwable $e) {
            $error = $e;
        }
        $code = FullmetrixJournalStore::errorCode($error);

        return [
            'id_cart' => (int) $idCart,
            'status' => 'error',
            'error' => substr(get_class($error) . ': ' . $error->getMessage(), 0, 300),
            'code' => $code > 0 ? $code : null,
        ];
    }

    public static function entitiesResponse($request)
    {
        $request = is_array($request) ? $request : [];
        FullmetrixFormatContext::lockCookie();
        $formatted = self::entities(
            isset($request['items']) ? $request['items'] : [],
            isset($request['carts']) ? $request['carts'] : [],
            microtime(true) + self::ENTITIES_BUDGET_S
        );

        return [
            'v' => 1,
            'results' => $formatted['results'],
            'cart_results' => $formatted['cart_results'],
            'deferred' => $formatted['deferred'],
            'server_time' => time(),
        ];
    }

    private static function digestSpec($table)
    {
        $p = _DB_PREFIX_;
        switch ($table) {
            case 'stock':
                return [
                    'from' => '`' . $p . 'stock_available` t',
                    'range' => 't.`id_product`',
                    'where' => '',
                    'order' => 't.`id_product`, t.`id_product_attribute`, t.`id_shop`, t.`id_shop_group`',
                    'columns' => [
                        ['stock_available', 't', 'id_product'],
                        ['stock_available', 't', 'id_product_attribute'],
                        ['stock_available', 't', 'id_shop'],
                        ['stock_available', 't', 'quantity'],
                        ['stock_available', 't', 'out_of_stock'],
                    ],
                ];
            case 'specific_price':
                $columns = [];
                foreach (['id_specific_price', 'id_product', 'id_product_attribute', 'id_shop', 'id_shop_group', 'id_currency', 'id_country', 'id_group', 'id_customer', 'price', 'from_quantity', 'reduction', 'reduction_tax', 'reduction_type', 'from', 'to'] as $column) {
                    $columns[] = ['specific_price', 't', $column];
                }

                return [
                    'from' => '`' . $p . 'specific_price` t',
                    'range' => 't.`id_product`',
                    'where' => ' AND t.`id_cart` = 0',
                    'order' => 't.`id_product`, t.`id_specific_price`',
                    'columns' => $columns,
                ];
            case 'combination':
                return [
                    'from' => '`' . $p . 'product_attribute` t INNER JOIN `' . $p . 'product_attribute_shop` s ON (s.`id_product_attribute` = t.`id_product_attribute`)',
                    'range' => 't.`id_product`',
                    'where' => '',
                    'order' => 't.`id_product`, t.`id_product_attribute`, s.`id_shop`',
                    'columns' => [
                        ['product_attribute', 't', 'id_product'],
                        ['product_attribute', 't', 'id_product_attribute'],
                        ['product_attribute_shop', 's', 'id_shop'],
                        ['product_attribute', 't', 'reference'],
                        ['product_attribute', 't', 'ean13'],
                        ['product_attribute', 't', 'upc'],
                        ['product_attribute_shop', 's', 'price'],
                        ['product_attribute_shop', 's', 'wholesale_price'],
                        ['product_attribute_shop', 's', 'weight'],
                        ['product_attribute_shop', 's', 'default_on'],
                    ],
                ];
        }

        return null;
    }

    private static function existingColumns($table)
    {
        $names = [];
        foreach (FullmetrixJournalStore::rows('SHOW COLUMNS FROM `' . _DB_PREFIX_ . $table . '`') as $row) {
            if (isset($row['Field'])) {
                $names[(string) $row['Field']] = true;
            }
        }

        return $names;
    }

    public static function digestColumns($spec)
    {
        $cached = self::cachedColumns();
        $known = [];
        $selected = [];
        $refreshed = false;
        foreach ($spec['columns'] as $column) {
            if (!isset($known[$column[0]])) {
                if (isset($cached[$column[0]]) && is_array($cached[$column[0]])) {
                    $known[$column[0]] = array_fill_keys($cached[$column[0]], true);
                } else {
                    $known[$column[0]] = self::existingColumns($column[0]);
                    $cached[$column[0]] = array_keys($known[$column[0]]);
                    $refreshed = true;
                }
            }
            if (isset($known[$column[0]][$column[2]])) {
                $selected[] = $column;
            }
        }
        if ($refreshed) {
            self::storeColumns($cached);
        }

        return $selected;
    }

    private static function cachedColumns()
    {
        $raw = FullmetrixJournalStore::config(self::CFG_DIGEST_COLUMNS);
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded) || !isset($decoded['at'], $decoded['tables']) || !is_array($decoded['tables'])
            || time() - (int) $decoded['at'] > self::DIGEST_COLUMNS_TTL_S) {
            return [];
        }

        return $decoded['tables'];
    }

    private static function storeColumns(array $tables)
    {
        $raw = FullmetrixJournalStore::config(self::CFG_DIGEST_COLUMNS);
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $at = is_array($decoded) && isset($decoded['at']) && time() - (int) $decoded['at'] <= self::DIGEST_COLUMNS_TTL_S
            ? (int) $decoded['at'] : time();
        FullmetrixJournalStore::setConfig(self::CFG_DIGEST_COLUMNS, self::json(['at' => $at, 'tables' => $tables]));
    }

    private static function digestHashBucket(array $spec, array $hashParts, array $bucket, $deadline)
    {
        $count = 0;
        $xor = 0;
        $pending = [$bucket];
        while (!empty($pending)) {
            if (microtime(true) > $deadline) {
                return null;
            }
            $range = array_pop($pending);
            $where = ' WHERE ' . $spec['range'] . ' BETWEEN ' . $range[0] . ' AND ' . $range[1] . $spec['where'];
            if ($range[0] < $range[1]) {
                $size = (int) FullmetrixJournalStore::value('SELECT COUNT(*) FROM ' . $spec['from'] . $where);
                if ($size > self::DIGEST_STATEMENT_ROWS) {
                    $middle = $range[0] + (int) floor(($range[1] - $range[0]) / 2);
                    $pending[] = [$middle + 1, $range[1]];
                    $pending[] = [$range[0], $middle];
                    continue;
                }
                if ($size === 0) {
                    continue;
                }
            }
            $row = FullmetrixJournalStore::rows(
                'SELECT COUNT(*) AS c, BIT_XOR(CRC32(CONCAT_WS(\'|\', ' . implode(', ', $hashParts) . '))) AS x FROM ' . $spec['from'] . $where
            );
            $count += isset($row[0]['c']) ? (int) $row[0]['c'] : 0;
            $xor ^= isset($row[0]['x']) ? ((int) $row[0]['x']) & 0xFFFFFFFF : 0;
        }

        return ['range' => $bucket, 'count' => $count, 'xor' => sprintf('%08x', $xor)];
    }

    public static function digest($request)
    {
        try {
            return self::digestOnce($request);
        } catch (Exception $e) {
            if (FullmetrixJournalStore::errorCode($e) !== self::ERR_UNKNOWN_COLUMN) {
                throw $e;
            }
            FullmetrixJournalStore::setConfig(self::CFG_DIGEST_COLUMNS, '');

            return self::digestOnce($request);
        }
    }

    private static function digestOnce($request)
    {
        $request = is_array($request) ? $request : [];
        $table = isset($request['table']) && is_string($request['table']) ? $request['table'] : '';
        $mode = isset($request['mode']) && $request['mode'] === 'rows' ? 'rows' : 'hash';
        $spec = self::digestSpec($table);
        if ($spec === null) {
            return null;
        }
        $buckets = [];
        if (isset($request['buckets']) && is_array($request['buckets'])) {
            foreach ($request['buckets'] as $bucket) {
                if (is_array($bucket) && count($bucket) === 2 && is_numeric($bucket[0]) && is_numeric($bucket[1])
                    && (int) $bucket[0] >= 0 && (int) $bucket[1] >= (int) $bucket[0]
                    && (int) $bucket[1] - (int) $bucket[0] < self::DIGEST_MAX_BUCKET_WIDTH) {
                    $buckets[] = [(int) $bucket[0], (int) $bucket[1]];
                }
                if (count($buckets) >= self::MAX_BUCKETS) {
                    break;
                }
            }
        }
        $startedAt = time();
        $deadline = microtime(true) + self::DIGEST_BUDGET_S;
        $columns = self::digestColumns($spec);
        $names = [];
        $selects = [];
        $hashParts = [];
        foreach ($columns as $column) {
            $expression = $column[1] . '.`' . $column[2] . '`';
            $names[] = $column[2];
            $selects[] = $expression . ' AS `' . $column[2] . '`';
            $hashParts[] = 'IFNULL(CAST(' . $expression . ' AS CHAR), \'\\\\N\')';
        }

        if ($mode === 'hash') {
            $out = [];
            foreach ($buckets as $bucket) {
                $hashed = self::digestHashBucket($spec, $hashParts, $bucket, $deadline);
                if ($hashed === null) {
                    break;
                }
                $out[] = $hashed;
            }

            return ['v' => 1, 'started_at' => $startedAt, 'table' => $table, 'buckets' => $out];
        }

        $rows = [];
        foreach ($buckets as $bucket) {
            $remaining = self::DIGEST_ROW_LIMIT - count($rows);
            if ($remaining <= 0 || microtime(true) > $deadline) {
                break;
            }
            foreach (FullmetrixJournalStore::rows(
                'SELECT ' . implode(', ', $selects) . ' FROM ' . $spec['from'] . ' WHERE ' . $spec['range'] . ' BETWEEN '
                . $bucket[0] . ' AND ' . $bucket[1] . $spec['where'] . ' ORDER BY ' . $spec['order'] . ' LIMIT ' . $remaining
            ) as $row) {
                $values = [];
                foreach ($names as $name) {
                    $value = $row[$name];
                    if (is_string($value) && preg_match('/^-?[1-9]\d{0,17}$|^0$/', $value)) {
                        $value = (int) $value;
                    } elseif (!is_string($value) && !is_int($value) && $value !== null) {
                        $value = (string) $value;
                    }
                    $values[] = $value;
                }
                $rows[] = $values;
            }
        }

        return ['v' => 1, 'started_at' => $startedAt, 'table' => $table, 'columns' => $names, 'rows' => $rows];
    }
}
