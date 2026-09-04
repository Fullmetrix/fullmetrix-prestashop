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

class FullmetrixStreamExporter
{
    private $db;
    private $prefix;
    private $idLang;
    private $idShop;
    private $batchSize = 1000;
    private $memoryLimitBytes;
    private $link;
    private $cartRuleColumnsCache;
    private $shopContextCache = [];

    const META_VALUE_MAX_LENGTH = 20000;

    const META_TOTAL_MAX_LENGTH = 65536;

    const META_SHORT_VALUE_LENGTH = 512;

    // The reporting engine cannot address a key longer than this, so a longer
    // one would be paid for in every payload and never usable.
    const META_KEY_MAX_LENGTH = 80;

    private static $sensitiveColumns = [
        'passwd', 'secure_key', 'last_passwd_gen',
        'reset_password_token', 'reset_password_validity',
    ];

    private static $orderMappedColumns = [
        'id_order', 'reference', 'id_customer', 'id_currency',
        'id_shop', 'id_shop_group', 'id_address_delivery', 'id_address_invoice',
        'current_state', 'module', 'payment', 'note',
        'payment_method', 'payment_method_title',
        'total_paid_tax_incl', 'total_paid_tax_excl',
        'total_discounts_tax_incl', 'total_shipping_tax_incl',
        'conversion_rate', 'date_add', 'date_upd',
        'status_name', 'customer_email', 'id_default_group', 'currency_code',
    ];

    private static $customerMappedColumns = [
        'id_customer', 'email', 'firstname', 'lastname', 'company',
        'birthday', 'newsletter', 'id_gender', 'gender_name',
        'id_default_group', 'id_shop', 'id_shop_group',
        'date_add', 'date_upd', 'deleted',
    ];

    private static $addressMappedColumns = [
        'id_address', 'id_customer', 'firstname', 'lastname', 'company',
        'address1', 'address2', 'city', 'postcode',
        'phone', 'phone_mobile', 'country', 'state', 'state_code',
        'id_country', 'id_state', 'deleted',
        // Constant on a customer address, and repeated on every order payload.
        'date_add', 'date_upd', 'active',
        'id_manufacturer', 'id_supplier', 'id_warehouse',
    ];

    private static $carrierMappedColumns = [
        'id_order', 'id_order_carrier', 'id_carrier',
        'tracking_number', 'shipping_cost_tax_incl', 'carrier_name',
    ];

    private static $paymentMappedColumns = [
        'order_reference', 'payment_method', 'amount', 'transaction_id',
    ];

    private static $refundMappedColumns = [
        'id_order_slip', 'id_order', 'id_customer',
        'total_products_tax_incl', 'total_shipping_tax_incl',
        'amount', 'shipping_cost_amount', 'date_add', 'date_upd',
        'id_shop', 'id_shop_group',
    ];

    private static $lineItemMappedColumns = [
        'id_order', 'id_order_detail', 'product_name',
        'product_quantity', 'product_reference',
        'product_id', 'product_attribute_id',
        'unit_price_tax_excl', 'unit_price_tax_incl',
        'total_price_tax_excl', 'total_price_tax_incl',
        'reduction_percent', 'reduction_amount_tax_incl',
        'tax_name', 'tax_rate', 'product_ean13', 'product_upc',
    ];

    private static $productMappedColumns = [
        'id_product', 'reference', 'price', 'wholesale_price', 'active',
        'weight', 'ean13', 'upc', 'isbn', 'condition',
        'id_manufacturer', 'id_supplier', 'supplier_reference',
        'date_add', 'date_upd',
        'name', 'description', 'description_short', 'link_rewrite',
        'stock_quantity', 'manufacturer_name', 'supplier_name',
    ];

    private static $categoryMappedColumns = [
        'id_category', 'id_parent', 'name', 'description', 'link_rewrite',
        'date_add', 'date_upd', 'product_count',
    ];

    private static $couponMappedColumns = [
        'id_cart_rule', 'code', 'description', 'name',
        'reduction_percent', 'reduction_amount', 'reduction_currency',
        'free_shipping', 'active', 'quantity', 'quantity_per_user',
        'minimum_amount', 'minimum_amount_currency',
        'date_from', 'date_to', 'date_add', 'date_upd', 'usage_count',
    ];

    /**
     * @param int $idShop Shop ID
     * @param Link|null $link PrestaShop Link instance for URL generation
     */
    public function __construct($idShop = 1, $link = null)
    {
        $this->db = Db::getInstance(_PS_USE_SQL_SLAVE_);
        $this->prefix = _DB_PREFIX_;
        $this->idLang = (int) Configuration::get('PS_LANG_DEFAULT') ?: 1;
        $this->idShop = (int) $idShop;
        $this->link = $link ?: new Link();
    }

    /**
     * Convert MySQL datetime (Y-m-d H:i:s) to ISO 8601 without costly strtotime().
     * ~10x faster than gmdate('c', strtotime($date)) for high-volume loops.
     */
    private function toIso($mysqlDate)
    {
        if ($mysqlDate === null || $mysqlDate === '' || $mysqlDate === false) {
            return null;
        }
        $d = trim((string) $mysqlDate);

        if ($d === '' || $d === '0000-00-00 00:00:00' || $d === '0000-00-00' || strlen($d) < 10) {
            return null;
        }

        $ts = strtotime($d);
        if ($ts === false) {
            return null;
        }

        return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    public function streamEntity($entity, $syncType = 'full', $since = null)
    {
        $this->setupStream();

        $this->sendLine([
            'type' => 'meta',
            'entity' => $entity,
            'started_at' => gmdate('c'),
            'mode' => 'fast_stream',
            'version' => FullmetrixConnector::FULLMETRIX_VERSION,
            'store_url' => $this->getStoreUrl(),
            'php_version' => PHP_VERSION,
            'ps_version' => _PS_VERSION_,
            'memory_limit' => ini_get('memory_limit'),
            'batch_size' => $this->batchSize,
            'shop' => $this->getShopContext(),
        ]);

        $count = 0;

        try {
            switch ($entity) {
                case 'orders':
                    $count = $this->streamOrdersFast($syncType, $since);
                    break;
                case 'refunds':
                    $count = $this->streamRefundsFast($syncType, $since);
                    break;
                case 'customers':
                    $count = $this->streamCustomersFast($syncType, $since);
                    break;
                case 'products':
                    $count = $this->streamProductsFast($syncType, $since);
                    break;
                case 'categories':
                    $count = $this->streamCategoriesFast($syncType, $since);
                    break;
                case 'coupons':
                    $count = $this->streamCouponsFast($syncType, $since);
                    break;
            }
        } catch (Throwable $e) {
            if (!class_exists('FullmetrixLogger')) {
                require_once _PS_MODULE_DIR_ . 'fullmetrixconnector/classes/FullmetrixLogger.php';
            }
            FullmetrixLogger::logException('stream_entity_' . $entity, $e);
            $this->sendLine([
                'type' => 'error',
                'message' => 'An error occurred while streaming ' . $entity . '.',
            ]);
        }

        $this->sendLine([
            'type' => 'done',
            'completed_at' => gmdate('c'),
            'count' => $count,
        ]);

        $this->recordSyncCompletion([$entity => $count]);

        $this->finishStream();
        exit;
    }

    public function streamAll($syncType = 'full', $since = null)
    {
        $this->setupStream();

        $this->sendLine([
            'type' => 'meta',
            'started_at' => gmdate('c'),
            'store_url' => $this->getStoreUrl(),
            'mode' => 'fast_stream',
            'version' => FullmetrixConnector::FULLMETRIX_VERSION,
            'php_version' => PHP_VERSION,
            'ps_version' => _PS_VERSION_,
            'memory_limit' => ini_get('memory_limit'),
            'batch_size' => $this->batchSize,
            'shop' => $this->getShopContext(),
        ]);

        $counts = [
            'orders' => $this->streamOrdersFast($syncType, $since),
            'refunds' => $this->streamRefundsFast($syncType, $since),
            'customers' => $this->streamCustomersFast($syncType, $since),
            'products' => $this->streamProductsFast($syncType, $since),
            'categories' => $this->streamCategoriesFast($syncType, $since),
            'coupons' => $this->streamCouponsFast($syncType, $since),
        ];

        $this->sendLine([
            'type' => 'done',
            'completed_at' => gmdate('c'),
            'counts' => $counts,
        ]);

        $this->recordSyncCompletion($counts);

        $this->finishStream();
        exit;
    }

    public function streamOrdersOnly($syncType = 'full', $since = null)
    {
        $this->setupStream();

        $this->sendLine([
            'type' => 'meta',
            'entity' => 'orders',
            'started_at' => gmdate('c'),
            'mode' => 'fast_stream',
            'version' => FullmetrixConnector::FULLMETRIX_VERSION,
            'store_url' => $this->getStoreUrl(),
            'php_version' => PHP_VERSION,
            'ps_version' => _PS_VERSION_,
            'memory_limit' => ini_get('memory_limit'),
            'batch_size' => $this->batchSize,
            'shop' => $this->getShopContext(),
        ]);

        $count = $this->streamOrdersFast($syncType, $since);

        $this->sendLine([
            'type' => 'done',
            'completed_at' => gmdate('c'),
            'count' => $count,
        ]);

        $this->recordSyncCompletion(['orders' => $count]);

        $this->finishStream();
        exit;
    }

    private function recordSyncCompletion(array $counts)
    {
        foreach ($counts as $entity => $count) {
            $key = 'FULLMETRIX_SYNC_' . Tools::strtoupper($entity);
            Configuration::updateValue($key, json_encode([
                'c' => (int) $count,
                't' => time(),
            ]));
        }

        Configuration::updateValue('FULLMETRIX_SYNC_IN_PROGRESS', '');
    }

    private function setupStream()
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1G');
        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', 'off');

        $this->memoryLimitBytes = $this->parseMemoryLimit(ini_get('memory_limit'));

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/x-ndjson');
        header('X-Accel-Buffering: no');
        header('Cache-Control: no-cache');
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        // Let the web server (Apache/nginx) handle gzip via mod_deflate —
        // doing it in PHP with ob_gzhandler breaks progressive streaming
    }

    private function finishStream()
    {
        // No-op — kept for forward compatibility
    }

    /**
     * Turn every column that is not already exposed as a top-level field into
     * meta_data, so module-added and shop-specific columns reach the platform
     * without having to be whitelisted here first.
     */
    private function extraColumnsMeta($row, array $mappedColumns)
    {
        return $this->extraColumnsMetaGroups([[$row, $mappedColumns, '']]);
    }

    /**
     * Same as extraColumnsMeta over several rows at once, each with its own key
     * prefix. Keys stay flat: the reporting engine only addresses key/value
     * arrays sitting at the root of the payload, so a nested meta_data would be
     * catalogued and never resolve.
     *
     * @param array $groups list of [row, mappedColumns, keyPrefix]
     */
    private function extraColumnsMetaGroups(array $groups)
    {
        $short = [];
        $long = [];
        foreach ($groups as $group) {
            list($row, $mappedColumns, $prefix) = $group;
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $key => $value) {
                if (in_array($key, $mappedColumns, true) || in_array($key, self::$sensitiveColumns, true)) {
                    continue;
                }
                if ($value === null || $value === '' || !is_scalar($value)) {
                    continue;
                }
                $text = (string) $value;
                if ($text === '0000-00-00' || $text === '0000-00-00 00:00:00') {
                    continue;
                }
                // A module column can hold binary or badly encoded bytes. Such a
                // value would make json_encode fail and take the whole entity
                // down with it.
                if (preg_match('//u', $text) !== 1) {
                    continue;
                }
                $prefixedKey = $this->truncateUtf8($prefix . $key, self::META_KEY_MAX_LENGTH);
                if (strlen($text) <= self::META_SHORT_VALUE_LENGTH) {
                    $short[] = ['key' => $prefixedKey, 'value' => $text];
                    continue;
                }
                $long[] = ['key' => $prefixedKey, 'value' => $this->truncateUtf8($text, self::META_VALUE_MAX_LENGTH)];
            }
        }

        // Short values are emitted first so a single bulky text column can never
        // push an identifier-sized field out of the payload.
        $meta = $short;
        $budget = self::META_TOTAL_MAX_LENGTH;
        foreach ($short as $item) {
            $budget -= strlen($item['value']);
        }
        foreach ($long as $item) {
            if ($budget <= 0) {
                break;
            }
            $value = strlen($item['value']) > $budget
                ? $this->truncateUtf8($item['value'], $budget)
                : $item['value'];
            $meta[] = ['key' => $item['key'], 'value' => $value];
            $budget -= strlen($item['value']);
        }

        return $meta;
    }

    /**
     * Cut on a character boundary. A byte-level cut splits a multibyte
     * character in two, which is enough to make json_encode reject the payload.
     */
    private function truncateUtf8($text, $maxBytes)
    {
        if ($maxBytes <= 0) {
            return '';
        }
        if (strlen($text) <= $maxBytes) {
            return $text;
        }
        if (function_exists('mb_strcut')) {
            return mb_strcut($text, 0, $maxBytes, 'UTF-8');
        }

        $cut = substr($text, 0, $maxBytes);
        while ($cut !== '' && preg_match('//u', $cut) !== 1) {
            $cut = substr($cut, 0, -1);
        }

        return $cut;
    }

    private function mergeMeta(array $metaData, array $extras)
    {
        $seen = [];
        foreach ($metaData as $item) {
            $seen[$item['key']] = true;
        }
        foreach ($extras as $item) {
            if (isset($seen[$item['key']])) {
                continue;
            }
            $metaData[] = $item;
        }

        return $metaData;
    }

    private function streamOrdersFast($syncType, $since)
    {
        $count = 0;
        $lastId = 0;

        $sinceWhere = '';
        if ($syncType === 'incremental' && $since) {
            $sinceWhere = ' AND o.date_upd > \'' . pSQL($since) . '\'';
        }

        while (true) {
            $sql = 'SELECT o.*,
                       o.module AS payment_method,
                       o.payment AS payment_method_title,
                       osl.name AS status_name,
                       c.email AS customer_email, c.id_default_group,
                       cur.iso_code AS currency_code
                FROM ' . $this->prefix . 'orders o
                LEFT JOIN ' . $this->prefix . 'order_state_lang osl
                    ON (o.current_state = osl.id_order_state AND osl.id_lang = ' . $this->idLang . ')
                LEFT JOIN ' . $this->prefix . 'customer c ON (o.id_customer = c.id_customer)
                LEFT JOIN ' . $this->prefix . 'currency cur ON (o.id_currency = cur.id_currency)
                WHERE o.id_order > ' . (int) $lastId . $sinceWhere . '
                ORDER BY o.id_order ASC
                LIMIT ' . $this->batchSize;

            $rows = $this->safeQuery($sql, 'orders');

            if ($rows === false) {
                $lastId = $this->findNextId('orders', 'id_order', $lastId, $sinceWhere);
                continue;
            }
            if (empty($rows)) {
                break;
            }

            // Collect IDs for batch queries
            $orderIds = [];
            $addressIds = [];
            $references = [];
            $customerIds = [];
            foreach ($rows as $row) {
                $oid = (int) $row['id_order'];
                $orderIds[] = $oid;
                $addressIds[] = (int) $row['id_address_invoice'];
                $addressIds[] = (int) $row['id_address_delivery'];
                $references[] = pSQL($row['reference']);
                if (!empty($row['id_customer'])) {
                    $customerIds[] = (int) $row['id_customer'];
                }
            }
            $orderIdsList = implode(',', $orderIds);
            $addressIds = array_unique(array_filter($addressIds));
            $customerIds = array_unique(array_filter($customerIds));

            // Batch: addresses
            $addressMap = $this->batchLoadAddresses($addressIds);

            // Batch: line items
            $lineItemsMap = $this->batchLoadOrderLineItems($orderIdsList);

            // Batch: carriers (shipping_lines)
            $carriersMap = $this->batchLoadOrderCarriers($orderIdsList);

            // Batch: cart rules (coupon_lines)
            $couponMap = $this->batchLoadOrderCartRules($orderIdsList);

            // Batch: payments
            $paymentMap = $this->batchLoadOrderPayments($references);

            // Batch: customer groups
            $customerGroupsMap = $this->batchLoadCustomerGroups(implode(',', $customerIds));

            foreach ($rows as $row) {
                $oid = (int) $row['id_order'];
                $totalTaxIncl = (float) $row['total_paid_tax_incl'];
                $totalTaxExcl = (float) $row['total_paid_tax_excl'];
                $tax = max(0, $totalTaxIncl - $totalTaxExcl);

                $billingAddr = $addressMap[(int) $row['id_address_invoice']] ?? null;
                $shippingAddr = $addressMap[(int) $row['id_address_delivery']] ?? null;

                $billing = $this->formatAddress($billingAddr);
                $shipping = $this->formatAddress($shippingAddr);

                // Add email to billing from customer
                $billing['email'] = (string) ($row['customer_email'] ?? '');
                $shop = $this->getShopContext((int) ($row['id_shop'] ?? $this->idShop), (int) ($row['id_shop_group'] ?? 0));
                $customerGroups = $this->formatCustomerGroups(
                    (int) $row['id_customer'],
                    (int) ($row['id_default_group'] ?? 0),
                    $customerGroupsMap
                );

                $this->sendLine([
                    'type' => 'order',
                    'data' => [
                        'id' => $oid,
                        'shop' => $shop,
                        'customer_groups' => $customerGroups,
                        'number' => (string) ($row['reference'] ?: $oid),
                        'status' => (string) ($row['status_name'] ?: 'unknown'),
                        'currency' => (string) ($row['currency_code'] ?: 'EUR'),
                        'conversion_rate' => (string) (isset($row['conversion_rate']) ? (float) $row['conversion_rate'] : 1),
                        'total' => (string) round($totalTaxIncl, 2),
                        'discount_total' => (string) round((float) $row['total_discounts_tax_incl'], 2),
                        'shipping_total' => (string) round((float) $row['total_shipping_tax_incl'], 2),
                        'total_tax' => (string) round($tax, 2),
                        'date_created' => $this->toIso($row['date_add']),
                        'date_modified' => $this->toIso($row['date_upd']),
                        'date_paid' => null,
                        'payment_method' => (string) ($row['payment_method'] ?? ''),
                        'payment_method_title' => (string) ($row['payment_method_title'] ?? ''),
                        'customer_id' => (int) $row['id_customer'],
                        'customer_note' => (string) ($row['note'] ?? ''),
                        'billing' => $billing,
                        'shipping' => $shipping,
                        'line_items' => $lineItemsMap[$oid] ?? [],
                        'shipping_lines' => $carriersMap[$oid] ?? [],
                        'fee_lines' => [],
                        'coupon_lines' => $couponMap[$oid] ?? [],
                        'tax_lines' => [],
                        'payments' => $paymentMap[$row['reference']] ?? [],
                        'meta_data' => $this->extraColumnsMetaGroups([
                            [$row, self::$orderMappedColumns, ''],
                            [$billingAddr, self::$addressMappedColumns, 'billing_'],
                            [$shippingAddr, self::$addressMappedColumns, 'shipping_'],
                        ]),
                    ],
                ]);

                ++$count;
            }

            $lastId = (int) end($rows)['id_order'];
            unset($rows, $addressMap, $lineItemsMap, $carriersMap, $couponMap, $paymentMap, $customerGroupsMap);
            $this->adaptBatchSize();
            $this->maybeGc();
        }

        $this->sendLine([
            'type' => 'entity_complete',
            'entity' => 'orders',
            'count' => $count,
        ]);

        return $count;
    }

    private function batchLoadAddresses($addressIds)
    {
        if (empty($addressIds)) {
            return [];
        }

        $idsList = implode(',', array_map('intval', $addressIds));

        $sql = 'SELECT a.*,
                   co.iso_code AS country, s.name AS state, s.iso_code AS state_code
            FROM ' . $this->prefix . 'address a
            LEFT JOIN ' . $this->prefix . 'country co ON a.id_country = co.id_country
            LEFT JOIN ' . $this->prefix . 'state s ON a.id_state = s.id_state
            WHERE a.id_address IN (' . $idsList . ')';

        $rows = $this->safeQuery($sql, 'order_addresses');
        $map = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $map[(int) $row['id_address']] = $row;
            }
        }

        return $map;
    }

    private function formatAddress($addr)
    {
        if (!$addr) {
            return [
                'first_name' => '', 'last_name' => '', 'company' => '',
                'address_1' => '', 'address_2' => '', 'city' => '',
                'state' => '', 'postcode' => '', 'country' => '',
                'email' => '', 'phone' => '',
            ];
        }

        return [
            'first_name' => (string) ($addr['firstname'] ?? ''),
            'last_name' => (string) ($addr['lastname'] ?? ''),
            'company' => (string) ($addr['company'] ?? ''),
            'address_1' => (string) ($addr['address1'] ?? ''),
            'address_2' => (string) ($addr['address2'] ?? ''),
            'city' => (string) ($addr['city'] ?? ''),
            'state' => (string) ($addr['state_code'] ?? $addr['state'] ?? ''),
            'postcode' => (string) ($addr['postcode'] ?? ''),
            'country' => (string) ($addr['country'] ?? ''),
            'email' => '',
            'phone' => (string) ($addr['phone'] ?: ($addr['phone_mobile'] ?? '')),
        ];
    }

    private function batchLoadOrderLineItems($orderIdsList)
    {
        $sql = 'SELECT od.*
            FROM ' . $this->prefix . 'order_detail od
            WHERE od.id_order IN (' . $orderIdsList . ')';

        $rows = $this->safeQuery($sql, 'order_line_items');
        $customizationIds = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $cuId = (int) ($row['id_customization'] ?? 0);
                if ($cuId > 0) {
                    $customizationIds[$cuId] = true;
                }
            }
        }
        $customizationMap = $this->batchLoadCustomizations(array_keys($customizationIds));
        $map = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $oid = (int) $row['id_order'];
                $qty = min(max(1, (int) $row['product_quantity']), 2147483647);
                $pid = (int) $row['product_id'];
                $aid = (int) $row['product_attribute_id'];
                $price = (float) $row['unit_price_tax_excl'];
                $displayPrice = (float) $row['unit_price_tax_incl'];
                $total = (float) $row['total_price_tax_incl'];
                $subtotalTax = max(0, ((float) $row['unit_price_tax_incl'] - (float) $row['unit_price_tax_excl']) * $qty);
                $totalTax = max(0, (float) $row['total_price_tax_incl'] - (float) $row['total_price_tax_excl']);

                $map[$oid][] = [
                    'id' => (int) $row['id_order_detail'],
                    'name' => (string) $row['product_name'],
                    'product_id' => $pid ?: null,
                    'variation_id' => $aid > 0 ? $pid . '_' . $aid : null,
                    'quantity' => $qty,
                    'price' => (string) round($price, 2),
                    'display_price' => (string) round($displayPrice, 2),
                    'subtotal' => (string) round($price * $qty, 2),
                    'subtotal_tax' => (string) round($subtotalTax, 2),
                    'total' => (string) round($total, 2),
                    'total_tax' => (string) round($totalTax, 2),
                    'sku' => (string) ($row['product_reference'] ?? ''),
                    'tax_name' => (string) ($row['tax_name'] ?? ''),
                    'tax_rate' => (string) ($row['tax_rate'] ?? '0'),
                    'ean13' => (string) ($row['product_ean13'] ?? ''),
                    'upc' => (string) ($row['product_upc'] ?? ''),
                    'reduction_percent' => (string) ($row['reduction_percent'] ?? '0'),
                    'reduction_amount' => (string) ($row['reduction_amount_tax_incl'] ?? '0'),
                    'meta_data' => $this->extraColumnsMetaGroups(array_merge(
                        [[$row, self::$lineItemMappedColumns, '']],
                        $customizationMap[(int) $row['id_customization']] ?? []
                    )),
                ];
            }
        }

        return $map;
    }

    /**
     * Text and file fields filled in by the customer on the product page. They
     * live in their own tables, keyed by the label the merchant configured.
     */
    private function batchLoadCustomizations(array $customizationIds)
    {
        if (empty($customizationIds)) {
            return [];
        }

        $idsList = implode(',', array_map('intval', $customizationIds));
        $sql = 'SELECT cd.id_customization, cd.type, cd.index, cd.value, cfl.name AS field_name
            FROM ' . $this->prefix . 'customized_data cd
            LEFT JOIN ' . $this->prefix . 'customization_field_lang cfl
                ON (cfl.id_customization_field = cd.index AND cfl.id_lang = ' . $this->idLang . ')
            WHERE cd.id_customization IN (' . $idsList . ')';

        $rows = $this->safeQuery($sql, 'order_customizations');
        $map = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $id = (int) $row['id_customization'];
                $label = trim((string) ($row['field_name'] ?? ''));
                if ($label === '') {
                    $label = 'field_' . (int) $row['index'];
                }
                $key = ((int) $row['type'] === 0 ? 'customization_file_' : 'customization_') . $label;
                $map[$id][] = [[$key => $row['value']], [], ''];
            }
        }

        return $map;
    }

    private function batchLoadOrderCarriers($orderIdsList)
    {
        $sql = 'SELECT oc.*, ca.name AS carrier_name
            FROM ' . $this->prefix . 'order_carrier oc
            LEFT JOIN ' . $this->prefix . 'carrier ca ON oc.id_carrier = ca.id_carrier
            WHERE oc.id_order IN (' . $orderIdsList . ')';

        $rows = $this->safeQuery($sql, 'order_carriers');
        $map = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $oid = (int) $row['id_order'];
                $map[$oid][] = [
                    'id' => (int) $row['id_order_carrier'],
                    'method_title' => (string) ($row['carrier_name'] ?? ''),
                    'total' => (string) round((float) $row['shipping_cost_tax_incl'], 2),
                    'tracking_number' => (string) ($row['tracking_number'] ?? ''),
                    'meta_data' => $this->extraColumnsMeta($row, self::$carrierMappedColumns),
                ];
            }
        }

        return $map;
    }

    private function detectOrderCartRuleColumns()
    {
        $hasValueTaxIncl = false;
        try {
            $result = $this->db->executeS(
                'SHOW COLUMNS FROM ' . $this->prefix . 'order_cart_rule WHERE Field = \'value_tax_incl\''
            );
            if (is_array($result) && count($result) > 0) {
                $hasValueTaxIncl = true;
            }
        } catch (Throwable $e) {
            /* intentionally empty */
        }

        return $hasValueTaxIncl;
    }

    private function batchLoadOrderCartRules($orderIdsList)
    {
        if ($this->cartRuleColumnsCache === null) {
            $this->cartRuleColumnsCache = $this->detectOrderCartRuleColumns();
        }
        $hasValueTaxIncl = $this->cartRuleColumnsCache;
        $valueSel = $hasValueTaxIncl ? 'ocr.value_tax_incl' : 'ocr.value';

        $sql = 'SELECT ocr.id_order, ocr.id_cart_rule, ocr.name, cr.code AS coupon_code, ' . $valueSel . ' AS discount_value
            FROM ' . $this->prefix . 'order_cart_rule ocr
            LEFT JOIN ' . $this->prefix . 'cart_rule cr ON (ocr.id_cart_rule = cr.id_cart_rule)
            WHERE ocr.id_order IN (' . $orderIdsList . ')';

        $rows = $this->safeQuery($sql, 'order_cart_rules');
        $map = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $oid = (int) $row['id_order'];
                $code = trim((string) ($row['coupon_code'] ?? ''));
                $map[$oid][] = [
                    'id' => (int) $row['id_cart_rule'],
                    'code' => $code !== '' ? $code : (string) ($row['name'] ?? ''),
                    'discount' => (string) round((float) $row['discount_value'], 2),
                ];
            }
        }

        return $map;
    }

    private function batchLoadOrderPayments($references)
    {
        if (empty($references)) {
            return [];
        }

        $refList = '\'' . implode('\',\'', $references) . '\'';

        $sql = 'SELECT op.*
            FROM ' . $this->prefix . 'order_payment op
            WHERE op.order_reference IN (' . $refList . ')';

        $rows = $this->safeQuery($sql, 'order_payments');
        $map = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $ref = (string) $row['order_reference'];
                $map[$ref][] = [
                    'method' => (string) ($row['payment_method'] ?? ''),
                    'amount' => (string) round((float) $row['amount'], 2),
                    'transaction_id' => (string) ($row['transaction_id'] ?? ''),
                    'meta_data' => $this->extraColumnsMeta($row, self::$paymentMappedColumns),
                ];
            }
        }

        return $map;
    }

    private function streamRefundsFast($syncType = 'full', $since = null)
    {
        $count = 0;
        $lastId = 0;

        $sinceWhere = '';
        if ($syncType === 'incremental' && $since) {
            $sinceWhere = ' AND os.date_add > \'' . pSQL($since) . '\'';
        }

        while (true) {
            $sql = 'SELECT os.*,
                       o.id_shop, o.id_shop_group
                FROM ' . $this->prefix . 'order_slip os
                LEFT JOIN ' . $this->prefix . 'orders o ON (os.id_order = o.id_order)
                WHERE os.id_order_slip > ' . (int) $lastId . $sinceWhere . '
                ORDER BY os.id_order_slip ASC
                LIMIT ' . $this->batchSize;

            $rows = $this->safeQuery($sql, 'refunds');

            if ($rows === false) {
                $lastId = $this->findNextId('order_slip', 'id_order_slip', $lastId, $sinceWhere);
                continue;
            }
            if (empty($rows)) {
                break;
            }

            $slipIds = [];
            foreach ($rows as $row) {
                $slipIds[] = (int) $row['id_order_slip'];
            }

            // Batch: slip details
            $detailMap = $this->batchLoadSlipDetails(implode(',', $slipIds));

            foreach ($rows as $row) {
                $sid = (int) $row['id_order_slip'];
                $totalProducts = (float) $row['total_products_tax_incl'];
                $totalShipping = (float) $row['total_shipping_tax_incl'];
                $totalAmount = $totalProducts + $totalShipping;

                $this->sendLine([
                    'type' => 'refund',
                    'data' => [
                        'id' => $sid,
                        'shop' => $this->getShopContext((int) ($row['id_shop'] ?? $this->idShop), (int) ($row['id_shop_group'] ?? 0)),
                        'parent_id' => (int) $row['id_order'],
                        'amount' => (string) round(abs($totalAmount), 2),
                        'reason' => '',
                        'date_created' => $this->toIso($row['date_add']),
                        'refunded_by' => null,
                        'line_items' => $detailMap[$sid] ?? [],
                        'meta_data' => $this->extraColumnsMeta($row, self::$refundMappedColumns),
                    ],
                ]);

                ++$count;
            }

            $lastId = (int) end($rows)['id_order_slip'];
            unset($rows, $detailMap);
            $this->adaptBatchSize();
            $this->maybeGc();
        }

        $this->sendLine([
            'type' => 'entity_complete',
            'entity' => 'refunds',
            'count' => $count,
        ]);

        return $count;
    }

    private function batchLoadSlipDetails($slipIdsList)
    {
        $sql = 'SELECT osd.*
            FROM ' . $this->prefix . 'order_slip_detail osd
            WHERE osd.id_order_slip IN (' . $slipIdsList . ')';

        $rows = $this->safeQuery($sql, 'refund_details');
        $map = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $sid = (int) $row['id_order_slip'];
                $map[$sid][] = [
                    'id' => (int) $row['id_order_detail'],
                    'quantity' => (int) $row['product_quantity'],
                    'amount' => (string) round((float) $row['amount_tax_incl'], 2),
                    'meta_data' => $this->extraColumnsMeta($row, [
                        'id_order_slip', 'id_order_detail', 'product_quantity', 'amount_tax_incl',
                    ]),
                ];
            }
        }

        return $map;
    }

    private function streamCustomersFast($syncType = 'full', $since = null)
    {
        $count = 0;
        $lastId = 0;

        $sinceWhere = '';
        if ($syncType === 'incremental' && $since) {
            $sinceWhere = ' AND c.date_upd > \'' . pSQL($since) . '\'';
        }

        while (true) {
            $sql = 'SELECT c.*,
                       gl.name AS gender_name
                FROM ' . $this->prefix . 'customer c
                LEFT JOIN ' . $this->prefix . 'gender_lang gl
                    ON (c.id_gender = gl.id_gender AND gl.id_lang = ' . $this->idLang . ')
                WHERE c.deleted = 0 AND c.id_customer > ' . (int) $lastId . $sinceWhere . '
                ORDER BY c.id_customer ASC
                LIMIT ' . $this->batchSize;

            $rows = $this->safeQuery($sql, 'customers');

            if ($rows === false) {
                $lastId = $this->findNextId('customer', 'id_customer', $lastId, str_replace('c.date_upd', 'date_upd', $sinceWhere));
                continue;
            }
            if (empty($rows)) {
                break;
            }

            $customerIds = [];
            foreach ($rows as $row) {
                $customerIds[] = (int) $row['id_customer'];
            }
            $customerIdsList = implode(',', $customerIds);

            // Batch: addresses
            $addrMap = $this->batchLoadCustomerAddresses($customerIdsList);

            // Batch: stats
            $statsMap = $this->batchLoadCustomerStats($customerIdsList);

            // Batch: groups
            $groupsMap = $this->batchLoadCustomerGroups($customerIdsList);

            foreach ($rows as $row) {
                $cid = (int) $row['id_customer'];
                $addresses = $addrMap[$cid] ?? [];
                $stats = $statsMap[$cid] ?? ['order_count' => 0, 'total_spent' => 0];

                // Use first address as primary billing/shipping
                $primaryAddr = !empty($addresses) ? $addresses[0] : null;

                $billing = $this->formatAddress($primaryAddr);
                $billing['email'] = (string) $row['email'];

                // Look for a dedicated delivery address (different from billing)
                $shippingAddr = count($addresses) > 1 ? $addresses[1] : $primaryAddr;
                $shipping = $this->formatAddress($shippingAddr);

                $phone = $billing['phone'] ?: ($shipping['phone'] ?? '');
                $city = $billing['city'] ?: ($shipping['city'] ?? '');
                $country = $billing['country'] ?: ($shipping['country'] ?? '');

                $metaData = [];
                $customerGroups = $this->formatCustomerGroups($cid, (int) ($row['id_default_group'] ?? 0), $groupsMap);
                if (!empty($row['newsletter'])) {
                    $metaData[] = ['key' => 'newsletter', 'value' => (string) $row['newsletter']];
                }
                if (!empty($customerGroups['default_group_id'])) {
                    $metaData[] = ['key' => 'default_group_id', 'value' => (string) $customerGroups['default_group_id']];
                }
                if (!empty($customerGroups['default_group_name'])) {
                    $metaData[] = ['key' => 'default_group_name', 'value' => (string) $customerGroups['default_group_name']];
                }
                if (!empty($row['birthday']) && $row['birthday'] !== '0000-00-00') {
                    $metaData[] = ['key' => 'birthday', 'value' => (string) $row['birthday']];
                }
                if (!empty($row['gender_name'])) {
                    $metaData[] = ['key' => 'gender', 'value' => (string) $row['gender_name']];
                }
                if ($stats['order_count'] > 0) {
                    $metaData[] = ['key' => 'orders_count', 'value' => (string) $stats['order_count']];
                    $metaData[] = ['key' => 'total_spent', 'value' => (string) round($stats['total_spent'], 2)];
                }
                $metaData = $this->mergeMeta($metaData, $this->extraColumnsMetaGroups([
                    [$row, self::$customerMappedColumns, ''],
                    [$primaryAddr, self::$addressMappedColumns, 'billing_'],
                    [$shippingAddr, self::$addressMappedColumns, 'shipping_'],
                ]));

                $this->sendLine([
                    'type' => 'customer',
                    'data' => [
                        'id' => $cid,
                        'shop' => $this->getShopContext((int) ($row['id_shop'] ?? $this->idShop), (int) ($row['id_shop_group'] ?? 0)),
                        'customer_groups' => $customerGroups,
                        'email' => (string) $row['email'],
                        'first_name' => (string) $row['firstname'],
                        'last_name' => (string) $row['lastname'],
                        'company' => (string) ($row['company'] ?? ''),
                        'phone' => $phone,
                        'city' => $city,
                        'country' => $country,
                        'date_created' => $this->toIso($row['date_add']),
                        'billing' => $billing,
                        'shipping' => $shipping,
                        'meta_data' => $metaData,
                    ],
                ]);

                ++$count;
            }

            $lastId = (int) end($rows)['id_customer'];
            unset($rows, $addrMap, $statsMap, $groupsMap);
            $this->adaptBatchSize();
            $this->maybeGc();
        }

        $this->sendLine([
            'type' => 'entity_complete',
            'entity' => 'customers',
            'count' => $count,
        ]);

        return $count;
    }

    private function batchLoadCustomerAddresses($customerIdsList)
    {
        $sql = 'SELECT a.*,
                   co.iso_code AS country, s.name AS state, s.iso_code AS state_code
            FROM ' . $this->prefix . 'address a
            LEFT JOIN ' . $this->prefix . 'country co ON a.id_country = co.id_country
            LEFT JOIN ' . $this->prefix . 'state s ON a.id_state = s.id_state
            WHERE a.id_customer IN (' . $customerIdsList . ') AND a.deleted = 0
            ORDER BY a.id_address ASC';

        $rows = $this->safeQuery($sql, 'customer_addresses');
        $map = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $cid = (int) $row['id_customer'];
                $map[$cid][] = $row;
            }
        }

        return $map;
    }

    private function batchLoadCustomerStats($customerIdsList)
    {
        $sql = 'SELECT id_customer, COUNT(*) AS order_count,
                   SUM(total_paid_tax_incl) AS total_spent
            FROM ' . $this->prefix . 'orders
            WHERE id_customer IN (' . $customerIdsList . ')
            GROUP BY id_customer';

        $rows = $this->safeQuery($sql, 'customer_stats');
        $map = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $cid = (int) $row['id_customer'];
                $map[$cid] = [
                    'order_count' => (int) $row['order_count'],
                    'total_spent' => (float) $row['total_spent'],
                ];
            }
        }

        return $map;
    }

    private function streamProductsFast($syncType = 'full', $since = null)
    {
        $count = 0;
        $lastId = 0;

        $sinceWhere = '';
        if ($syncType === 'incremental' && $since) {
            $sinceWhere = ' AND p.date_upd > \'' . pSQL($since) . '\'';
        }

        while (true) {
            $sql = 'SELECT p.*,
                       pl.name, pl.description, pl.description_short, pl.link_rewrite,
                       sa.quantity AS stock_quantity,
                       m.name AS manufacturer_name,
                       s.name AS supplier_name
                FROM ' . $this->prefix . 'product p
                LEFT JOIN ' . $this->prefix . 'product_lang pl
                    ON (p.id_product = pl.id_product AND pl.id_lang = ' . $this->idLang . ' AND pl.id_shop = ' . $this->idShop . ')
                LEFT JOIN ' . $this->prefix . 'stock_available sa
                    ON (p.id_product = sa.id_product AND sa.id_product_attribute = 0 AND sa.id_shop = ' . $this->idShop . ')
                LEFT JOIN ' . $this->prefix . 'manufacturer m ON (p.id_manufacturer = m.id_manufacturer)
                LEFT JOIN ' . $this->prefix . 'supplier s ON (p.id_supplier = s.id_supplier)
                WHERE p.id_product > ' . (int) $lastId . $sinceWhere . '
                ORDER BY p.id_product ASC
                LIMIT ' . $this->batchSize;

            $rows = $this->safeQuery($sql, 'products');

            if ($rows === false) {
                $lastId = $this->findNextId('product', 'id_product', $lastId, str_replace('p.date_upd', 'date_upd', $sinceWhere));
                continue;
            }
            if (empty($rows)) {
                break;
            }

            $productIds = [];
            $basePrices = [];
            $rewriteMap = [];
            foreach ($rows as $row) {
                $pid = (int) $row['id_product'];
                $productIds[] = $pid;
                $basePrices[$pid] = (float) $row['price'];
                $rewriteMap[$pid] = (string) ($row['link_rewrite'] ?? 'product');
            }
            $productIdsList = implode(',', $productIds);

            // Batch queries
            $categoriesMap = $this->batchLoadProductCategories($productIdsList);
            $tagsMap = $this->batchLoadProductTags($productIdsList);
            $imagesMap = $this->batchLoadProductImages($productIdsList, $rewriteMap);
            $salePriceMap = $this->batchLoadSpecificPrices($productIdsList, $basePrices);
            $combosMap = $this->batchLoadCombinations($productIdsList);
            $suppliersMap = $this->batchLoadProductSuppliers($productIdsList);
            $featuresMap = $this->batchLoadProductFeatures($productIdsList);
            $shop = $this->getShopContext();

            foreach ($rows as $row) {
                $pid = (int) $row['id_product'];
                $hasCombinations = !empty($combosMap[$pid]);
                $stockQty = min((int) ($row['stock_quantity'] ?? 0), 2147483647);

                $images = $imagesMap[$pid] ?? [];
                $imageUrl = !empty($images) ? $images[0] : null;
                $imageList = array_map(function ($src) { return ['src' => $src]; }, $images);

                $salePrice = $salePriceMap[$pid] ?? null;
                $displayPrices = $this->getDisplayPriceValues($pid);

                $this->sendLine([
                    'type' => 'product',
                    'data' => [
                        'id' => $pid,
                        'shop' => $shop,
                        'name' => (string) ($row['name'] ?? ''),
                        'slug' => (string) ($row['link_rewrite'] ?? ''),
                        'permalink' => $this->link->getProductLink($pid),
                        'type' => $hasCombinations ? 'variable' : 'simple',
                        'status' => $row['active'] ? 'publish' : 'draft',
                        'description' => (string) ($row['description'] ?? ''),
                        'short_description' => (string) ($row['description_short'] ?? ''),
                        'sku' => (string) ($row['reference'] ?? ''),
                        'price' => (string) round((float) $row['price'], 2),
                        'regular_price' => (string) round((float) $row['price'], 2),
                        'sale_price' => $salePrice,
                        'display_price' => $displayPrices['price'],
                        'display_regular_price' => $displayPrices['regular_price'],
                        'display_sale_price' => $displayPrices['sale_price'],
                        'display_price_includes_tax' => true,
                        'on_sale' => !empty($salePrice),
                        'stock_status' => $stockQty > 0 ? 'instock' : 'outofstock',
                        'stock_quantity' => $stockQty,
                        'manage_stock' => true,
                        'weight' => (string) ($row['weight'] ?? ''),
                        'ean13' => (string) ($row['ean13'] ?? ''),
                        'upc' => (string) ($row['upc'] ?? ''),
                        'isbn' => (string) ($row['isbn'] ?? ''),
                        'condition' => (string) ($row['condition'] ?? 'new'),
                        'manufacturer_name' => (string) ($row['manufacturer_name'] ?? ''),
                        'supplier_id' => (int) ($row['id_supplier'] ?? 0),
                        'supplier_name' => (string) ($row['supplier_name'] ?? ''),
                        'supplier_reference' => (string) ($row['supplier_reference'] ?? ''),
                        'suppliers' => $suppliersMap[$pid] ?? [],
                        'features' => $featuresMap[$pid] ?? [],
                        'wholesale_price' => (string) round((float) ($row['wholesale_price'] ?? 0), 2),
                        'category_ids' => $categoriesMap[$pid] ?? [],
                        'tags' => $tagsMap[$pid] ?? [],
                        'parent_id' => null,
                        'image_url' => $imageUrl,
                        'images' => $imageList,
                        'date_created' => $this->toIso($row['date_add']),
                        'date_modified' => $this->toIso($row['date_upd']),
                        'meta_data' => $this->extraColumnsMeta($row, self::$productMappedColumns),
                    ],
                ]);
                ++$count;

                // Emit variations as separate products
                if ($hasCombinations) {
                    foreach ($combosMap[$pid] as $combo) {
                        $aid = (int) $combo['id_product_attribute'];
                        $comboStock = min((int) ($combo['quantity'] ?? 0), 2147483647);
                        $comboRef = (string) ($combo['reference'] ?? $row['reference'] ?? '');
                        $priceImpact = (float) ($combo['price_impact'] ?? 0);
                        $comboPrice = (float) $row['price'] + $priceImpact;
                        $displayPrices = $this->getDisplayPriceValues($pid, $aid);

                        $comboName = (string) ($row['name'] ?? '');
                        if (!empty($combo['attributes'])) {
                            $comboName .= ' - ' . $combo['attributes'];
                        }

                        $this->sendLine([
                            'type' => 'product',
                            'data' => [
                                'id' => $pid . '_' . $aid,
                                'shop' => $shop,
                                'name' => $comboName,
                                'slug' => (string) ($row['link_rewrite'] ?? ''),
                                'permalink' => $this->link->getProductLink($pid, null, null, null, null, null, $aid),
                                'type' => 'variation',
                                'status' => $row['active'] ? 'publish' : 'draft',
                                'sku' => $comboRef,
                                'price' => (string) round($comboPrice, 2),
                                'regular_price' => (string) round($comboPrice, 2),
                                'sale_price' => null,
                                'display_price' => $displayPrices['price'],
                                'display_regular_price' => $displayPrices['regular_price'],
                                'display_sale_price' => $displayPrices['sale_price'],
                                'display_price_includes_tax' => true,
                                'stock_status' => $comboStock > 0 ? 'instock' : 'outofstock',
                                'stock_quantity' => $comboStock,
                                'manage_stock' => true,
                                'weight' => (string) ($row['weight'] ?? ''),
                                'ean13' => (string) ($combo['ean13'] ?? ''),
                                'upc' => (string) ($combo['upc'] ?? ''),
                                'category_ids' => [],
                                'parent_id' => $pid,
                                'image_url' => $imageUrl,
                                'images' => [],
                                'date_created' => $this->toIso($row['date_add']),
                                'date_modified' => $this->toIso($row['date_upd']),
                            ],
                        ]);
                        ++$count;
                    }
                }
            }

            $lastId = (int) end($rows)['id_product'];
            unset($rows, $categoriesMap, $tagsMap, $imagesMap, $salePriceMap, $combosMap, $suppliersMap, $featuresMap);
            $this->adaptBatchSize();
            $this->maybeGc();
        }

        $this->sendLine([
            'type' => 'entity_complete',
            'entity' => 'products',
            'count' => $count,
        ]);

        return $count;
    }

    private function batchLoadProductCategories($productIdsList)
    {
        $sql = 'SELECT id_product, id_category
            FROM ' . $this->prefix . 'category_product
            WHERE id_product IN (' . $productIdsList . ')';

        $rows = $this->safeQuery($sql, 'product_categories');
        $map = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $map[(int) $row['id_product']][] = (int) $row['id_category'];
            }
        }

        return $map;
    }

    private function batchLoadProductSuppliers($productIdsList)
    {
        $sql = 'SELECT ps.id_product, ps.id_supplier, ps.id_product_attribute,
                   ps.product_supplier_reference, ps.product_supplier_price_te,
                   ps.id_currency, s.name AS supplier_name
            FROM ' . $this->prefix . 'product_supplier ps
            LEFT JOIN ' . $this->prefix . 'supplier s ON (ps.id_supplier = s.id_supplier)
            WHERE ps.id_product IN (' . $productIdsList . ')';

        $rows = $this->safeQuery($sql, 'product_suppliers');
        $map = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $map[(int) $row['id_product']][] = [
                    'id' => (int) $row['id_supplier'],
                    'name' => (string) ($row['supplier_name'] ?? ''),
                    'reference' => (string) ($row['product_supplier_reference'] ?? ''),
                    'price_te' => (string) ($row['product_supplier_price_te'] ?? '0'),
                    'currency_id' => (int) $row['id_currency'],
                    'attribute_id' => (int) $row['id_product_attribute'],
                ];
            }
        }

        return $map;
    }

    private function batchLoadProductFeatures($productIdsList)
    {
        $sql = 'SELECT fp.id_product, fp.id_feature, fp.id_feature_value,
                   f.position,
                   fl.name AS feature_name,
                   fvl.value AS feature_value
            FROM ' . $this->prefix . 'feature_product fp
            LEFT JOIN ' . $this->prefix . 'feature f ON (fp.id_feature = f.id_feature)
            LEFT JOIN ' . $this->prefix . 'feature_lang fl
                ON (fp.id_feature = fl.id_feature AND fl.id_lang = ' . $this->idLang . ')
            LEFT JOIN ' . $this->prefix . 'feature_value_lang fvl
                ON (fp.id_feature_value = fvl.id_feature_value AND fvl.id_lang = ' . $this->idLang . ')
            WHERE fp.id_product IN (' . $productIdsList . ')
            ORDER BY f.position ASC';

        $rows = $this->safeQuery($sql, 'product_features');
        $map = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $map[(int) $row['id_product']][] = [
                    'id' => (int) $row['id_feature'],
                    'name' => (string) ($row['feature_name'] ?? ''),
                    'value_id' => (int) $row['id_feature_value'],
                    'value' => (string) ($row['feature_value'] ?? ''),
                    'position' => (int) ($row['position'] ?? 0),
                ];
            }
        }

        return $map;
    }

    private function batchLoadProductTags($productIdsList)
    {
        $sql = 'SELECT pt.id_product, t.id_tag, t.name
            FROM ' . $this->prefix . 'product_tag pt
            JOIN ' . $this->prefix . 'tag t ON pt.id_tag = t.id_tag
            WHERE pt.id_product IN (' . $productIdsList . ') AND t.id_lang = ' . $this->idLang;

        $rows = $this->safeQuery($sql, 'product_tags');
        $map = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $map[(int) $row['id_product']][] = [
                    'id' => (int) $row['id_tag'],
                    'name' => (string) $row['name'],
                ];
            }
        }

        return $map;
    }

    private function batchLoadProductImages($productIdsList, $rewriteMap = [])
    {
        $sql = 'SELECT i.id_product, i.id_image, i.cover
            FROM ' . $this->prefix . 'image i
            INNER JOIN ' . $this->prefix . 'image_shop ish
                ON (i.id_image = ish.id_image AND ish.id_shop = ' . $this->idShop . ')
            WHERE i.id_product IN (' . $productIdsList . ')
            ORDER BY i.position ASC';

        $rows = $this->safeQuery($sql, 'product_images');
        $map = [];

        if (!is_array($rows) || empty($rows)) {
            return $map;
        }

        // Build image URLs
        $link = $this->link;

        foreach ($rows as $row) {
            $pid = (int) $row['id_product'];
            $idImage = (int) $row['id_image'];
            $linkRewrite = $rewriteMap[$pid] ?? 'product';

            $imageUrl = null;
            if ($link) {
                $imageUrl = $link->getImageLink($linkRewrite, $idImage, 'home_default');
                if ($imageUrl && strpos($imageUrl, 'http') !== 0) {
                    $imageUrl = 'https://' . $imageUrl;
                }
            }

            if ($imageUrl) {
                $map[$pid][] = $imageUrl;
            }
        }

        return $map;
    }

    private function batchLoadSpecificPrices($productIdsList, $basePrices = [])
    {
        $now = date('Y-m-d H:i:s');

        $sql = 'SELECT id_product, reduction, reduction_type, reduction_tax
            FROM ' . $this->prefix . 'specific_price
            WHERE id_product IN (' . $productIdsList . ')
            AND id_group = 0 AND id_customer = 0 AND from_quantity <= 1
            AND ((`from` = \'0000-00-00 00:00:00\' OR `from` <= \'' . pSQL($now) . '\')
            AND (`to` = \'0000-00-00 00:00:00\' OR `to` >= \'' . pSQL($now) . '\'))
            ORDER BY id_specific_price ASC';

        $rows = $this->safeQuery($sql, 'product_prices');
        $map = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $pid = (int) $row['id_product'];
                if (isset($map[$pid])) {
                    continue; // Keep first matching rule
                }

                $reduction = (float) $row['reduction'];
                if ($reduction > 0) {
                    $basePrice = isset($basePrices[$pid]) ? (float) $basePrices[$pid] : 0;
                    if ($row['reduction_type'] === 'percentage') {
                        $salePrice = $basePrice * (1 - $reduction);
                    } else {
                        $salePrice = $basePrice - $reduction;
                    }
                    $map[$pid] = (string) round(max(0, $salePrice), 2);
                }
            }
        }

        return $map;
    }

    private function batchLoadCombinations($productIdsList)
    {
        $sql = 'SELECT pa.id_product, pa.id_product_attribute, pa.reference, pa.ean13, pa.upc,
                   pa.price AS price_impact, pa.weight AS weight_impact,
                   sa.quantity,
                   GROUP_CONCAT(DISTINCT al.name ORDER BY al.name SEPARATOR \', \') AS attributes
            FROM ' . $this->prefix . 'product_attribute pa
            LEFT JOIN ' . $this->prefix . 'stock_available sa
                ON (pa.id_product_attribute = sa.id_product_attribute AND sa.id_shop = ' . $this->idShop . ')
            LEFT JOIN ' . $this->prefix . 'product_attribute_combination pac
                ON pa.id_product_attribute = pac.id_product_attribute
            LEFT JOIN ' . $this->prefix . 'attribute_lang al
                ON (pac.id_attribute = al.id_attribute AND al.id_lang = ' . $this->idLang . ')
            WHERE pa.id_product IN (' . $productIdsList . ')
            GROUP BY pa.id_product_attribute';

        $rows = $this->safeQuery($sql, 'product_combinations');
        $map = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $pid = (int) $row['id_product'];
                $map[$pid][] = $row;
            }
        }

        return $map;
    }

    private function streamCategoriesFast($syncType = 'full', $since = null)
    {
        $count = 0;
        $lastId = 0;

        $sinceWhere = '';
        if ($syncType === 'incremental' && $since) {
            $sinceWhere = ' AND c.date_upd > \'' . pSQL($since) . '\'';
        }

        while (true) {
            $sql = 'SELECT c.*,
                       cl.name, cl.description, cl.link_rewrite
                FROM ' . $this->prefix . 'category c
                LEFT JOIN ' . $this->prefix . 'category_lang cl
                    ON (c.id_category = cl.id_category AND cl.id_lang = ' . $this->idLang . ' AND cl.id_shop = ' . $this->idShop . ')
                WHERE c.active = 1 AND c.id_category > ' . (int) $lastId . $sinceWhere . '
                ORDER BY c.id_category ASC
                LIMIT ' . $this->batchSize;

            $rows = $this->safeQuery($sql, 'categories');

            if ($rows === false) {
                $lastId = $this->findNextId('category', 'id_category', $lastId, str_replace('c.date_upd', 'date_upd', $sinceWhere));
                continue;
            }
            if (empty($rows)) {
                break;
            }

            // Batch load product counts instead of N+1 subquery
            $catIds = array_map(function ($r) { return (int) $r['id_category']; }, $rows);
            $catIdsList = implode(',', $catIds);
            $countMap = [];
            $countRows = $this->safeQuery(
                'SELECT id_category, COUNT(*) AS cnt FROM ' . $this->prefix . 'category_product WHERE id_category IN (' . $catIdsList . ') GROUP BY id_category',
                'category_counts'
            );
            if (is_array($countRows)) {
                foreach ($countRows as $cr) {
                    $countMap[(int) $cr['id_category']] = (int) $cr['cnt'];
                }
            }
            $shop = $this->getShopContext();

            foreach ($rows as $row) {
                $cid = (int) $row['id_category'];
                $this->sendLine([
                    'type' => 'category',
                    'data' => [
                        'id' => $cid,
                        'shop' => $shop,
                        'name' => (string) ($row['name'] ?? ''),
                        'slug' => (string) ($row['link_rewrite'] ?? ''),
                        'parent_id' => (int) $row['id_parent'] ?: null,
                        'description' => (string) ($row['description'] ?? ''),
                        'count' => $countMap[$cid] ?? 0,
                        'image_url' => null,
                        'meta_data' => $this->extraColumnsMeta($row, self::$categoryMappedColumns),
                    ],
                ]);
                ++$count;
            }

            $lastId = (int) end($rows)['id_category'];
            unset($rows);
            $this->adaptBatchSize();
            $this->maybeGc();
        }

        $this->sendLine([
            'type' => 'entity_complete',
            'entity' => 'categories',
            'count' => $count,
        ]);

        return $count;
    }

    private function streamCouponsFast($syncType = 'full', $since = null)
    {
        $count = 0;
        $lastId = 0;

        $sinceWhere = '';
        if ($syncType === 'incremental' && $since) {
            $sinceWhere = ' AND cr.date_add > \'' . pSQL($since) . '\'';
        }

        while (true) {
            $sql = 'SELECT cr.*,
                       crl.name
                FROM ' . $this->prefix . 'cart_rule cr
                LEFT JOIN ' . $this->prefix . 'cart_rule_lang crl
                    ON (cr.id_cart_rule = crl.id_cart_rule AND crl.id_lang = ' . $this->idLang . ')
                WHERE cr.id_cart_rule > ' . (int) $lastId . $sinceWhere . '
                ORDER BY cr.id_cart_rule ASC
                LIMIT ' . $this->batchSize;

            $rows = $this->safeQuery($sql, 'coupons');

            if ($rows === false) {
                $lastId = $this->findNextId('cart_rule', 'id_cart_rule', $lastId, str_replace('cr.date_add', 'date_add', $sinceWhere));
                continue;
            }
            if (empty($rows)) {
                break;
            }

            // Batch load usage counts instead of N+1 subquery
            $crIds = array_map(function ($r) { return (int) $r['id_cart_rule']; }, $rows);
            $crIdsList = implode(',', $crIds);
            $usageMap = [];
            $usageRows = $this->safeQuery(
                'SELECT id_cart_rule, COUNT(DISTINCT id_order) AS cnt FROM ' . $this->prefix . 'order_cart_rule WHERE id_cart_rule IN (' . $crIdsList . ') GROUP BY id_cart_rule',
                'coupon_usage_counts'
            );
            if (is_array($usageRows)) {
                foreach ($usageRows as $ur) {
                    $usageMap[(int) $ur['id_cart_rule']] = (int) $ur['cnt'];
                }
            }
            $restrictionsMap = $this->batchLoadCartRuleRestrictions($crIdsList);
            $shop = $this->getShopContext();

            foreach ($rows as $row) {
                $crId = (int) $row['id_cart_rule'];
                $discountType = 'fixed_cart';
                $amount = (float) $row['reduction_amount'];
                if ((float) $row['reduction_percent'] > 0) {
                    $discountType = 'percent';
                    $amount = (float) $row['reduction_percent'];
                }

                $this->sendLine([
                    'type' => 'coupon',
                    'data' => [
                        'id' => $crId,
                        'shop' => $shop,
                        'code' => (string) ($row['code'] ?? ''),
                        'description' => (string) ($row['description'] ?? ''),
                        'discount_type' => $discountType,
                        'amount' => (string) $amount,
                        'usage_count' => $usageMap[$crId] ?? 0,
                        'usage_limit' => (int) ($row['quantity'] ?? 0),
                        'usage_limit_per_user' => (int) ($row['quantity_per_user'] ?? 0),
                        'free_shipping' => (bool) $row['free_shipping'],
                        'minimum_amount' => (string) ((float) ($row['minimum_amount'] ?? 0)),
                        'maximum_amount' => null,
                        'restrictions' => [
                            'group_restriction' => !empty($row['group_restriction']),
                            'shop_restriction' => !empty($row['shop_restriction']),
                            'groups' => $restrictionsMap[$crId]['groups'] ?? [],
                            'shops' => $restrictionsMap[$crId]['shops'] ?? [],
                        ],
                        'date_created' => $this->toIso($row['date_add']),
                        'date_expires' => ($row['date_to'] && $row['date_to'] !== '0000-00-00 00:00:00')
                            ? $this->toIso($row['date_to']) : null,
                        'meta_data' => $this->extraColumnsMeta($row, self::$couponMappedColumns),
                    ],
                ]);
                ++$count;
            }

            $lastId = (int) end($rows)['id_cart_rule'];
            unset($rows, $restrictionsMap);
            $this->adaptBatchSize();
            $this->maybeGc();
        }

        $this->sendLine([
            'type' => 'entity_complete',
            'entity' => 'coupons',
            'count' => $count,
        ]);

        return $count;
    }

    private function getStoreUrl()
    {
        try {
            $ssl = Configuration::get('PS_SSL_ENABLED') || Tools::usingSecureMode();

            $domain = Configuration::get($ssl ? 'PS_SHOP_DOMAIN_SSL' : 'PS_SHOP_DOMAIN');
            if (empty($domain)) {
                $domain = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
            }

            $physicalUri = defined('__PS_BASE_URI__') ? __PS_BASE_URI__ : '/';

            return rtrim(($ssl ? 'https://' : 'http://') . $domain . $physicalUri, '/');
        } catch (Throwable $e) {
            return 'https://unknown';
        }
    }

    private function getShopContext($idShop = null, $idShopGroup = null)
    {
        $shopId = (int) ($idShop ?: $this->idShop);
        if ($shopId <= 0) {
            $shopId = (int) $this->idShop;
        }

        if (isset($this->shopContextCache[$shopId])) {
            return $this->shopContextCache[$shopId];
        }

        $context = [
            'id' => $shopId,
            'group_id' => $idShopGroup !== null ? (int) $idShopGroup : null,
            'group_name' => null,
            'name' => '',
            'url' => $this->getStoreUrl(),
            'active' => true,
        ];

        try {
            $sql = 'SELECT s.id_shop, s.id_shop_group, s.name, s.active,
                       sg.name AS group_name,
                       su.domain, su.domain_ssl, su.physical_uri, su.virtual_uri
                FROM ' . $this->prefix . 'shop s
                LEFT JOIN ' . $this->prefix . 'shop_group sg ON (s.id_shop_group = sg.id_shop_group)
                LEFT JOIN ' . $this->prefix . 'shop_url su
                    ON (s.id_shop = su.id_shop AND su.main = 1 AND su.active = 1)
                WHERE s.id_shop = ' . (int) $shopId;
            $row = $this->db->getRow($sql);
            if (is_array($row) && !empty($row)) {
                $ssl = Configuration::get('PS_SSL_ENABLED') || Tools::usingSecureMode();
                $domain = $ssl && !empty($row['domain_ssl']) ? $row['domain_ssl'] : ($row['domain'] ?? '');
                if (empty($domain) && !empty($row['domain_ssl'])) {
                    $domain = $row['domain_ssl'];
                }

                $physicalUri = isset($row['physical_uri']) ? (string) $row['physical_uri'] : '/';
                $virtualUri = isset($row['virtual_uri']) ? (string) $row['virtual_uri'] : '';
                $baseUri = '/' . trim($physicalUri . $virtualUri, '/') . '/';
                if ($baseUri === '//') {
                    $baseUri = '/';
                }

                $context = [
                    'id' => (int) $row['id_shop'],
                    'group_id' => (int) $row['id_shop_group'],
                    'group_name' => isset($row['group_name']) ? (string) $row['group_name'] : null,
                    'name' => (string) ($row['name'] ?? ''),
                    'url' => $domain ? rtrim(($ssl ? 'https://' : 'http://') . $domain . $baseUri, '/') : $context['url'],
                    'active' => !empty($row['active']),
                ];
            }
        } catch (Throwable $e) {
            // Keep the fallback context.
        }

        $this->shopContextCache[$shopId] = $context;

        return $context;
    }

    private function batchLoadCustomerGroups($customerIdsList)
    {
        if (trim((string) $customerIdsList) === '') {
            return [];
        }

        $sql = 'SELECT cg.id_customer, cg.id_group, gl.name
            FROM ' . $this->prefix . 'customer_group cg
            LEFT JOIN ' . $this->prefix . 'group_lang gl
                ON (cg.id_group = gl.id_group AND gl.id_lang = ' . $this->idLang . ')
            WHERE cg.id_customer IN (' . $customerIdsList . ')
            ORDER BY cg.id_customer ASC, cg.id_group ASC';

        $rows = $this->safeQuery($sql, 'customer_groups');
        $map = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $cid = (int) $row['id_customer'];
                $group = [
                    'id' => (int) $row['id_group'],
                    'name' => (string) ($row['name'] ?? ''),
                ];
                $map[$cid][] = $group;
            }
        }

        return $map;
    }

    private function formatCustomerGroups($customerId, $defaultGroupId, $groupsMap)
    {
        $defaultGroupId = (int) $defaultGroupId;
        $groups = isset($groupsMap[$customerId]) && is_array($groupsMap[$customerId]) ? $groupsMap[$customerId] : [];
        $groupIds = [];
        $groupNames = [];
        $defaultGroupName = null;

        foreach ($groups as $group) {
            $groupIds[] = (int) $group['id'];
            $groupNames[] = (string) $group['name'];
            if ((int) $group['id'] === $defaultGroupId) {
                $defaultGroupName = (string) $group['name'];
            }
        }

        return [
            'default_group_id' => $defaultGroupId > 0 ? $defaultGroupId : null,
            'default_group_name' => $defaultGroupName,
            'group_ids' => $groupIds,
            'group_names' => $groupNames,
            'groups' => $groups,
        ];
    }

    private function batchLoadCartRuleRestrictions($cartRuleIdsList)
    {
        if (trim((string) $cartRuleIdsList) === '') {
            return [];
        }

        $map = [];

        $groupRows = $this->safeQuery(
            'SELECT crg.id_cart_rule, crg.id_group, gl.name
                FROM ' . $this->prefix . 'cart_rule_group crg
                LEFT JOIN ' . $this->prefix . 'group_lang gl
                    ON (crg.id_group = gl.id_group AND gl.id_lang = ' . $this->idLang . ')
                WHERE crg.id_cart_rule IN (' . $cartRuleIdsList . ')
                ORDER BY crg.id_cart_rule ASC, crg.id_group ASC',
            'cart_rule_group_restrictions'
        );
        if (is_array($groupRows)) {
            foreach ($groupRows as $row) {
                $id = (int) $row['id_cart_rule'];
                if (!isset($map[$id])) {
                    $map[$id] = ['groups' => [], 'shops' => []];
                }
                $map[$id]['groups'][] = [
                    'id' => (int) $row['id_group'],
                    'name' => (string) ($row['name'] ?? ''),
                ];
            }
        }

        $shopRows = $this->safeQuery(
            'SELECT crs.id_cart_rule, s.id_shop, s.id_shop_group, s.name
                FROM ' . $this->prefix . 'cart_rule_shop crs
                LEFT JOIN ' . $this->prefix . 'shop s ON (crs.id_shop = s.id_shop)
                WHERE crs.id_cart_rule IN (' . $cartRuleIdsList . ')
                ORDER BY crs.id_cart_rule ASC, s.id_shop ASC',
            'cart_rule_shop_restrictions'
        );
        if (is_array($shopRows)) {
            foreach ($shopRows as $row) {
                $id = (int) $row['id_cart_rule'];
                if (!isset($map[$id])) {
                    $map[$id] = ['groups' => [], 'shops' => []];
                }
                $map[$id]['shops'][] = $this->getShopContext((int) $row['id_shop'], (int) $row['id_shop_group']);
            }
        }

        return $map;
    }

    private function safeQuery($sql, $context = '', $retries = 2)
    {
        for ($attempt = 0; $attempt <= $retries; ++$attempt) {
            try {
                $rows = $this->db->executeS($sql);
            } catch (Throwable $e) {
                if ($attempt < $retries) {
                    usleep(200000 * ($attempt + 1)); // 200ms, 400ms
                    continue;
                }
                if (!class_exists('FullmetrixLogger')) {
                    require_once _PS_MODULE_DIR_ . 'fullmetrixconnector/classes/FullmetrixLogger.php';
                }
                FullmetrixLogger::logException('safe_query' . ($context ? '_' . $context : ''), $e);
                $this->sendLine([
                    'type' => 'error',
                    'message' => 'A database error occurred while exporting data.',
                    'attempt' => $attempt + 1,
                ]);
                return false;
            }
            if ($rows === false) {
                if ($attempt < $retries) {
                    usleep(200000 * ($attempt + 1));
                    continue;
                }
                $error = method_exists($this->db, 'getMsgError') ? $this->db->getMsgError() : 'Unknown SQL error';
                $this->sendLine([
                    'type' => 'error',
                    'message' => 'SQL error' . ($context ? ' [' . $context . ']' : '') . ': ' . $error,
                    'attempt' => $attempt + 1,
                ]);
                return false;
            }
            return is_array($rows) ? $rows : [];
        }
        return false;
    }

    private function sendLine($data)
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);

        // Any badly encoded byte coming from the shop database would otherwise
        // turn the whole line into an empty one, silently losing the entity.
        if ($json === false && defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        if ($json === false) {
            if (!class_exists('FullmetrixLogger')) {
                require_once _PS_MODULE_DIR_ . 'fullmetrixconnector/classes/FullmetrixLogger.php';
            }
            FullmetrixLogger::log('send_line_encode_failed', json_last_error_msg());

            return;
        }

        echo $json . "\n";
        flush();
    }

    /**
     * Find the next valid ID after a failed query to avoid skipping data.
     * Uses a lightweight MIN() query instead of blind $lastId += batchSize.
     */
    private function findNextId($table, $idColumn, $afterId, $extraWhere = '')
    {
        $sql = 'SELECT MIN(' . $idColumn . ') AS next_id FROM ' . $this->prefix . $table
            . ' WHERE ' . $idColumn . ' > ' . (int) $afterId . $extraWhere;
        try {
            $row = $this->db->getRow($sql);
            if ($row && $row['next_id'] !== null) {
                return (int) $row['next_id'] - 1; // -1 because queries use > lastId
            }
        } catch (Throwable $e) {
            // fallback
        }

        return $afterId + $this->batchSize;
    }

    private $gcCounter = 0;

    private function maybeGc()
    {
        try {
            ++$this->gcCounter;
            if ($this->gcCounter % 3 === 0 && function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
            if ($this->memoryLimitBytes) {
                $memPct = memory_get_usage(true) / $this->memoryLimitBytes;
                if ($memPct > 0.8) {
                    $this->batchSize = max(100, (int) ($this->batchSize * 0.5));
                    $this->sendLine(['type' => 'info', 'message' => 'Batch reduit (pression memoire)']);
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                }
            }
        } catch (Throwable $e) {
            // memory housekeeping is best-effort
        }
    }

    private function adaptBatchSize()
    {
        try {
            if (!$this->memoryLimitBytes) {
                return;
            }
            $memPct = memory_get_usage(true) / $this->memoryLimitBytes;
            if ($memPct > 0.7 && $this->batchSize > 100) {
                $this->batchSize = max(100, (int) ($this->batchSize * 0.5));
            } elseif ($memPct < 0.4 && $this->batchSize < 2000) {
                $this->batchSize = min(2000, (int) ($this->batchSize * 1.5));
            }
        } catch (Throwable $e) {
            // batch tuning is best-effort
        }
    }

    private function parseMemoryLimit($val)
    {
        $val = trim($val);
        $num = (int) $val;
        $suffix = strtolower(substr($val, -1));
        switch ($suffix) {
            case 'g': return $num * 1024 * 1024 * 1024;
            case 'm': return $num * 1024 * 1024;
            case 'k': return $num * 1024;
            default: return $num;
        }
    }

    // ─── SINGLE ENTITY FORMAT (for webhooks) ─────────────────────────

    /**
     * Format a single entity by type and ID, returning the same data shape
     * as the NDJSON stream. Returns null if entity not found.
     *
     * @param string $entityType order|customer|product|category|coupon|refund
     * @param int|string $id
     *
     * @return array|null
     */
    public function formatSingleEntity($entityType, $id)
    {
        if ($entityType === 'product' && preg_match('/^(\d+)_(\d+)$/', (string) $id, $matches)) {
            return $this->formatSingleProductVariant((int) $matches[1], (int) $matches[2]);
        }

        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }

        switch ($entityType) {
            case 'order':
                return $this->formatSingleOrder($id);
            case 'customer':
                return $this->formatSingleCustomer($id);
            case 'product':
                return $this->formatSingleProduct($id);
            case 'category':
                return $this->formatSingleCategory($id);
            case 'coupon':
                return $this->formatSingleCoupon($id);
            case 'refund':
                return $this->formatSingleRefund($id);
            default:
                return null;
        }
    }

    private function formatSingleOrder($orderId)
    {
        $sql = 'SELECT o.*,
                   o.module AS payment_method,
                   o.payment AS payment_method_title,
                   osl.name AS status_name,
                   c.email AS customer_email, c.id_default_group,
                   cur.iso_code AS currency_code
            FROM ' . $this->prefix . 'orders o
            LEFT JOIN ' . $this->prefix . 'order_state_lang osl
                ON (o.current_state = osl.id_order_state AND osl.id_lang = ' . $this->idLang . ')
            LEFT JOIN ' . $this->prefix . 'customer c ON (o.id_customer = c.id_customer)
            LEFT JOIN ' . $this->prefix . 'currency cur ON (o.id_currency = cur.id_currency)
            WHERE o.id_order = ' . $orderId;

        $rows = $this->safeQuery($sql, 'single_order');
        if (!is_array($rows) || empty($rows)) {
            return null;
        }
        $row = $rows[0];

        $addressIds = array_unique(array_filter([
            (int) $row['id_address_invoice'],
            (int) $row['id_address_delivery'],
        ]));
        $addressMap = $this->batchLoadAddresses($addressIds);
        $lineItemsMap = $this->batchLoadOrderLineItems((string) $orderId);
        $carriersMap = $this->batchLoadOrderCarriers((string) $orderId);
        $couponMap = $this->batchLoadOrderCartRules((string) $orderId);
        $paymentMap = $this->batchLoadOrderPayments([pSQL($row['reference'])]);
        $customerGroupsMap = $this->batchLoadCustomerGroups((string) ((int) $row['id_customer']));

        $totalTaxIncl = (float) $row['total_paid_tax_incl'];
        $totalTaxExcl = (float) $row['total_paid_tax_excl'];
        $tax = max(0, $totalTaxIncl - $totalTaxExcl);

        $billing = $this->formatAddress($addressMap[(int) $row['id_address_invoice']] ?? null);
        $shipping = $this->formatAddress($addressMap[(int) $row['id_address_delivery']] ?? null);
        $billing['email'] = (string) ($row['customer_email'] ?? '');

        return [
            'id' => $orderId,
            'shop' => $this->getShopContext((int) ($row['id_shop'] ?? $this->idShop), (int) ($row['id_shop_group'] ?? 0)),
            'customer_groups' => $this->formatCustomerGroups((int) $row['id_customer'], (int) ($row['id_default_group'] ?? 0), $customerGroupsMap),
            'number' => (string) ($row['reference'] ?: $orderId),
            'status' => (string) ($row['status_name'] ?: 'unknown'),
            'currency' => (string) ($row['currency_code'] ?: 'EUR'),
            'conversion_rate' => (string) (isset($row['conversion_rate']) ? (float) $row['conversion_rate'] : 1),
            'total' => (string) round($totalTaxIncl, 2),
            'discount_total' => (string) round((float) $row['total_discounts_tax_incl'], 2),
            'shipping_total' => (string) round((float) $row['total_shipping_tax_incl'], 2),
            'total_tax' => (string) round($tax, 2),
            'date_created' => $this->toIso($row['date_add']),
            'date_modified' => $this->toIso($row['date_upd']),
            'date_paid' => null,
            'payment_method' => (string) ($row['payment_method'] ?? ''),
            'payment_method_title' => (string) ($row['payment_method_title'] ?? ''),
            'customer_id' => (int) $row['id_customer'],
            'customer_note' => (string) ($row['note'] ?? ''),
            'billing' => $billing,
            'shipping' => $shipping,
            'line_items' => $lineItemsMap[$orderId] ?? [],
            'shipping_lines' => $carriersMap[$orderId] ?? [],
            'fee_lines' => [],
            'coupon_lines' => $couponMap[$orderId] ?? [],
            'tax_lines' => [],
            'payments' => $paymentMap[$row['reference']] ?? [],
            'meta_data' => $this->extraColumnsMetaGroups([
                [$row, self::$orderMappedColumns, ''],
                [$addressMap[(int) $row['id_address_invoice']] ?? null, self::$addressMappedColumns, 'billing_'],
                [$addressMap[(int) $row['id_address_delivery']] ?? null, self::$addressMappedColumns, 'shipping_'],
            ]),
        ];
    }

    private function formatSingleCustomer($customerId)
    {
        $sql = 'SELECT c.*,
                   gl.name AS gender_name
            FROM ' . $this->prefix . 'customer c
            LEFT JOIN ' . $this->prefix . 'gender_lang gl
                ON (c.id_gender = gl.id_gender AND gl.id_lang = ' . $this->idLang . ')
            WHERE c.deleted = 0 AND c.id_customer = ' . $customerId;

        $rows = $this->safeQuery($sql, 'single_customer');
        if (!is_array($rows) || empty($rows)) {
            return null;
        }
        $row = $rows[0];

        $addrMap = $this->batchLoadCustomerAddresses((string) $customerId);
        $statsMap = $this->batchLoadCustomerStats((string) $customerId);
        $groupsMap = $this->batchLoadCustomerGroups((string) $customerId);

        $addresses = $addrMap[$customerId] ?? [];
        $stats = $statsMap[$customerId] ?? ['order_count' => 0, 'total_spent' => 0];

        $primaryAddr = !empty($addresses) ? $addresses[0] : null;
        $billing = $this->formatAddress($primaryAddr);
        $billing['email'] = (string) $row['email'];

        $shippingAddr = count($addresses) > 1 ? $addresses[1] : $primaryAddr;
        $shipping = $this->formatAddress($shippingAddr);

        $phone = $billing['phone'] ?: ($shipping['phone'] ?? '');
        $city = $billing['city'] ?: ($shipping['city'] ?? '');
        $country = $billing['country'] ?: ($shipping['country'] ?? '');

        $metaData = [];
        $customerGroups = $this->formatCustomerGroups($customerId, (int) ($row['id_default_group'] ?? 0), $groupsMap);
        if (!empty($row['newsletter'])) {
            $metaData[] = ['key' => 'newsletter', 'value' => (string) $row['newsletter']];
        }
        if (!empty($customerGroups['default_group_id'])) {
            $metaData[] = ['key' => 'default_group_id', 'value' => (string) $customerGroups['default_group_id']];
        }
        if (!empty($customerGroups['default_group_name'])) {
            $metaData[] = ['key' => 'default_group_name', 'value' => (string) $customerGroups['default_group_name']];
        }
        if (!empty($row['birthday']) && $row['birthday'] !== '0000-00-00') {
            $metaData[] = ['key' => 'birthday', 'value' => (string) $row['birthday']];
        }
        if (!empty($row['gender_name'])) {
            $metaData[] = ['key' => 'gender', 'value' => (string) $row['gender_name']];
        }
        if ($stats['order_count'] > 0) {
            $metaData[] = ['key' => 'orders_count', 'value' => (string) $stats['order_count']];
            $metaData[] = ['key' => 'total_spent', 'value' => (string) round($stats['total_spent'], 2)];
        }
        $metaData = $this->mergeMeta($metaData, $this->extraColumnsMetaGroups([
            [$row, self::$customerMappedColumns, ''],
            [$primaryAddr, self::$addressMappedColumns, 'billing_'],
            [$shippingAddr, self::$addressMappedColumns, 'shipping_'],
        ]));

        return [
            'id' => $customerId,
            'shop' => $this->getShopContext((int) ($row['id_shop'] ?? $this->idShop), (int) ($row['id_shop_group'] ?? 0)),
            'customer_groups' => $customerGroups,
            'email' => (string) $row['email'],
            'first_name' => (string) $row['firstname'],
            'last_name' => (string) $row['lastname'],
            'company' => (string) ($row['company'] ?? ''),
            'phone' => $phone,
            'city' => $city,
            'country' => $country,
            'date_created' => $this->toIso($row['date_add']),
            'billing' => $billing,
            'shipping' => $shipping,
            'meta_data' => $metaData,
        ];
    }

    private function formatSingleProduct($productId)
    {
        $sql = 'SELECT p.*,
                   pl.name, pl.description, pl.description_short, pl.link_rewrite,
                   sa.quantity AS stock_quantity,
                   s.name AS supplier_name
            FROM ' . $this->prefix . 'product p
            LEFT JOIN ' . $this->prefix . 'product_lang pl
                ON (p.id_product = pl.id_product AND pl.id_lang = ' . $this->idLang . ' AND pl.id_shop = ' . $this->idShop . ')
            LEFT JOIN ' . $this->prefix . 'stock_available sa
                ON (p.id_product = sa.id_product AND sa.id_product_attribute = 0 AND sa.id_shop = ' . $this->idShop . ')
            LEFT JOIN ' . $this->prefix . 'supplier s ON (p.id_supplier = s.id_supplier)
            WHERE p.id_product = ' . $productId;

        $rows = $this->safeQuery($sql, 'single_product');
        if (!is_array($rows) || empty($rows)) {
            return null;
        }
        $row = $rows[0];

        $pid = (int) $row['id_product'];
        $pidList = (string) $pid;
        $basePrices = [$pid => (float) $row['price']];

        $categoriesMap = $this->batchLoadProductCategories($pidList);
        $tagsMap = $this->batchLoadProductTags($pidList);
        $imagesMap = $this->batchLoadProductImages($pidList);
        $salePriceMap = $this->batchLoadSpecificPrices($pidList, $basePrices);
        $suppliersMap = $this->batchLoadProductSuppliers($pidList);
        $featuresMap = $this->batchLoadProductFeatures($pidList);

        $stockQty = min((int) ($row['stock_quantity'] ?? 0), 2147483647);
        $images = $imagesMap[$pid] ?? [];
        $imageUrl = !empty($images) ? $images[0] : null;
        $imageList = array_map(function ($src) { return ['src' => $src]; }, $images);
        $salePrice = $salePriceMap[$pid] ?? null;
        $displayPrices = $this->getDisplayPriceValues($pid);

        return [
            'id' => $pid,
            'shop' => $this->getShopContext(),
            'name' => (string) ($row['name'] ?? ''),
            'slug' => (string) ($row['link_rewrite'] ?? ''),
            'permalink' => $this->link->getProductLink($pid),
            'type' => 'simple',
            'status' => $row['active'] ? 'publish' : 'draft',
            'description' => (string) ($row['description'] ?? ''),
            'short_description' => (string) ($row['description_short'] ?? ''),
            'sku' => (string) ($row['reference'] ?? ''),
            'price' => (string) round((float) $row['price'], 2),
            'regular_price' => (string) round((float) $row['price'], 2),
            'sale_price' => $salePrice,
            'display_price' => $displayPrices['price'],
            'display_regular_price' => $displayPrices['regular_price'],
            'display_sale_price' => $displayPrices['sale_price'],
            'display_price_includes_tax' => true,
            'on_sale' => !empty($salePrice),
            'stock_status' => $stockQty > 0 ? 'instock' : 'outofstock',
            'stock_quantity' => $stockQty,
            'manage_stock' => true,
            'weight' => (string) ($row['weight'] ?? ''),
            'ean13' => (string) ($row['ean13'] ?? ''),
            'upc' => (string) ($row['upc'] ?? ''),
            'isbn' => (string) ($row['isbn'] ?? ''),
            'condition' => (string) ($row['condition'] ?? 'new'),
            'supplier_id' => (int) ($row['id_supplier'] ?? 0),
            'supplier_name' => (string) ($row['supplier_name'] ?? ''),
            'supplier_reference' => (string) ($row['supplier_reference'] ?? ''),
            'suppliers' => $suppliersMap[$pid] ?? [],
            'features' => $featuresMap[$pid] ?? [],
            'wholesale_price' => (string) round((float) ($row['wholesale_price'] ?? 0), 2),
            'category_ids' => $categoriesMap[$pid] ?? [],
            'tags' => $tagsMap[$pid] ?? [],
            'parent_id' => null,
            'image_url' => $imageUrl,
            'images' => $imageList,
            'date_created' => $this->toIso($row['date_add']),
            'date_modified' => $this->toIso($row['date_upd']),
            'meta_data' => $this->extraColumnsMeta($row, self::$productMappedColumns),
        ];
    }

    private function formatSingleProductVariant($productId, $attributeId)
    {
        $product = $this->formatSingleProduct($productId);
        if ($product === null) {
            return null;
        }

        $combinations = $this->batchLoadCombinations((string) $productId);
        $combination = null;
        foreach ($combinations[$productId] ?? [] as $candidate) {
            if ((int) $candidate['id_product_attribute'] === $attributeId) {
                $combination = $candidate;
                break;
            }
        }
        if ($combination === null) {
            return null;
        }

        $displayPrices = $this->getDisplayPriceValues($productId, $attributeId);
        $regularPrice = (float) $product['regular_price'] + (float) ($combination['price_impact'] ?? 0);
        $stockQty = min((int) ($combination['quantity'] ?? 0), 2147483647);
        $name = $product['name'];
        if (!empty($combination['attributes'])) {
            $name .= ' - ' . $combination['attributes'];
        }

        $product['id'] = $productId . '_' . $attributeId;
        $product['name'] = $name;
        $product['permalink'] = $this->link->getProductLink($productId, null, null, null, null, null, $attributeId);
        $product['type'] = 'variation';
        $product['sku'] = (string) ($combination['reference'] ?? $product['sku']);
        $product['price'] = (string) round($regularPrice, 2);
        $product['regular_price'] = (string) round($regularPrice, 2);
        $product['sale_price'] = null;
        $product['display_price'] = $displayPrices['price'];
        $product['display_regular_price'] = $displayPrices['regular_price'];
        $product['display_sale_price'] = $displayPrices['sale_price'];
        $product['on_sale'] = $displayPrices['sale_price'] !== null;
        $product['stock_status'] = $stockQty > 0 ? 'instock' : 'outofstock';
        $product['stock_quantity'] = $stockQty;
        $product['ean13'] = (string) ($combination['ean13'] ?? '');
        $product['upc'] = (string) ($combination['upc'] ?? '');
        $product['category_ids'] = [];
        $product['parent_id'] = $productId;
        $product['images'] = [];

        return $product;
    }

    private function getDisplayPriceValues($productId, $attributeId = null)
    {
        try {
            $regularPrice = (float) Product::getPriceStatic(
                (int) $productId,
                true,
                $attributeId !== null ? (int) $attributeId : null,
                6,
                null,
                false,
                false,
                1
            );
            $effectivePrice = (float) Product::getPriceStatic(
                (int) $productId,
                true,
                $attributeId !== null ? (int) $attributeId : null,
                6,
                null,
                false,
                true,
                1
            );

            return [
                'price' => (string) round($effectivePrice, 2),
                'regular_price' => (string) round($regularPrice, 2),
                'sale_price' => $effectivePrice < $regularPrice ? (string) round($effectivePrice, 2) : null,
            ];
        } catch (Throwable $e) {
            return [
                'price' => null,
                'regular_price' => null,
                'sale_price' => null,
            ];
        }
    }

    private function formatSingleCategory($categoryId)
    {
        $sql = 'SELECT c.*,
                   cl.name, cl.description, cl.link_rewrite,
                   COALESCE(cp_count.cnt, 0) AS product_count
            FROM ' . $this->prefix . 'category c
            LEFT JOIN (SELECT id_category, COUNT(*) AS cnt FROM ' . $this->prefix . 'category_product GROUP BY id_category) cp_count
                ON cp_count.id_category = c.id_category
            LEFT JOIN ' . $this->prefix . 'category_lang cl
                ON (c.id_category = cl.id_category AND cl.id_lang = ' . $this->idLang . ' AND cl.id_shop = ' . $this->idShop . ')
            WHERE c.id_category = ' . $categoryId;

        $rows = $this->safeQuery($sql, 'single_category');
        if (!is_array($rows) || empty($rows)) {
            return null;
        }
        $row = $rows[0];

        return [
            'id' => (int) $row['id_category'],
            'shop' => $this->getShopContext(),
            'name' => (string) ($row['name'] ?? ''),
            'slug' => (string) ($row['link_rewrite'] ?? ''),
            'parent_id' => (int) $row['id_parent'] ?: null,
            'description' => (string) ($row['description'] ?? ''),
            'count' => (int) ($row['product_count'] ?? 0),
            'image_url' => null,
            'meta_data' => $this->extraColumnsMeta($row, self::$categoryMappedColumns),
        ];
    }

    private function formatSingleCoupon($cartRuleId)
    {
        $sql = 'SELECT cr.*,
                   crl.name,
                   COALESCE(ocr_count.cnt, 0) AS usage_count
            FROM ' . $this->prefix . 'cart_rule cr
            LEFT JOIN (SELECT id_cart_rule, COUNT(DISTINCT id_order) AS cnt FROM ' . $this->prefix . 'order_cart_rule GROUP BY id_cart_rule) ocr_count
                ON ocr_count.id_cart_rule = cr.id_cart_rule
            LEFT JOIN ' . $this->prefix . 'cart_rule_lang crl
                ON (cr.id_cart_rule = crl.id_cart_rule AND crl.id_lang = ' . $this->idLang . ')
            WHERE cr.id_cart_rule = ' . $cartRuleId;

        $rows = $this->safeQuery($sql, 'single_coupon');
        if (!is_array($rows) || empty($rows)) {
            return null;
        }
        $row = $rows[0];

        $discountType = 'fixed_cart';
        $amount = (float) $row['reduction_amount'];
        if ((float) $row['reduction_percent'] > 0) {
            $discountType = 'percent';
            $amount = (float) $row['reduction_percent'];
        }
        $restrictionsMap = $this->batchLoadCartRuleRestrictions((string) $cartRuleId);

        return [
            'id' => (int) $row['id_cart_rule'],
            'shop' => $this->getShopContext(),
            'code' => (string) ($row['code'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'discount_type' => $discountType,
            'amount' => (string) $amount,
            'usage_count' => (int) ($row['usage_count'] ?? 0),
            'usage_limit' => (int) ($row['quantity'] ?? 0),
            'usage_limit_per_user' => (int) ($row['quantity_per_user'] ?? 0),
            'free_shipping' => (bool) $row['free_shipping'],
            'minimum_amount' => (string) ((float) ($row['minimum_amount'] ?? 0)),
            'maximum_amount' => null,
            'restrictions' => [
                'group_restriction' => !empty($row['group_restriction']),
                'shop_restriction' => !empty($row['shop_restriction']),
                'groups' => $restrictionsMap[$cartRuleId]['groups'] ?? [],
                'shops' => $restrictionsMap[$cartRuleId]['shops'] ?? [],
            ],
            'date_created' => $this->toIso($row['date_add']),
            'date_expires' => ($row['date_to'] && $row['date_to'] !== '0000-00-00 00:00:00')
                ? $this->toIso($row['date_to']) : null,
            'meta_data' => $this->extraColumnsMeta($row, self::$couponMappedColumns),
        ];
    }

    private function formatSingleRefund($slipId)
    {
        $sql = 'SELECT os.*,
                   o.id_shop, o.id_shop_group
            FROM ' . $this->prefix . 'order_slip os
            LEFT JOIN ' . $this->prefix . 'orders o ON (os.id_order = o.id_order)
            WHERE os.id_order_slip = ' . $slipId;

        $rows = $this->safeQuery($sql, 'single_refund');
        if (!is_array($rows) || empty($rows)) {
            return null;
        }
        $row = $rows[0];

        $detailMap = $this->batchLoadSlipDetails((string) $slipId);

        $totalProducts = (float) $row['total_products_tax_incl'];
        $totalShipping = (float) $row['total_shipping_tax_incl'];
        $totalAmount = $totalProducts + $totalShipping;

        return [
            'id' => (int) $row['id_order_slip'],
            'shop' => $this->getShopContext((int) ($row['id_shop'] ?? $this->idShop), (int) ($row['id_shop_group'] ?? 0)),
            'parent_id' => (int) $row['id_order'],
            'amount' => (string) round(abs($totalAmount), 2),
            'reason' => '',
            'date_created' => $this->toIso($row['date_add']),
            'refunded_by' => null,
            'line_items' => $detailMap[$slipId] ?? [],
        ];
    }
}
