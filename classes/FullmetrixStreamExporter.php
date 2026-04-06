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
    private $orderColumnsCache;
    private $cartRuleColumnsCache;

    /**
     * @param int $idShop Shop ID
     * @param \Link|null $link PrestaShop Link instance for URL generation
     */
    public function __construct($idShop = 1, $link = null)
    {
        $this->db = Db::getInstance(_PS_USE_SQL_SLAVE_);
        $this->prefix = _DB_PREFIX_;
        $this->idLang = (int) Configuration::get('PS_LANG_DEFAULT') ?: 1;
        $this->idShop = (int) $idShop;
        $this->link = $link;
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

        // MySQL: "2025-03-16 14:30:00" → ISO: "2025-03-16T14:30:00Z"
        // Date-only: "2025-03-16" → "2025-03-16T00:00:00Z"
        if (strlen($d) === 10) {
            return $d . 'T00:00:00Z';
        }

        return str_replace(' ', 'T', $d) . 'Z';
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
        } catch (\Throwable $e) {
            $this->sendLine([
                'type' => 'error',
                'message' => 'Fatal error streaming ' . $entity . ': ' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }

        $this->sendLine([
            'type' => 'done',
            'completed_at' => gmdate('c'),
            'count' => $count,
        ]);

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
        ]);

        $count = $this->streamOrdersFast($syncType, $since);

        $this->sendLine([
            'type' => 'done',
            'completed_at' => gmdate('c'),
            'count' => $count,
        ]);

        $this->finishStream();
        exit;
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
        // Let the web server (Apache/nginx) handle gzip via mod_deflate —
        // doing it in PHP with ob_gzhandler breaks progressive streaming
    }

    private function finishStream()
    {
        // No-op — kept for forward compatibility
    }

    private function detectOrderColumns()
    {
        $cols = ['note' => false, 'total_wrapping_tax_incl' => false];
        try {
            $result = $this->db->executeS(
                'SHOW COLUMNS FROM ' . $this->prefix . 'orders WHERE Field IN ("note", "total_wrapping_tax_incl")'
            );
            if (is_array($result)) {
                foreach ($result as $row) {
                    $cols[$row['Field']] = true;
                }
            }
        } catch (\Throwable $e) {
            // Fallback: assume columns don't exist
        }

        return $cols;
    }

    private function streamOrdersFast($syncType, $since)
    {
        $count = 0;
        $lastId = 0;

        $sinceWhere = '';
        if ($syncType === 'incremental' && $since) {
            $sinceWhere = ' AND o.date_upd > \'' . pSQL($since) . '\'';
        }

        // Detect available optional columns once per sync (cached)
        if ($this->orderColumnsCache === null) {
            $this->orderColumnsCache = $this->detectOrderColumns();
        }
        $optionalCols = $this->orderColumnsCache;

        while (true) {
            $noteCol = $optionalCols['note'] ? ', o.note' : ', \'\' AS note';
            $wrappingCol = $optionalCols['total_wrapping_tax_incl']
                ? ', o.total_wrapping_tax_incl'
                : ', 0 AS total_wrapping_tax_incl';

            $sql = 'SELECT o.id_order, o.reference, o.id_customer, o.id_currency,
                       o.id_address_delivery, o.id_address_invoice,
                       o.current_state, o.module AS payment_method,
                       o.payment AS payment_method_title,
                       o.total_paid_tax_incl, o.total_paid_tax_excl,
                       o.total_discounts_tax_incl,
                       o.total_shipping_tax_incl
                       ' . $wrappingCol . ',
                       o.total_products, o.total_products_wt,
                       o.invoice_number, o.delivery_number
                       ' . $noteCol . ',
                       o.date_add, o.date_upd,
                       osl.name AS status_name,
                       c.email AS customer_email,
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
            foreach ($rows as $row) {
                $oid = (int) $row['id_order'];
                $orderIds[] = $oid;
                $addressIds[] = (int) $row['id_address_invoice'];
                $addressIds[] = (int) $row['id_address_delivery'];
                $references[] = pSQL($row['reference']);
            }
            $orderIdsList = implode(',', $orderIds);
            $addressIds = array_unique(array_filter($addressIds));

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

                $this->sendLine([
                    'type' => 'order',
                    'data' => [
                        'id' => $oid,
                        'number' => (string) ($row['reference'] ?: $oid),
                        'status' => (string) ($row['status_name'] ?: 'unknown'),
                        'currency' => (string) ($row['currency_code'] ?: 'EUR'),
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
                    ],
                ]);

                ++$count;
            }

            $lastId = (int) end($rows)['id_order'];
            unset($rows, $addressMap, $lineItemsMap, $carriersMap, $couponMap, $paymentMap);
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

        $sql = 'SELECT a.id_address, a.firstname, a.lastname, a.company,
                   a.address1, a.address2, a.city, a.postcode,
                   a.phone, a.phone_mobile, a.vat_number,
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
        $sql = 'SELECT od.id_order, od.id_order_detail, od.product_name,
                   od.product_quantity, od.product_reference,
                   od.product_id, od.product_attribute_id,
                   od.unit_price_tax_excl, od.unit_price_tax_incl,
                   od.total_price_tax_excl, od.total_price_tax_incl,
                   od.reduction_percent, od.reduction_amount_tax_incl,
                   od.tax_name, od.tax_rate,
                   od.product_ean13, od.product_upc
            FROM ' . $this->prefix . 'order_detail od
            WHERE od.id_order IN (' . $orderIdsList . ')';

        $rows = $this->safeQuery($sql, 'order_line_items');
        $map = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $oid = (int) $row['id_order'];
                $qty = min(max(1, (int) $row['product_quantity']), 2147483647);
                $pid = (int) $row['product_id'];
                $aid = (int) $row['product_attribute_id'];
                $price = (float) $row['unit_price_tax_excl'];
                $total = (float) $row['total_price_tax_incl'];

                $map[$oid][] = [
                    'id' => (int) $row['id_order_detail'],
                    'name' => (string) $row['product_name'],
                    'product_id' => $pid ?: null,
                    'variation_id' => $aid > 0 ? $pid . '_' . $aid : null,
                    'quantity' => $qty,
                    'price' => (string) round($price, 2),
                    'total' => (string) round($total, 2),
                    'sku' => (string) ($row['product_reference'] ?? ''),
                    'tax_name' => (string) ($row['tax_name'] ?? ''),
                    'tax_rate' => (string) ($row['tax_rate'] ?? '0'),
                    'ean13' => (string) ($row['product_ean13'] ?? ''),
                    'upc' => (string) ($row['product_upc'] ?? ''),
                    'reduction_percent' => (string) ($row['reduction_percent'] ?? '0'),
                    'reduction_amount' => (string) ($row['reduction_amount_tax_incl'] ?? '0'),
                ];
            }
        }

        return $map;
    }

    private function batchLoadOrderCarriers($orderIdsList)
    {
        $sql = 'SELECT oc.id_order, oc.id_order_carrier, oc.id_carrier,
                   oc.tracking_number, oc.shipping_cost_tax_incl,
                   ca.name AS carrier_name
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
        } catch (\Throwable $e) {
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

        $sql = 'SELECT op.order_reference, op.payment_method, op.amount,
                   op.transaction_id, op.card_brand, op.date_add
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
            $sql = 'SELECT os.id_order_slip, os.id_order, os.id_customer,
                       os.total_products_tax_incl, os.total_shipping_tax_incl,
                       os.amount, os.shipping_cost_amount,
                       os.date_add, os.date_upd
                FROM ' . $this->prefix . 'order_slip os
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
                        'parent_id' => (int) $row['id_order'],
                        'amount' => (string) round(abs($totalAmount), 2),
                        'reason' => '',
                        'date_created' => $this->toIso($row['date_add']),
                        'refunded_by' => null,
                        'line_items' => $detailMap[$sid] ?? [],
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
        $sql = 'SELECT osd.id_order_slip, osd.id_order_detail,
                   osd.product_quantity, osd.amount_tax_incl
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
            $sql = 'SELECT c.id_customer, c.email, c.firstname, c.lastname,
                       c.company, c.birthday, c.newsletter, c.optin,
                       c.website, c.siret, c.ape, c.note,
                       c.id_gender, c.id_default_group,
                       c.date_add, c.date_upd,
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
                if (!empty($row['newsletter'])) {
                    $metaData[] = ['key' => 'newsletter', 'value' => (string) $row['newsletter']];
                }
                if (!empty($row['birthday']) && $row['birthday'] !== '0000-00-00') {
                    $metaData[] = ['key' => 'birthday', 'value' => (string) $row['birthday']];
                }
                if (!empty($row['gender_name'])) {
                    $metaData[] = ['key' => 'gender', 'value' => (string) $row['gender_name']];
                }
                if (!empty($row['siret'])) {
                    $metaData[] = ['key' => 'siret', 'value' => (string) $row['siret']];
                }
                if ($stats['order_count'] > 0) {
                    $metaData[] = ['key' => 'orders_count', 'value' => (string) $stats['order_count']];
                    $metaData[] = ['key' => 'total_spent', 'value' => (string) round($stats['total_spent'], 2)];
                }

                $this->sendLine([
                    'type' => 'customer',
                    'data' => [
                        'id' => $cid,
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
            unset($rows, $addrMap, $statsMap);
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
        $sql = 'SELECT a.id_customer, a.id_address, a.alias, a.firstname, a.lastname,
                   a.company, a.address1, a.address2, a.city, a.postcode,
                   a.phone, a.phone_mobile, a.vat_number,
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
            $sql = 'SELECT p.id_product, p.reference, p.price, p.wholesale_price,
                       p.active, p.weight, p.width, p.height, p.depth,
                       p.ean13, p.upc, p.isbn, p.condition, p.visibility,
                       p.id_manufacturer, p.id_tax_rules_group, p.on_sale,
                       p.date_add, p.date_upd,
                       pl.name, pl.description, pl.description_short, pl.link_rewrite,
                       sa.quantity AS stock_quantity,
                       m.name AS manufacturer_name
                FROM ' . $this->prefix . 'product p
                LEFT JOIN ' . $this->prefix . 'product_lang pl
                    ON (p.id_product = pl.id_product AND pl.id_lang = ' . $this->idLang . ' AND pl.id_shop = ' . $this->idShop . ')
                LEFT JOIN ' . $this->prefix . 'stock_available sa
                    ON (p.id_product = sa.id_product AND sa.id_product_attribute = 0 AND sa.id_shop = ' . $this->idShop . ')
                LEFT JOIN ' . $this->prefix . 'manufacturer m ON (p.id_manufacturer = m.id_manufacturer)
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

            foreach ($rows as $row) {
                $pid = (int) $row['id_product'];
                $hasCombinations = !empty($combosMap[$pid]);
                $stockQty = min((int) ($row['stock_quantity'] ?? 0), 2147483647);

                $images = $imagesMap[$pid] ?? [];
                $imageUrl = !empty($images) ? $images[0] : null;
                $imageList = array_map(function ($src) { return ['src' => $src]; }, $images);

                $salePrice = $salePriceMap[$pid] ?? null;

                $this->sendLine([
                    'type' => 'product',
                    'data' => [
                        'id' => $pid,
                        'name' => (string) ($row['name'] ?? ''),
                        'slug' => (string) ($row['link_rewrite'] ?? ''),
                        'permalink' => \Context::getContext()->link->getProductLink($pid),
                        'type' => $hasCombinations ? 'variable' : 'simple',
                        'status' => $row['active'] ? 'publish' : 'draft',
                        'description' => (string) ($row['description'] ?? ''),
                        'short_description' => (string) ($row['description_short'] ?? ''),
                        'sku' => (string) ($row['reference'] ?? ''),
                        'price' => (string) round((float) $row['price'], 2),
                        'regular_price' => (string) round((float) $row['price'], 2),
                        'sale_price' => $salePrice,
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
                        'wholesale_price' => (string) round((float) ($row['wholesale_price'] ?? 0), 2),
                        'category_ids' => $categoriesMap[$pid] ?? [],
                        'tags' => $tagsMap[$pid] ?? [],
                        'parent_id' => null,
                        'image_url' => $imageUrl,
                        'images' => $imageList,
                        'date_created' => $this->toIso($row['date_add']),
                        'date_modified' => $this->toIso($row['date_upd']),
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

                        $comboName = (string) ($row['name'] ?? '');
                        if (!empty($combo['attributes'])) {
                            $comboName .= ' - ' . $combo['attributes'];
                        }

                        $this->sendLine([
                            'type' => 'product',
                            'data' => [
                                'id' => $pid . '_' . $aid,
                                'name' => $comboName,
                                'slug' => (string) ($row['link_rewrite'] ?? ''),
                                'permalink' => \Context::getContext()->link->getProductLink($pid, null, null, null, null, null, $aid),
                                'type' => 'variation',
                                'status' => $row['active'] ? 'publish' : 'draft',
                                'sku' => $comboRef,
                                'price' => (string) round($comboPrice, 2),
                                'regular_price' => (string) round($comboPrice, 2),
                                'sale_price' => null,
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
            unset($rows, $categoriesMap, $tagsMap, $imagesMap, $salePriceMap, $combosMap);
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
            $sql = 'SELECT c.id_category, c.id_parent, c.active, c.level_depth,
                       c.date_add, c.date_upd,
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

            foreach ($rows as $row) {
                $cid = (int) $row['id_category'];
                $this->sendLine([
                    'type' => 'category',
                    'data' => [
                        'id' => $cid,
                        'name' => (string) ($row['name'] ?? ''),
                        'slug' => (string) ($row['link_rewrite'] ?? ''),
                        'parent_id' => (int) $row['id_parent'] ?: null,
                        'description' => (string) ($row['description'] ?? ''),
                        'count' => $countMap[$cid] ?? 0,
                        'image_url' => null,
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
            $sql = 'SELECT cr.id_cart_rule, cr.code, cr.description,
                       cr.reduction_percent, cr.reduction_amount, cr.reduction_currency,
                       cr.free_shipping, cr.active, cr.quantity, cr.quantity_per_user,
                       cr.minimum_amount, cr.minimum_amount_currency,
                       cr.date_from, cr.date_to, cr.date_add,
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
                        'date_created' => $this->toIso($row['date_add']),
                        'date_expires' => ($row['date_to'] && $row['date_to'] !== '0000-00-00 00:00:00')
                            ? $this->toIso($row['date_to']) : null,
                    ],
                ]);
                ++$count;
            }

            $lastId = (int) end($rows)['id_cart_rule'];
            unset($rows);
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
        } catch (\Throwable $e) {
            return 'https://unknown';
        }
    }

    private function safeQuery($sql, $context = '', $retries = 2)
    {
        for ($attempt = 0; $attempt <= $retries; ++$attempt) {
            try {
                $rows = $this->db->executeS($sql);
            } catch (\Throwable $e) {
                if ($attempt < $retries) {
                    usleep(200000 * ($attempt + 1)); // 200ms, 400ms
                    continue;
                }
                $this->sendLine([
                    'type' => 'error',
                    'message' => 'SQL exception' . ($context ? ' [' . $context . ']' : '') . ': ' . $e->getMessage(),
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
        echo json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
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
        } catch (\Throwable $e) {
            // fallback
        }

        return $afterId + $this->batchSize;
    }

    private $gcCounter = 0;

    private function maybeGc()
    {
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
    }

    private function adaptBatchSize()
    {
        if (!$this->memoryLimitBytes) {
            return;
        }
        $memPct = memory_get_usage(true) / $this->memoryLimitBytes;
        if ($memPct > 0.7 && $this->batchSize > 100) {
            $this->batchSize = max(100, (int) ($this->batchSize * 0.5));
        } elseif ($memPct < 0.4 && $this->batchSize < 2000) {
            $this->batchSize = min(2000, (int) ($this->batchSize * 1.5));
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
     * @return array|null
     */
    public function formatSingleEntity($entityType, $id)
    {
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
        $optionalCols = $this->detectOrderColumns();
        $wrappingCol = $optionalCols['total_wrapping_tax_incl']
            ? ', o.total_wrapping_tax_incl'
            : ', 0 AS total_wrapping_tax_incl';
        $noteCol = $optionalCols['note'] ? ', o.note' : ', \'\' AS note';

        $sql = 'SELECT o.id_order, o.reference, o.id_customer, o.id_currency,
                   o.id_address_delivery, o.id_address_invoice,
                   o.current_state, o.module AS payment_method,
                   o.payment AS payment_method_title,
                   o.total_paid_tax_incl, o.total_paid_tax_excl,
                   o.total_discounts_tax_incl,
                   o.total_shipping_tax_incl
                   ' . $wrappingCol . ',
                   o.total_products, o.total_products_wt,
                   o.invoice_number, o.delivery_number
                   ' . $noteCol . ',
                   o.date_add, o.date_upd,
                   osl.name AS status_name,
                   c.email AS customer_email,
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

        $totalTaxIncl = (float) $row['total_paid_tax_incl'];
        $totalTaxExcl = (float) $row['total_paid_tax_excl'];
        $tax = max(0, $totalTaxIncl - $totalTaxExcl);

        $billing = $this->formatAddress($addressMap[(int) $row['id_address_invoice']] ?? null);
        $shipping = $this->formatAddress($addressMap[(int) $row['id_address_delivery']] ?? null);
        $billing['email'] = (string) ($row['customer_email'] ?? '');

        return [
            'id' => $orderId,
            'number' => (string) ($row['reference'] ?: $orderId),
            'status' => (string) ($row['status_name'] ?: 'unknown'),
            'currency' => (string) ($row['currency_code'] ?: 'EUR'),
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
        ];
    }

    private function formatSingleCustomer($customerId)
    {
        $sql = 'SELECT c.id_customer, c.email, c.firstname, c.lastname,
                   c.company, c.birthday, c.newsletter, c.optin,
                   c.id_gender, c.date_add, c.date_upd,
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
        if (!empty($row['newsletter'])) {
            $metaData[] = ['key' => 'newsletter', 'value' => (string) $row['newsletter']];
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

        return [
            'id' => $customerId,
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
        $sql = 'SELECT p.id_product, p.reference, p.price, p.wholesale_price,
                   p.active, p.weight, p.width, p.height, p.depth,
                   p.ean13, p.upc, p.isbn, p.condition, p.visibility,
                   p.on_sale, p.date_add, p.date_upd,
                   pl.name, pl.description, pl.description_short, pl.link_rewrite,
                   sa.quantity AS stock_quantity
            FROM ' . $this->prefix . 'product p
            LEFT JOIN ' . $this->prefix . 'product_lang pl
                ON (p.id_product = pl.id_product AND pl.id_lang = ' . $this->idLang . ' AND pl.id_shop = ' . $this->idShop . ')
            LEFT JOIN ' . $this->prefix . 'stock_available sa
                ON (p.id_product = sa.id_product AND sa.id_product_attribute = 0 AND sa.id_shop = ' . $this->idShop . ')
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

        $stockQty = min((int) ($row['stock_quantity'] ?? 0), 2147483647);
        $images = $imagesMap[$pid] ?? [];
        $imageUrl = !empty($images) ? $images[0] : null;
        $imageList = array_map(function ($src) { return ['src' => $src]; }, $images);
        $salePrice = $salePriceMap[$pid] ?? null;

        return [
            'id' => $pid,
            'name' => (string) ($row['name'] ?? ''),
            'slug' => (string) ($row['link_rewrite'] ?? ''),
            'permalink' => \Context::getContext()->link->getProductLink($pid),
            'type' => 'simple',
            'status' => $row['active'] ? 'publish' : 'draft',
            'description' => (string) ($row['description'] ?? ''),
            'short_description' => (string) ($row['description_short'] ?? ''),
            'sku' => (string) ($row['reference'] ?? ''),
            'price' => (string) round((float) $row['price'], 2),
            'regular_price' => (string) round((float) $row['price'], 2),
            'sale_price' => $salePrice,
            'on_sale' => !empty($salePrice),
            'stock_status' => $stockQty > 0 ? 'instock' : 'outofstock',
            'stock_quantity' => $stockQty,
            'manage_stock' => true,
            'weight' => (string) ($row['weight'] ?? ''),
            'ean13' => (string) ($row['ean13'] ?? ''),
            'upc' => (string) ($row['upc'] ?? ''),
            'isbn' => (string) ($row['isbn'] ?? ''),
            'condition' => (string) ($row['condition'] ?? 'new'),
            'wholesale_price' => (string) round((float) ($row['wholesale_price'] ?? 0), 2),
            'category_ids' => $categoriesMap[$pid] ?? [],
            'tags' => $tagsMap[$pid] ?? [],
            'parent_id' => null,
            'image_url' => $imageUrl,
            'images' => $imageList,
            'date_created' => $this->toIso($row['date_add']),
            'date_modified' => $this->toIso($row['date_upd']),
        ];
    }

    private function formatSingleCategory($categoryId)
    {
        $sql = 'SELECT c.id_category, c.id_parent, c.active, c.date_add, c.date_upd,
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
            'name' => (string) ($row['name'] ?? ''),
            'slug' => (string) ($row['link_rewrite'] ?? ''),
            'parent_id' => (int) $row['id_parent'] ?: null,
            'description' => (string) ($row['description'] ?? ''),
            'count' => (int) ($row['product_count'] ?? 0),
            'image_url' => null,
        ];
    }

    private function formatSingleCoupon($cartRuleId)
    {
        $sql = 'SELECT cr.id_cart_rule, cr.code, cr.description,
                   cr.reduction_percent, cr.reduction_amount, cr.reduction_currency,
                   cr.free_shipping, cr.active, cr.quantity, cr.quantity_per_user,
                   cr.minimum_amount, cr.minimum_amount_currency,
                   cr.date_from, cr.date_to, cr.date_add,
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

        return [
            'id' => (int) $row['id_cart_rule'],
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
            'date_created' => $this->toIso($row['date_add']),
            'date_expires' => ($row['date_to'] && $row['date_to'] !== '0000-00-00 00:00:00')
                ? $this->toIso($row['date_to']) : null,
        ];
    }

    private function formatSingleRefund($slipId)
    {
        $sql = 'SELECT os.id_order_slip, os.id_order, os.id_customer,
                   os.total_products_tax_incl, os.total_shipping_tax_incl,
                   os.amount, os.shipping_cost_amount,
                   os.date_add
            FROM ' . $this->prefix . 'order_slip os
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
            'parent_id' => (int) $row['id_order'],
            'amount' => (string) round(abs($totalAmount), 2),
            'reason' => '',
            'date_created' => $this->toIso($row['date_add']),
            'refunded_by' => null,
            'line_items' => $detailMap[$slipId] ?? [],
        ];
    }
}
