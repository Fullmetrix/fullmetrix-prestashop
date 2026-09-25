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
    private $productImageTypeCache;
    private $relatedTablesCache = [];
    private $sensitiveKeyCache = [];
    private $shopContextCache = [];
    private $streaming = false;

    public const RELATED_TABLES_TTL = 86400;

    public const META_VALUE_MAX_LENGTH = 20000;

    public const META_TOTAL_MAX_LENGTH = 65536;

    public const META_SHORT_VALUE_LENGTH = 512;

    // The reporting engine cannot address a key longer than this, so a longer
    // one would be paid for in every payload and never usable.
    public const META_KEY_MAX_LENGTH = 80;

    private static $sensitiveColumns = [
        'passwd', 'secure_key', 'last_passwd_gen',
        'reset_password_token', 'reset_password_validity',
        // Donnees de carte portees par order_payment: card_brand reste, c'est
        // le moyen de paiement, le reste ne doit jamais sortir de la boutique.
        'card_number', 'card_expiration', 'card_holder',
    ];

    /**
     * Motifs appliques aux colonnes des tables tierces, ou aucune liste de noms
     * ne peut etre exhaustive. Bilingue: un module francais nomme ses colonnes
     * jeton_api ou mot_de_passe, qui ne contiennent aucun motif anglais.
     */
    private static $sensitiveKeyPatterns = [
        'password', 'passwd', 'secret', 'token', 'api_key', 'apikey',
        'private_key', 'salt', 'nonce', 'credential',
        'jeton', 'mot_de_passe', 'motdepasse', 'cle_api', 'cle_secrete',
        'cle_privee', 'empreinte', 'signature',
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
        'customer_newsletter',
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

    private static $combinationMappedColumns = [
        'id_product', 'id_product_attribute', 'reference', 'ean13', 'upc',
        'price', 'weight', 'price_impact', 'weight_impact', 'quantity', 'attributes',
        'attribute_pairs', 'wholesale_price',
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

    public static function moduleVersion()
    {
        if (class_exists('FullmetrixConnector', false) && method_exists('FullmetrixConnector', 'pluginVersion')) {
            return (string) FullmetrixConnector::pluginVersion();
        }

        return '2.0.0';
    }

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

    public function streamEntity($entity, $syncType = 'full', $since = null, $fromId = 0)
    {
        $this->setupStream();

        $this->sendLine([
            'type' => 'meta',
            'entity' => $entity,
            'started_at' => gmdate('c'),
            'mode' => 'fast_stream',
            'version' => self::moduleVersion(),
            'store_url' => $this->getStoreUrl(),
            'php_version' => PHP_VERSION,
            'ps_version' => _PS_VERSION_,
            'memory_limit' => ini_get('memory_limit'),
            'batch_size' => $this->batchSize,
            'supports_from_id' => true,
            'shop' => $this->getShopContext(),
        ]);

        $count = 0;

        try {
            switch ($entity) {
                case 'orders':
                    $count = $this->streamOrdersFast($syncType, $since, $fromId);
                    break;
                case 'refunds':
                    $count = $this->streamRefundsFast($syncType, $since, $fromId);
                    break;
                case 'customers':
                    $count = $this->streamCustomersFast($syncType, $since, $fromId);
                    break;
                case 'products':
                    $count = $this->streamProductsFast($syncType, $since, $fromId);
                    break;
                case 'categories':
                    $count = $this->streamCategoriesFast($syncType, $since, $fromId);
                    break;
                case 'coupons':
                    $count = $this->streamCouponsFast($syncType, $since, $fromId);
                    break;
            }
        } catch (Throwable $e) {
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
            'version' => self::moduleVersion(),
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
            'version' => self::moduleVersion(),
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
    }

    private function setupStream()
    {
        $this->streaming = true;
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
        // Let the web server (Apache/nginx) handle gzip via mod_deflate,
        // doing it in PHP with ob_gzhandler breaks progressive streaming
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
            // Recherche en temps constant: les listes sont parcourues pour
            // chaque colonne de chaque ligne, soit des millions de fois sur un
            // gros catalogue.
            $skip = array_flip($mappedColumns) + array_flip(self::$sensitiveColumns);
            foreach ($row as $key => $value) {
                // Un champ de personnalisation nomme Signature ou Empreinte est
                // de la donnee metier saisie par le client, jamais un secret.
                $estPersonnalisation = strpos($key, 'customization_') === 0;
                if (isset($skip[$key]) || (!$estPersonnalisation && $this->isSensitiveKey($key))) {
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
                // La validation UTF-8 complete ne sert que si un octet haut est
                // present: l'ASCII est toujours valide, et c'est le cas courant.
                if (preg_match('/[\x80-\xFF]/', $text) === 1 && preg_match('//u', $text) !== 1) {
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
    private function isSensitiveKey($key)
    {
        if (isset($this->sensitiveKeyCache[$key])) {
            return $this->sensitiveKeyCache[$key];
        }

        $lower = strtolower((string) $key);
        $sensible = false;
        foreach (self::$sensitiveKeyPatterns as $pattern) {
            if (strpos($lower, $pattern) !== false) {
                $sensible = true;
                break;
            }
        }

        return $this->sensitiveKeyCache[$key] = $sensible;
    }

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

    // A shop can carry hundreds of tables. Only this many are followed per
    // entity, so a badly designed module cannot turn one sync into hundreds of
    // queries per batch.
    public const MAX_RELATED_TABLES = 20;

    /**
     * Tables already exported on their own, plus the ones whose content would
     * be meaningless or unbounded as entity meta.
     */
    private static $relatedTableDenylist = [
        'orders', 'order_detail', 'order_detail_tax', 'order_slip', 'order_slip_detail',
        'order_slip_detail_tax', 'order_carrier', 'order_payment', 'order_cart_rule',
        'customer', 'address', 'product', 'product_lang', 'product_shop',
        'product_attribute', 'product_attribute_combination', 'product_attribute_shop',
        'product_attribute_image', 'product_lang_backup', 'category_product',
        'cart_product', 'customized_data', 'customization', 'customization_field',
        'customization_field_lang', 'stock_mvt', 'stock_available', 'specific_price',
        'specific_price_rule', 'log', 'connections', 'connections_page',
        'connections_source', 'guest', 'page_viewed', 'pagenotfound',
        'search_index', 'search_word', 'statssearch', 'layered_product_attribute',
        'layered_filter', 'layered_price_index', 'image', 'image_shop', 'image_lang',
        // Tables de pure jointure ou de cache: elles occuperaient un des
        // emplacements sans rien apprendre.
        'product_comment_criterion_product', 'product_comment_report',
        'product_comment_usefulness', 'product_group_reduction_cache',
        'customer_session', 'product_country_tax', 'cart_cart_rule',
    ];

    /**
     * Any table carrying the entity foreign key, discovered at runtime. A module
     * that adds its own table is picked up without touching this connector.
     */
    private function detectRelatedTables($fkColumn)
    {
        if (isset($this->relatedTablesCache[$fkColumn])) {
            return $this->relatedTablesCache[$fkColumn];
        }

        if (!$this->streaming) {
            $cached = $this->readRelatedTablesCache($fkColumn);
            if ($cached !== null) {
                return $this->relatedTablesCache[$fkColumn] = $cached;
            }
        }

        $tables = [];
        try {
            $denylist = '\'' . implode('\',\'', array_map('pSQL', self::$relatedTableDenylist)) . '\'';
            // La cle primaire sert a ne ramener qu'une ligne par entite, en SQL:
            // une table portant des milliers de lignes par entite serait sinon
            // chargee entiere en memoire.
            $rows = $this->db->executeS(
                'SELECT c.TABLE_NAME AS table_name,
                        MIN(k.COLUMN_NAME) AS pk_column,
                        GROUP_CONCAT(DISTINCT
                            CASE WHEN c.DATA_TYPE IN (\'blob\', \'mediumblob\', \'longblob\', \'tinyblob\', \'binary\', \'varbinary\')
                                 THEN NULL ELSE c.COLUMN_NAME END
                        ) AS safe_columns,
                        COUNT(*) AS column_count
                 FROM information_schema.COLUMNS c
                 LEFT JOIN information_schema.KEY_COLUMN_USAGE k
                        ON (k.TABLE_SCHEMA = c.TABLE_SCHEMA
                        AND k.TABLE_NAME = c.TABLE_NAME
                        AND k.CONSTRAINT_NAME = \'PRIMARY\')
                 WHERE c.TABLE_SCHEMA = DATABASE()
                   AND c.TABLE_NAME LIKE \'' . pSQL(str_replace(['_', '%'], ['\\_', '\\%'], $this->prefix)) . '%\'
                   AND EXISTS (
                       SELECT 1 FROM information_schema.COLUMNS f
                       WHERE f.TABLE_SCHEMA = c.TABLE_SCHEMA
                         AND f.TABLE_NAME = c.TABLE_NAME
                         AND f.COLUMN_NAME = \'' . pSQL($fkColumn) . '\'
                   )
                   AND SUBSTRING(c.TABLE_NAME, ' . (strlen($this->prefix) + 1) . ') NOT IN (' . $denylist . ')
                 GROUP BY c.TABLE_NAME
                 HAVING column_count > 1
                 ORDER BY c.TABLE_NAME
                 LIMIT ' . (int) self::MAX_RELATED_TABLES
            );
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $tables[(string) $row['table_name']] = [
                        'pk' => (string) ($row['pk_column'] ?? ''),
                        'columns' => array_values(array_filter(explode(',', (string) ($row['safe_columns'] ?? '')))),
                    ];
                }
            }
            $this->writeRelatedTablesCache($fkColumn, $tables);
        } catch (Throwable $e) {
            // An information_schema restricted by hosting must not break the sync.
            $tables = [];
        }

        $this->relatedTablesCache[$fkColumn] = $tables;

        return $tables;
    }

    private function readRelatedTablesCache($fkColumn)
    {
        try {
            $raw = Configuration::getGlobalValue('FULLMETRIX_RELATED_TABLES');
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            if (!is_array($decoded) || !isset($decoded[$fkColumn]['t'], $decoded[$fkColumn]['tables'])
                || !is_array($decoded[$fkColumn]['tables'])
                || time() - (int) $decoded[$fkColumn]['t'] > self::RELATED_TABLES_TTL) {
                return null;
            }

            return $decoded[$fkColumn]['tables'];
        } catch (Throwable $e) {
            return null;
        }
    }

    private function writeRelatedTablesCache($fkColumn, array $tables)
    {
        try {
            $raw = Configuration::getGlobalValue('FULLMETRIX_RELATED_TABLES');
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            if (!is_array($decoded)) {
                $decoded = [];
            }
            $decoded[$fkColumn] = ['t' => time(), 'tables' => $tables];
            $encoded = json_encode($decoded);
            if (is_string($encoded)) {
                Configuration::updateGlobalValue('FULLMETRIX_RELATED_TABLES', $encoded, true);
            }
        } catch (Throwable $e) {
            return;
        }
    }

    /**
     * One row per table and per entity, the most recent one, keyed by the table
     * short name. Emitting every row would make the payload grow with the
     * shop's history and the key names unstable.
     *
     * @return array entity id => list of meta groups
     */
    private function batchLoadRelatedTableMeta($fkColumn, $idsList)
    {
        if ($idsList === '') {
            return [];
        }

        $groups = [];
        foreach ($this->detectRelatedTables($fkColumn) as $table => $meta) {
            $pkColumn = $meta['pk'];
            if (empty($meta['columns'])) {
                continue;
            }
            // Colonnes listees explicitement: une colonne binaire, un PDF de
            // facture stocke par un module par exemple, serait lue en memoire
            // pour chaque ligne avant d'etre ecartee.
            $select = '`' . implode('`, `', array_map('bqSQL', $meta['columns'])) . '`';
            $shortName = substr($table, strlen($this->prefix));
            $where = bqSQL($fkColumn) . ' IN (' . $idsList . ')';
            if ($pkColumn !== '' && $pkColumn !== $fkColumn) {
                // Une seule ligne par entite, la derniere, decidee par le moteur.
                $where = '(' . bqSQL($fkColumn) . ', ' . bqSQL($pkColumn) . ') IN ('
                    . 'SELECT ' . bqSQL($fkColumn) . ', MAX(' . bqSQL($pkColumn) . ')'
                    . ' FROM `' . bqSQL($table) . '`'
                    . ' WHERE ' . bqSQL($fkColumn) . ' IN (' . $idsList . ')'
                    . ' GROUP BY ' . bqSQL($fkColumn) . ')';
            }
            $rows = $this->safeQuery(
                'SELECT ' . $select . ' FROM `' . bqSQL($table) . '` WHERE ' . $where,
                'related_' . $shortName
            );
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                $id = (int) $row[$fkColumn];
                $groups[$id][$shortName] = [$row, [$fkColumn], $shortName . '_'];
            }
        }

        foreach ($groups as $id => $byTable) {
            $groups[$id] = array_values($byTable);
        }

        return $groups;
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

    private function streamOrdersFast($syncType, $since, $fromId = 0)
    {
        return $this->streamRows('orders', 'orders', 'id_order', 'o', 'date_upd', 'orderSelect', 'orderLines', $syncType, $since, $fromId);
    }

    private function streamRows($entity, $table, $idColumn, $alias, $dateColumn, $select, $format, $syncType, $since, $fromId)
    {
        $count = 0;
        $lastId = (int) $fromId;
        $sinceWhere = '';
        if ($syncType === 'incremental' && $since) {
            $sinceWhere = ' AND ' . $alias . '.' . $dateColumn . ' > \'' . pSQL($since) . '\'';
        }

        while (true) {
            $rows = $this->safeQuery(
                $this->$select($alias . '.' . $idColumn . ' > ' . (int) $lastId . $sinceWhere)
                . ' ORDER BY ' . $alias . '.' . $idColumn . ' ASC LIMIT ' . $this->batchSize,
                $entity
            );
            if ($rows === false) {
                $lastId = $this->findNextId($table, $idColumn, $lastId, str_replace($alias . '.' . $dateColumn, $dateColumn, $sinceWhere));
                continue;
            }
            if (empty($rows)) {
                break;
            }
            foreach ($this->$format($rows) as $line) {
                $this->sendLine($line);
                ++$count;
            }
            $lastId = (int) end($rows)[$idColumn];
            unset($rows);
            $this->adaptBatchSize();
            $this->maybeGc();
        }

        $this->sendLine([
            'type' => 'entity_complete',
            'entity' => $entity,
            'count' => $count,
        ]);

        return $count;
    }

    private function orderSelect($where)
    {
        return 'SELECT o.*,
                   o.module AS payment_method,
                   o.payment AS payment_method_title,
                   osl.name AS status_name,
                   c.email AS customer_email, c.id_default_group, c.newsletter AS customer_newsletter,
                   cur.iso_code AS currency_code
            FROM ' . $this->prefix . 'orders o
            LEFT JOIN ' . $this->prefix . 'order_state_lang osl
                ON (o.current_state = osl.id_order_state AND osl.id_lang = ' . $this->idLang . ')
            LEFT JOIN ' . $this->prefix . 'customer c ON (o.id_customer = c.id_customer)
            LEFT JOIN ' . $this->prefix . 'currency cur ON (o.id_currency = cur.id_currency)
            WHERE ' . $where;
    }

    private function orderLines(array $rows)
    {
        $orderIds = [];
        $addressIds = [];
        $references = [];
        $customerIds = [];
        foreach ($rows as $row) {
            $orderIds[] = (int) $row['id_order'];
            $addressIds[] = (int) $row['id_address_invoice'];
            $addressIds[] = (int) $row['id_address_delivery'];
            $references[] = pSQL($row['reference']);
            if (!empty($row['id_customer'])) {
                $customerIds[] = (int) $row['id_customer'];
            }
        }
        $orderIdsList = implode(',', $orderIds);
        $addressMap = $this->batchLoadAddresses(array_unique(array_filter($addressIds)));
        $lineItemsMap = $this->batchLoadOrderLineItems($orderIdsList);
        $carriersMap = $this->batchLoadOrderCarriers($orderIdsList);
        $couponMap = $this->batchLoadOrderCartRules($orderIdsList);
        $paymentMap = $this->batchLoadOrderPayments($references);
        $customerGroupsMap = $this->batchLoadCustomerGroups(implode(',', array_unique(array_filter($customerIds))));
        $relatedMap = $this->batchLoadRelatedTableMeta('id_order', $orderIdsList);
        $stateSeqMap = $this->getStateSeqs($orderIds);

        $lines = [];
        foreach ($rows as $row) {
            $oid = (int) $row['id_order'];
            $totalTaxIncl = (float) $row['total_paid_tax_incl'];
            $totalTaxExcl = (float) $row['total_paid_tax_excl'];
            $tax = max(0, $totalTaxIncl - $totalTaxExcl);
            $billingAddr = $addressMap[(int) $row['id_address_invoice']] ?? null;
            $shippingAddr = $addressMap[(int) $row['id_address_delivery']] ?? null;
            $billing = $this->formatAddress($billingAddr);
            $shipping = $this->formatAddress($shippingAddr);
            $billing['email'] = (string) ($row['customer_email'] ?? '');

            $lines[] = [
                'type' => 'order',
                '_cursor' => $oid,
                'state_seq' => $stateSeqMap[$oid] ?? 0,
                'current_state' => (int) $row['current_state'],
                'data' => [
                    'id' => $oid,
                    'shop' => $this->getShopContext((int) ($row['id_shop'] ?? $this->idShop), (int) ($row['id_shop_group'] ?? 0)),
                    'customer_groups' => $this->formatCustomerGroups((int) $row['id_customer'], (int) ($row['id_default_group'] ?? 0), $customerGroupsMap),
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
                    'current_state' => (int) $row['current_state'],
                    'state_seq' => $stateSeqMap[$oid] ?? 0,
                    'customer_newsletter' => (int) ($row['customer_newsletter'] ?? 0),
                    'meta_data' => $this->extraColumnsMetaGroups(array_merge([
                        [$row, self::$orderMappedColumns, ''],
                        [$billingAddr, self::$addressMappedColumns, 'billing_'],
                        [$shippingAddr, self::$addressMappedColumns, 'shipping_'],
                    ], $relatedMap[$oid] ?? [])),
                ],
            ];
        }

        return $lines;
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
                        $customizationMap[(int) ($row['id_customization'] ?? 0)] ?? []
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

    private function streamRefundsFast($syncType = 'full', $since = null, $fromId = 0)
    {
        return $this->streamRows('refunds', 'order_slip', 'id_order_slip', 'os', 'date_add', 'refundSelect', 'refundLines', $syncType, $since, $fromId);
    }

    private function refundSelect($where)
    {
        return 'SELECT os.*,
                   o.id_shop, o.id_shop_group
            FROM ' . $this->prefix . 'order_slip os
            LEFT JOIN ' . $this->prefix . 'orders o ON (os.id_order = o.id_order)
            WHERE ' . $where;
    }

    private function refundLines(array $rows)
    {
        $slipIds = [];
        foreach ($rows as $row) {
            $slipIds[] = (int) $row['id_order_slip'];
        }
        $detailMap = $this->batchLoadSlipDetails(implode(',', $slipIds));
        $relatedMap = $this->batchLoadRelatedTableMeta('id_order_slip', implode(',', $slipIds));

        $lines = [];
        foreach ($rows as $row) {
            $sid = (int) $row['id_order_slip'];
            $totalAmount = (float) $row['total_products_tax_incl'] + (float) $row['total_shipping_tax_incl'];
            $lines[] = [
                'type' => 'refund',
                '_cursor' => $sid,
                'data' => [
                    'id' => $sid,
                    'shop' => $this->getShopContext((int) ($row['id_shop'] ?? $this->idShop), (int) ($row['id_shop_group'] ?? 0)),
                    'parent_id' => (int) $row['id_order'],
                    'amount' => (string) round(abs($totalAmount), 2),
                    'reason' => '',
                    'date_created' => $this->toIso($row['date_add']),
                    'refunded_by' => null,
                    'line_items' => $detailMap[$sid] ?? [],
                    'meta_data' => $this->extraColumnsMetaGroups(array_merge(
                        [[$row, self::$refundMappedColumns, '']],
                        $relatedMap[$sid] ?? []
                    )),
                ],
            ];
        }

        return $lines;
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

    private function streamCustomersFast($syncType = 'full', $since = null, $fromId = 0)
    {
        return $this->streamRows('customers', 'customer', 'id_customer', 'c', 'date_upd', 'customerSelect', 'customerLines', $syncType, $since, $fromId);
    }

    private function customerSelect($where)
    {
        return 'SELECT c.*,
                   gl.name AS gender_name
            FROM ' . $this->prefix . 'customer c
            LEFT JOIN ' . $this->prefix . 'gender_lang gl
                ON (c.id_gender = gl.id_gender AND gl.id_lang = ' . $this->idLang . ')
            WHERE c.deleted = 0 AND ' . $where;
    }

    private function customerLines(array $rows)
    {
        $customerIds = [];
        foreach ($rows as $row) {
            $customerIds[] = (int) $row['id_customer'];
        }
        $customerIdsList = implode(',', $customerIds);
        $addrMap = $this->batchLoadCustomerAddresses($customerIdsList);
        $statsMap = $this->batchLoadCustomerStats($customerIdsList);
        $groupsMap = $this->batchLoadCustomerGroups($customerIdsList);
        $relatedMap = $this->batchLoadRelatedTableMeta('id_customer', $customerIdsList);

        $lines = [];
        foreach ($rows as $row) {
            $cid = (int) $row['id_customer'];
            $addresses = $addrMap[$cid] ?? [];
            $stats = $statsMap[$cid] ?? ['order_count' => 0, 'total_spent' => 0];
            $primaryAddr = !empty($addresses) ? $addresses[0] : null;
            $billing = $this->formatAddress($primaryAddr);
            $billing['email'] = (string) $row['email'];
            $shippingAddr = count($addresses) > 1 ? $addresses[1] : $primaryAddr;
            $shipping = $this->formatAddress($shippingAddr);

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
            $metaData = $this->mergeMeta($metaData, $this->extraColumnsMetaGroups(array_merge([
                [$row, self::$customerMappedColumns, ''],
                [$primaryAddr, self::$addressMappedColumns, 'billing_'],
                [$shippingAddr, self::$addressMappedColumns, 'shipping_'],
            ], $relatedMap[$cid] ?? [])));

            $lines[] = [
                'type' => 'customer',
                '_cursor' => $cid,
                'data' => [
                    'id' => $cid,
                    'shop' => $this->getShopContext((int) ($row['id_shop'] ?? $this->idShop), (int) ($row['id_shop_group'] ?? 0)),
                    'customer_groups' => $customerGroups,
                    'email' => (string) $row['email'],
                    'first_name' => (string) $row['firstname'],
                    'last_name' => (string) $row['lastname'],
                    'company' => (string) ($row['company'] ?? ''),
                    'phone' => $billing['phone'] ?: ($shipping['phone'] ?? ''),
                    'city' => $billing['city'] ?: ($shipping['city'] ?? ''),
                    'country' => $billing['country'] ?: ($shipping['country'] ?? ''),
                    'date_created' => $this->toIso($row['date_add']),
                    'billing' => $billing,
                    'shipping' => $shipping,
                    'meta_data' => $metaData,
                ],
            ];
        }

        return $lines;
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

    private function streamProductsFast($syncType = 'full', $since = null, $fromId = 0)
    {
        return $this->streamRows('products', 'product', 'id_product', 'p', 'date_upd', 'productSelect', 'productLines', $syncType, $since, $fromId);
    }

    private function productSelect($where)
    {
        return 'SELECT p.*,
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
            WHERE ' . $where;
    }

    private function productLines(array $rows, $attributeIds = null)
    {
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

        $categoriesMap = $this->batchLoadProductCategories($productIdsList);
        $tagsMap = $this->batchLoadProductTags($productIdsList);
        $imagesMap = $this->batchLoadProductImages($productIdsList, $rewriteMap);
        $salePriceMap = $this->batchLoadSpecificPrices($productIdsList, $basePrices);
        $combosMap = $attributeIds === null ? $this->batchLoadCombinations($productIdsList) : $this->batchLoadCombinations($productIdsList, $attributeIds);
        $variableMap = $attributeIds === null ? null : $this->batchLoadCombinationCounts($productIdsList);
        $suppliersMap = $this->batchLoadProductSuppliers($productIdsList);
        $featuresMap = $this->batchLoadProductFeatures($productIdsList);
        $relatedMap = $this->batchLoadRelatedTableMeta('id_product', $productIdsList);
        $shop = $this->getShopContext();

        $lines = [];
        foreach ($rows as $row) {
            $pid = (int) $row['id_product'];
            $hasCombinations = $variableMap === null ? !empty($combosMap[$pid]) : !empty($variableMap[$pid]);
            $stockQty = min((int) ($row['stock_quantity'] ?? 0), 2147483647);
            $images = $imagesMap[$pid] ?? [];
            $imageUrl = !empty($images) ? $images[0] : null;
            $imageList = array_map(function ($src) { return ['src' => $src]; }, $images);
            $salePrice = $salePriceMap[$pid] ?? null;
            $displayPrices = $this->getDisplayPriceValues($pid);

            $lines[] = [
                'type' => 'product',
                '_cursor' => $pid - 1,
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
                    'meta_data' => $this->extraColumnsMetaGroups(array_merge(
                        [[$row, self::$productMappedColumns, '']],
                        $relatedMap[$pid] ?? []
                    )),
                ],
            ];

            foreach ($combosMap[$pid] ?? [] as $combo) {
                $lines[] = $this->variationLine($row, $combo, $shop, $imageUrl);
            }
        }

        return $lines;
    }

    private function variationLine(array $row, array $combo, array $shop, $imageUrl)
    {
        $pid = (int) $row['id_product'];
        $aid = (int) $combo['id_product_attribute'];
        $comboStock = min((int) ($combo['quantity'] ?? 0), 2147483647);
        $comboRef = (string) ($combo['reference'] ?? '');
        if ($comboRef === '') {
            $comboRef = (string) ($row['reference'] ?? '');
        }
        $comboPrice = (float) $row['price'] + (float) ($combo['price_impact'] ?? 0);
        $comboCost = (float) ($combo['wholesale_price'] ?? 0);
        if ($comboCost <= 0) {
            $comboCost = (float) ($row['wholesale_price'] ?? 0);
        }
        $displayPrices = $this->getDisplayPriceValues($pid, $aid);
        $comboName = (string) ($row['name'] ?? '');
        if (!empty($combo['attributes'])) {
            $comboName .= ' - ' . $combo['attributes'];
        }

        return [
            'type' => 'product',
            '_cursor' => $pid - 1,
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
                'condition' => (string) ($row['condition'] ?? 'new'),
                'wholesale_price' => (string) round($comboCost, 2),
                'category_ids' => [],
                'parent_id' => $pid,
                'attributes' => $combo['attribute_pairs'],
                'meta_data' => $this->extraColumnsMeta($combo, self::$combinationMappedColumns),
                'image_url' => $imageUrl,
                'images' => [],
                'date_created' => $this->toIso($row['date_add']),
                'date_modified' => $this->toIso($row['date_upd']),
            ],
        ];
    }

    private function batchLoadCombinationCounts($productIdsList)
    {
        $rows = $this->safeQuery(
            'SELECT id_product, COUNT(*) AS cnt FROM ' . $this->prefix . 'product_attribute WHERE id_product IN (' . $productIdsList . ') GROUP BY id_product',
            'product_combination_counts'
        );
        $map = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $map[(int) $row['id_product']] = (int) $row['cnt'];
            }
        }

        return $map;
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
                $imageUrl = $link->getImageLink($linkRewrite, $idImage, $this->getProductImageType());
                if ($imageUrl && strpos($imageUrl, 'http') !== 0) {
                    $imageUrl = (Configuration::get('PS_SSL_ENABLED') || Tools::usingSecureMode() ? 'https://' : 'http://') . $imageUrl;
                }
            }

            if ($imageUrl) {
                $map[$pid][] = $imageUrl;
            }
        }

        return $map;
    }

    /**
     * Les comparateurs marchands refusent une image de moins de 500 px de
     * cote. `home_default` en fait 250 sur une boutique standard : on prend le
     * plus grand format declare pour les produits, que la boutique l'ait
     * renomme ou non.
     */
    private function getProductImageType()
    {
        if ($this->productImageTypeCache !== null) {
            return $this->productImageTypeCache;
        }

        $this->productImageTypeCache = 'home_default';

        $rows = $this->safeQuery(
            'SELECT name, width, height
            FROM ' . $this->prefix . 'image_type
            WHERE products = 1
            ORDER BY width DESC, height DESC
            LIMIT 1',
            'product_image_type'
        );

        if (is_array($rows) && !empty($rows) && !empty($rows[0]['name'])) {
            $this->productImageTypeCache = (string) $rows[0]['name'];
        }

        return $this->productImageTypeCache;
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

    private function batchLoadCombinations($productIdsList, $attributeIds = null)
    {
        $attributeFilter = '';
        if (is_array($attributeIds)) {
            $attributeIds = array_values(array_filter(array_map('intval', $attributeIds)));
            if (empty($attributeIds)) {
                return [];
            }
            $attributeFilter = ' AND pa.id_product_attribute IN (' . implode(',', $attributeIds) . ')';
        }

        $sql = 'SELECT pa.*,
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
            WHERE pa.id_product IN (' . $productIdsList . ')' . $attributeFilter . '
            GROUP BY pa.id_product_attribute';

        $rows = $this->safeQuery($sql, 'product_combinations');
        $map = [];

        if (is_array($rows)) {
            $attributePairs = $this->batchLoadCombinationAttributes($productIdsList, $attributeFilter);
            foreach ($rows as $row) {
                $pid = (int) $row['id_product'];
                $aid = (int) $row['id_product_attribute'];
                $row['attribute_pairs'] = isset($attributePairs[$aid]) ? $attributePairs[$aid] : [];
                $map[$pid][] = $row;
            }
        }

        return $map;
    }

    /**
     * Le nom du groupe porte le sens : sans lui, « 39/40 » ne dit pas s'il
     * s'agit d'une pointure ou d'une longueur, et les flux marchands ne
     * peuvent pas remplir `size` ni `color`.
     */
    private function batchLoadCombinationAttributes($productIdsList, $attributeFilter = '')
    {
        $sql = 'SELECT pac.id_product_attribute, agl.name AS group_name, al.name AS value_name, ag.position
            FROM ' . $this->prefix . 'product_attribute pa
            INNER JOIN ' . $this->prefix . 'product_attribute_combination pac
                ON pac.id_product_attribute = pa.id_product_attribute
            INNER JOIN ' . $this->prefix . 'attribute a
                ON a.id_attribute = pac.id_attribute
            INNER JOIN ' . $this->prefix . 'attribute_group ag
                ON ag.id_attribute_group = a.id_attribute_group
            LEFT JOIN ' . $this->prefix . 'attribute_group_lang agl
                ON (agl.id_attribute_group = ag.id_attribute_group AND agl.id_lang = ' . $this->idLang . ')
            LEFT JOIN ' . $this->prefix . 'attribute_lang al
                ON (al.id_attribute = a.id_attribute AND al.id_lang = ' . $this->idLang . ')
            WHERE pa.id_product IN (' . $productIdsList . ')' . $attributeFilter . '
            ORDER BY pac.id_product_attribute ASC, ag.position ASC';

        $rows = $this->safeQuery($sql, 'combination_attributes');
        $map = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $name = trim((string) ($row['group_name'] ?? ''));
                $value = trim((string) ($row['value_name'] ?? ''));
                if ($name === '' || $value === '') {
                    continue;
                }
                $map[(int) $row['id_product_attribute']][] = [
                    'name' => $name,
                    'option' => $value,
                    'position' => (int) ($row['position'] ?? 0),
                ];
            }
        }

        return $map;
    }

    private function streamCategoriesFast($syncType = 'full', $since = null, $fromId = 0)
    {
        return $this->streamRows('categories', 'category', 'id_category', 'c', 'date_upd', 'categorySelect', 'categoryLines', $syncType, $since, $fromId);
    }

    private function categorySelect($where)
    {
        return 'SELECT c.*,
                   cl.name, cl.description, cl.link_rewrite
            FROM ' . $this->prefix . 'category c
            LEFT JOIN ' . $this->prefix . 'category_lang cl
                ON (c.id_category = cl.id_category AND cl.id_lang = ' . $this->idLang . ' AND cl.id_shop = ' . $this->idShop . ')
            WHERE ' . $where;
    }

    private function categoryLines(array $rows)
    {
        $catIdsList = implode(',', array_map(function ($r) { return (int) $r['id_category']; }, $rows));
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
        $relatedMap = $this->batchLoadRelatedTableMeta('id_category', $catIdsList);
        $shop = $this->getShopContext();

        $lines = [];
        foreach ($rows as $row) {
            $cid = (int) $row['id_category'];
            $lines[] = [
                'type' => 'category',
                '_cursor' => $cid,
                'data' => [
                    'id' => $cid,
                    'shop' => $shop,
                    'name' => (string) ($row['name'] ?? ''),
                    'slug' => (string) ($row['link_rewrite'] ?? ''),
                    'parent_id' => (int) $row['id_parent'] ?: null,
                    'description' => (string) ($row['description'] ?? ''),
                    'count' => $countMap[$cid] ?? 0,
                    'image_url' => null,
                    'meta_data' => $this->extraColumnsMetaGroups(array_merge(
                        [[$row, self::$categoryMappedColumns, '']],
                        $relatedMap[$cid] ?? []
                    )),
                ],
            ];
        }

        return $lines;
    }
    private function streamCouponsFast($syncType = 'full', $since = null, $fromId = 0)
    {
        return $this->streamRows('coupons', 'cart_rule', 'id_cart_rule', 'cr', 'date_upd', 'couponSelect', 'couponLines', $syncType, $since, $fromId);
    }

    private function couponSelect($where)
    {
        return 'SELECT cr.*,
                   crl.name
            FROM ' . $this->prefix . 'cart_rule cr
            LEFT JOIN ' . $this->prefix . 'cart_rule_lang crl
                ON (cr.id_cart_rule = crl.id_cart_rule AND crl.id_lang = ' . $this->idLang . ')
            WHERE ' . $where;
    }

    private function couponLines(array $rows)
    {
        $crIdsList = implode(',', array_map(function ($r) { return (int) $r['id_cart_rule']; }, $rows));
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
        $relatedMap = $this->batchLoadRelatedTableMeta('id_cart_rule', $crIdsList);
        $shop = $this->getShopContext();

        $lines = [];
        foreach ($rows as $row) {
            $crId = (int) $row['id_cart_rule'];
            $discountType = 'fixed_cart';
            $amount = (float) $row['reduction_amount'];
            if ((float) $row['reduction_percent'] > 0) {
                $discountType = 'percent';
                $amount = (float) $row['reduction_percent'];
            }
            $lines[] = [
                'type' => 'coupon',
                '_cursor' => $crId,
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
                    'meta_data' => $this->extraColumnsMetaGroups(array_merge(
                        [[$row, self::$couponMappedColumns, '']],
                        $relatedMap[$crId] ?? []
                    )),
                ],
            ];
        }

        return $lines;
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
                if (!$this->streaming) {
                    return $this->failOutsideStream($context, $e->getMessage(), $this->sqlErrorNumber());
                }
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
                if (!$this->streaming) {
                    return $this->failOutsideStream($context, (string) $error, $this->sqlErrorNumber());
                }
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

    private function sqlErrorNumber()
    {
        try {
            return method_exists($this->db, 'getNumberError') ? (int) $this->db->getNumberError() : 0;
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function failOutsideStream($context, $message, $code)
    {
        if (strpos((string) $context, 'related_') === 0 || $context === 'product_image_type') {
            return false;
        }

        throw new RuntimeException('SQL error' . ($context ? ' [' . $context . ']' : '') . ': ' . $message, (int) $code);
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
            $variants = $this->formatProductVariants((int) $matches[1], [(int) $matches[2]]);

            return $variants[(int) $matches[2]] ?? null;
        }

        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }

        switch ($entityType) {
            case 'order':
                return $this->formatSingle('orderSelect', 'orderLines', 'o.id_order = ' . $id, 'single_order');
            case 'customer':
                return $this->formatSingle('customerSelect', 'customerLines', 'c.id_customer = ' . $id, 'single_customer');
            case 'product':
                return $this->formatProductFamily($id, [])['product'];
            case 'category':
                return $this->formatSingle('categorySelect', 'categoryLines', 'c.id_category = ' . $id, 'single_category');
            case 'coupon':
                return $this->formatSingle('couponSelect', 'couponLines', 'cr.id_cart_rule = ' . $id, 'single_coupon');
            case 'refund':
                return $this->formatSingle('refundSelect', 'refundLines', 'os.id_order_slip = ' . $id, 'single_refund');
            default:
                return null;
        }
    }

    public function formatProductVariants($productId, array $attributeIds)
    {
        $family = $this->formatProductFamily($productId, $attributeIds);

        return $family['variants'];
    }

    public function formatProductFamily($productId, array $attributeIds)
    {
        $family = ['product' => null, 'variants' => []];
        $rows = $this->safeQuery($this->productSelect('p.id_product = ' . (int) $productId), 'single_product');
        if (!is_array($rows) || empty($rows)) {
            return $family;
        }
        foreach ($this->productLines($rows, $attributeIds) as $line) {
            if ($line['data']['parent_id'] === null) {
                $family['product'] = $line['data'];
                continue;
            }
            $attributeId = (int) substr((string) $line['data']['id'], strpos((string) $line['data']['id'], '_') + 1);
            $family['variants'][$attributeId] = $line['data'];
        }

        return $family;
    }

    private function formatSingle($select, $lines, $where, $context)
    {
        $rows = $this->safeQuery($this->$select($where), $context);
        if (!is_array($rows) || empty($rows)) {
            return null;
        }
        $formatted = $this->$lines($rows);

        return empty($formatted) ? null : $formatted[0]['data'];
    }
    public function listProductAttributeIds($productId, $offset, $limit)
    {
        $rows = $this->safeQuery(
            'SELECT id_product_attribute FROM ' . $this->prefix . 'product_attribute WHERE id_product = ' . (int) $productId
            . ' ORDER BY id_product_attribute LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
            'product_family'
        );
        $ids = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $ids[] = (int) $row['id_product_attribute'];
            }
        }

        return $ids;
    }

    public function getStateSeqs(array $orderIds)
    {
        $orderIds = array_values(array_filter(array_map('intval', $orderIds)));
        if (empty($orderIds)) {
            return [];
        }
        $rows = $this->safeQuery(
            'SELECT id_order, MAX(id_order_history) AS state_seq FROM ' . $this->prefix . 'order_history
            WHERE id_order IN (' . implode(',', $orderIds) . ') GROUP BY id_order',
            'order_state_seq'
        );
        $map = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $map[(int) $row['id_order']] = (int) $row['state_seq'];
            }
        }

        return $map;
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
}
