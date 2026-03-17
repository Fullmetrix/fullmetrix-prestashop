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

class FullmetrixFastExporter
{
    const VERSION = '3.2.6';

    private $db;
    private $prefix;
    private $idLang;
    private $idShop;
    private $link;

    /**
     * @param int        $idShop Shop ID
     * @param \Link|null $link   PrestaShop Link instance for URL generation
     */
    public function __construct($idShop = 1, $link = null)
    {
        $this->db = Db::getInstance(_PS_USE_SQL_SLAVE_);
        $this->prefix = _DB_PREFIX_;
        $this->idLang = (int) Configuration::get('PS_LANG_DEFAULT') ?: 1;
        $this->idShop = (int) $idShop;
        $this->link = $link;
    }

    public function exportOrdersFast($page = 1, $perPage = 100, $since = null)
    {
        $offset = ($page - 1) * $perPage;

        $where = 'WHERE 1=1';
        if ($since) {
            $where .= ' AND o.date_upd > \'' . pSQL($since) . '\'';
        }

        $countQuery = "SELECT COUNT(*) FROM {$this->prefix}orders o {$where}";
        $total = (int) $this->db->getValue($countQuery);
        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;

        $query = "
            SELECT
                o.id_order,
                o.reference,
                o.id_customer,
                o.id_currency,
                o.id_address_delivery,
                o.id_address_invoice,
                o.current_state,
                o.module as payment_method,
                o.total_paid_tax_incl,
                o.total_paid_tax_excl,
                o.total_discounts,
                o.total_shipping,
                o.date_add,
                o.date_upd,
                osl.name as status_name,
                c.email as customer_email,
                cur.iso_code as currency_code,
                ai.firstname,
                ai.lastname,
                ai.phone,
                ai.phone_mobile,
                ai.city,
                ai.id_country,
                co.iso_code as country_code
            FROM {$this->prefix}orders o
            LEFT JOIN {$this->prefix}order_state_lang osl ON (o.current_state = osl.id_order_state AND osl.id_lang = {$this->idLang})
            LEFT JOIN {$this->prefix}customer c ON (o.id_customer = c.id_customer)
            LEFT JOIN {$this->prefix}currency cur ON (o.id_currency = cur.id_currency)
            LEFT JOIN {$this->prefix}address ai ON (o.id_address_invoice = ai.id_address)
            LEFT JOIN {$this->prefix}country co ON (ai.id_country = co.id_country)
            {$where}
            ORDER BY o.id_order ASC
            LIMIT {$perPage} OFFSET {$offset}
        ";

        $rows = $this->db->executeS($query);
        $orders = [];

        if (is_array($rows)) {
            $orderIds = array_column($rows, 'id_order');
            $lineItems = $this->getOrderLineItems($orderIds);

            foreach ($rows as $row) {
                $orderId = (int) $row['id_order'];
                $paidTaxIncl = (float) $row['total_paid_tax_incl'];
                $paidTaxExcl = (float) $row['total_paid_tax_excl'];

                $orders[] = [
                    'id' => $orderId,
                    'order_number' => (string) $row['reference'],
                    'status' => (string) ($row['status_name'] ?: 'unknown'),
                    'currency' => (string) ($row['currency_code'] ?: 'EUR'),
                    'total' => round($paidTaxIncl, 2),
                    'discount_total' => round((float) $row['total_discounts'], 2),
                    'shipping_total' => round((float) $row['total_shipping'], 2),
                    'tax_total' => round(max(0, $paidTaxIncl - $paidTaxExcl), 2),
                    'date_created' => $row['date_add'] ? gmdate('c', strtotime($row['date_add'])) : null,
                    'date_modified' => $row['date_upd'] ? gmdate('c', strtotime($row['date_upd'])) : null,
                    'date_paid' => null,
                    'customer_email' => (string) $row['customer_email'],
                    'billing_first_name' => (string) $row['firstname'],
                    'billing_last_name' => (string) $row['lastname'],
                    'billing_phone' => $row['phone_mobile'] ?: $row['phone'],
                    'billing_city' => (string) $row['city'],
                    'billing_country' => (string) ($row['country_code'] ?: ''),
                    'payment_method' => (string) $row['payment_method'],
                    'line_items' => $lineItems[$orderId] ?? [],
                ];
            }
        }

        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        return [
            'success' => true,
            'meta' => [
                'totalOrders' => $total,
                'currentPage' => $page,
                'totalPages' => $totalPages,
                'perPage' => $perPage,
                'storeUrl' => $this->getStoreUrl(),
                'pluginVersion' => self::VERSION,
                'exportedAt' => gmdate('c'),
                'mode' => 'fast',
            ],
            'orders' => $orders,
        ];
    }

    private function getOrderLineItems($orderIds)
    {
        if (empty($orderIds)) {
            return [];
        }

        $ids = implode(',', array_map('intval', $orderIds));

        $query = "
            SELECT
                od.id_order,
                od.id_order_detail,
                od.product_name,
                od.product_quantity,
                od.unit_price_tax_excl,
                od.total_price_tax_incl,
                od.product_id,
                od.product_attribute_id,
                od.product_reference
            FROM {$this->prefix}order_detail od
            WHERE od.id_order IN ({$ids})
            ORDER BY od.id_order, od.id_order_detail
        ";

        $rows = $this->db->executeS($query);
        $grouped = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $orderId = (int) $row['id_order'];
                $prodId = (int) $row['product_id'];
                $attrId = (int) $row['product_attribute_id'];

                $grouped[$orderId][] = [
                    'id' => (int) $row['id_order_detail'],
                    'name' => (string) $row['product_name'],
                    'quantity' => min((int) $row['product_quantity'], 2147483647),
                    'price' => round((float) $row['unit_price_tax_excl'], 2),
                    'total' => round((float) $row['total_price_tax_incl'], 2),
                    'product_id' => $prodId ?: null,
                    'variation_id' => $attrId > 0 ? $prodId . '_' . $attrId : null,
                    'sku' => (string) $row['product_reference'],
                ];
            }
        }

        return $grouped;
    }

    public function exportProductsFast($page = 1, $perPage = 100)
    {
        $offset = ($page - 1) * $perPage;

        $total = (int) $this->db->getValue("SELECT COUNT(*) FROM {$this->prefix}product");
        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;

        $query = "
            SELECT
                p.id_product,
                p.reference,
                p.price,
                p.active,
                p.date_add,
                p.date_upd,
                pl.name,
                pl.link_rewrite,
                sa.quantity,
                (SELECT GROUP_CONCAT(cp.id_category) FROM {$this->prefix}category_product cp WHERE cp.id_product = p.id_product) as category_ids,
                (SELECT COUNT(*) FROM {$this->prefix}product_attribute pa WHERE pa.id_product = p.id_product) as combination_count,
                img.id_image
            FROM {$this->prefix}product p
            LEFT JOIN {$this->prefix}product_lang pl ON (p.id_product = pl.id_product AND pl.id_lang = {$this->idLang} AND pl.id_shop = {$this->idShop})
            LEFT JOIN {$this->prefix}stock_available sa ON (p.id_product = sa.id_product AND sa.id_product_attribute = 0 AND sa.id_shop = {$this->idShop})
            LEFT JOIN {$this->prefix}image img ON (p.id_product = img.id_product AND img.cover = 1)
            ORDER BY p.id_product ASC
            LIMIT {$perPage} OFFSET {$offset}
        ";

        $rows = $this->db->executeS($query);
        $products = [];

        if (is_array($rows)) {
            $productIds = [];
            foreach ($rows as $row) {
                if ((int) $row['combination_count'] > 0) {
                    $productIds[] = (int) $row['id_product'];
                }
            }
            $combinations = $this->getProductCombinations($productIds);

            foreach ($rows as $row) {
                $productId = (int) $row['id_product'];
                $hasCombinations = (int) $row['combination_count'] > 0;
                $quantity = min((int) $row['quantity'], 2147483647);

                $imageUrl = null;
                if (!empty($row['id_image']) && !empty($row['link_rewrite'])) {
                    $imageUrl = $this->buildImageUrl($row['link_rewrite'], $row['id_image']);
                }

                $categoryIds = [];
                if (!empty($row['category_ids'])) {
                    $categoryIds = array_map('intval', explode(',', $row['category_ids']));
                }

                $products[] = [
                    'id' => $productId,
                    'name' => (string) $row['name'],
                    'sku' => (string) $row['reference'],
                    'type' => $hasCombinations ? 'variable' : 'simple',
                    'status' => $row['active'] ? 'publish' : 'draft',
                    'price' => (float) $row['price'],
                    'regular_price' => (float) $row['price'],
                    'sale_price' => null,
                    'stock_status' => $quantity > 0 ? 'instock' : 'outofstock',
                    'stock_quantity' => $quantity,
                    'category_ids' => $categoryIds,
                    'parent_id' => null,
                    'image_url' => $imageUrl,
                    'date_created' => $row['date_add'] ? gmdate('c', strtotime($row['date_add'])) : null,
                    'date_modified' => $row['date_upd'] ? gmdate('c', strtotime($row['date_upd'])) : null,
                ];

                if ($hasCombinations && isset($combinations[$productId])) {
                    foreach ($combinations[$productId] as $combo) {
                        $products[] = [
                            'id' => $productId . '_' . $combo['id_product_attribute'],
                            'name' => $row['name'] . ' - ' . $combo['attributes'],
                            'sku' => (string) $combo['reference'],
                            'type' => 'variation',
                            'status' => $row['active'] ? 'publish' : 'draft',
                            'price' => (float) $combo['price'],
                            'regular_price' => (float) $combo['price'],
                            'sale_price' => null,
                            'stock_status' => (int) $combo['quantity'] > 0 ? 'instock' : 'outofstock',
                            'stock_quantity' => min((int) $combo['quantity'], 2147483647),
                            'category_ids' => [],
                            'parent_id' => $productId,
                            'image_url' => $imageUrl,
                            'date_created' => $row['date_add'] ? gmdate('c', strtotime($row['date_add'])) : null,
                            'date_modified' => $row['date_upd'] ? gmdate('c', strtotime($row['date_upd'])) : null,
                        ];
                    }
                }
            }
        }

        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        return [
            'success' => true,
            'meta' => $this->buildMeta($total, $page, $totalPages, $perPage),
            'products' => $products,
        ];
    }

    private function getProductCombinations($productIds)
    {
        if (empty($productIds)) {
            return [];
        }

        $ids = implode(',', array_map('intval', $productIds));

        $query = "
            SELECT
                pa.id_product,
                pa.id_product_attribute,
                pa.reference,
                pa.price + p.price as price,
                sa.quantity,
                GROUP_CONCAT(DISTINCT al.name ORDER BY al.name SEPARATOR ', ') as attributes
            FROM {$this->prefix}product_attribute pa
            INNER JOIN {$this->prefix}product p ON (pa.id_product = p.id_product)
            LEFT JOIN {$this->prefix}stock_available sa ON (pa.id_product_attribute = sa.id_product_attribute AND sa.id_shop = {$this->idShop})
            LEFT JOIN {$this->prefix}product_attribute_combination pac ON (pa.id_product_attribute = pac.id_product_attribute)
            LEFT JOIN {$this->prefix}attribute_lang al ON (pac.id_attribute = al.id_attribute AND al.id_lang = {$this->idLang})
            WHERE pa.id_product IN ({$ids})
            GROUP BY pa.id_product_attribute
            ORDER BY pa.id_product, pa.id_product_attribute
        ";

        $rows = $this->db->executeS($query);
        $grouped = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $productId = (int) $row['id_product'];
                $grouped[$productId][] = $row;
            }
        }

        return $grouped;
    }

    public function exportCustomersFast($page = 1, $perPage = 100)
    {
        $offset = ($page - 1) * $perPage;

        $total = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM {$this->prefix}customer WHERE deleted = 0 AND active = 1"
        );
        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;

        $query = "
            SELECT
                c.id_customer,
                c.email,
                c.firstname,
                c.lastname,
                c.date_add,
                a.phone,
                a.phone_mobile,
                a.city,
                a.company,
                co.iso_code as country_code
            FROM {$this->prefix}customer c
            LEFT JOIN {$this->prefix}address a ON (c.id_customer = a.id_customer AND a.deleted = 0)
            LEFT JOIN {$this->prefix}country co ON (a.id_country = co.id_country)
            WHERE c.deleted = 0 AND c.active = 1
            GROUP BY c.id_customer
            ORDER BY c.id_customer ASC
            LIMIT {$perPage} OFFSET {$offset}
        ";

        $rows = $this->db->executeS($query);
        $customers = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $customers[] = [
                    'id' => (int) $row['id_customer'],
                    'email' => (string) $row['email'],
                    'first_name' => (string) $row['firstname'],
                    'last_name' => (string) $row['lastname'],
                    'company' => (string) ($row['company'] ?: ''),
                    'phone' => $row['phone_mobile'] ?: $row['phone'] ?: '',
                    'city' => (string) ($row['city'] ?: ''),
                    'country' => (string) ($row['country_code'] ?: ''),
                    'date_created' => $row['date_add'] ? gmdate('c', strtotime($row['date_add'])) : null,
                ];
            }
        }

        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        return [
            'success' => true,
            'meta' => $this->buildMeta($total, $page, $totalPages, $perPage),
            'customers' => $customers,
        ];
    }

    public function exportCategoriesFast($page = 1, $perPage = 100)
    {
        $offset = ($page - 1) * $perPage;

        $total = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM {$this->prefix}category WHERE active = 1"
        );
        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;

        $query = "
            SELECT
                c.id_category,
                c.id_parent,
                c.active,
                c.date_add,
                c.date_upd,
                cl.name,
                cl.description,
                cl.link_rewrite
            FROM {$this->prefix}category c
            LEFT JOIN {$this->prefix}category_lang cl ON (c.id_category = cl.id_category AND cl.id_lang = {$this->idLang} AND cl.id_shop = {$this->idShop})
            WHERE c.active = 1
            ORDER BY c.id_category ASC
            LIMIT {$perPage} OFFSET {$offset}
        ";

        $rows = $this->db->executeS($query);
        $categories = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $categories[] = [
                    'id' => (int) $row['id_category'],
                    'name' => (string) $row['name'],
                    'slug' => (string) $row['link_rewrite'],
                    'parent_id' => (int) $row['id_parent'] > 0 ? (int) $row['id_parent'] : null,
                    'description' => (string) ($row['description'] ?: ''),
                    'date_created' => $row['date_add'] ? gmdate('c', strtotime($row['date_add'])) : null,
                    'date_modified' => $row['date_upd'] ? gmdate('c', strtotime($row['date_upd'])) : null,
                ];
            }
        }

        return [
            'success' => true,
            'meta' => $this->buildMeta($total, $page, $totalPages, $perPage),
            'categories' => $categories,
        ];
    }

    public function exportCouponsFast($page = 1, $perPage = 100)
    {
        $offset = ($page - 1) * $perPage;

        $total = (int) $this->db->getValue("SELECT COUNT(*) FROM {$this->prefix}cart_rule");
        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;

        $query = "
            SELECT
                cr.id_cart_rule,
                cr.code,
                cr.description,
                cr.reduction_percent,
                cr.reduction_amount,
                cr.free_shipping,
                cr.active,
                cr.date_from,
                cr.date_to,
                cr.date_add,
                crl.name
            FROM {$this->prefix}cart_rule cr
            LEFT JOIN {$this->prefix}cart_rule_lang crl ON (cr.id_cart_rule = crl.id_cart_rule AND crl.id_lang = {$this->idLang})
            ORDER BY cr.id_cart_rule ASC
            LIMIT {$perPage} OFFSET {$offset}
        ";

        $rows = $this->db->executeS($query);
        $coupons = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $discountType = 'fixed_cart';
                $amount = (float) $row['reduction_amount'];
                if ((float) $row['reduction_percent'] > 0) {
                    $discountType = 'percent';
                    $amount = (float) $row['reduction_percent'];
                } elseif ((int) $row['free_shipping'] === 1) {
                    $discountType = 'free_shipping';
                    $amount = 0;
                }

                $coupons[] = [
                    'id' => (int) $row['id_cart_rule'],
                    'code' => (string) $row['code'],
                    'name' => (string) ($row['name'] ?: $row['code']),
                    'description' => (string) ($row['description'] ?: ''),
                    'discount_type' => $discountType,
                    'amount' => $amount,
                    'status' => (int) $row['active'] === 1 ? 'active' : 'inactive',
                    'date_expires' => $row['date_to'] ? gmdate('c', strtotime($row['date_to'])) : null,
                    'date_created' => $row['date_add'] ? gmdate('c', strtotime($row['date_add'])) : null,
                ];
            }
        }

        return [
            'success' => true,
            'meta' => $this->buildMeta($total, $page, $totalPages, $perPage),
            'coupons' => $coupons,
        ];
    }

    public function getUpdatedIds($type, $days = 30, $hours = 0, $limit = 200000, $offset = 0)
    {
        $time = strtotime('-' . $days . ' days');
        if ($hours > 0) {
            $time = $time - (60 * 60 * $hours);
        }
        $from = date('Y-m-d H:i:s', $time);

        switch ($type) {
            case 'orders':
                $query = "
                    SELECT id_order as id, UNIX_TIMESTAMP(date_upd) as last_updated
                    FROM {$this->prefix}orders
                    WHERE date_upd > '{$from}'
                    ORDER BY date_upd DESC
                    LIMIT {$limit} OFFSET {$offset}
                ";
                break;
            case 'products':
                $query = "
                    SELECT id_product as id, UNIX_TIMESTAMP(date_upd) as last_updated
                    FROM {$this->prefix}product
                    WHERE date_upd > '{$from}'
                    ORDER BY date_upd DESC
                    LIMIT {$limit} OFFSET {$offset}
                ";
                break;
            case 'customers':
                $query = "
                    SELECT id_customer as id, UNIX_TIMESTAMP(date_upd) as last_updated
                    FROM {$this->prefix}customer
                    WHERE date_upd > '{$from}' AND deleted = 0
                    ORDER BY date_upd DESC
                    LIMIT {$limit} OFFSET {$offset}
                ";
                break;
            default:
                return [];
        }

        return $this->db->executeS($query) ?: [];
    }

    private function buildImageUrl($linkRewrite, $idImage)
    {
        try {
            if ($this->link) {
                $url = $this->link->getImageLink($linkRewrite, $idImage, 'home_default');
                if ($url && strpos($url, 'http') !== 0) {
                    $url = 'https://' . $url;
                }

                return $url;
            }
        } catch (\Throwable $e) {
            /* intentionally empty */
        }

        return null;
    }

    private function buildMeta($total, $page, $totalPages, $perPage)
    {
        return [
            'total' => $total,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'perPage' => $perPage,
            'storeUrl' => $this->getStoreUrl(),
            'pluginVersion' => self::VERSION,
            'exportedAt' => gmdate('c'),
            'mode' => 'fast',
        ];
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
}
