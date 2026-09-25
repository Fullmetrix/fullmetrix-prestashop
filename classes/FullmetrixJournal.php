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

class FullmetrixJournal
{
    private static $entities = [
        'order_ready' => 1,
        'customer' => 2,
        'product' => 3,
        'combination' => 4,
        'stock' => 5,
        'category' => 6,
        'coupon' => 7,
        'cart_saved' => 8,
        'customer_login' => 9,
        'product_deleted' => 10,
        'combination_deleted' => 11,
        'coupon_deleted' => 12,
        'category_deleted' => 13,
        'overflow' => 255,
    ];

    private static $keys = [
        'registered' => 'FULLMETRIX_REGISTERED',
        'code' => 'FULLMETRIX_CONNECTION_CODE',
        'secret' => 'FULLMETRIX_CONNECTION_SECRET',
        'api_base' => 'FULLMETRIX_API_BASE',
        'webhooks_enabled' => 'FULLMETRIX_WEBHOOKS_ENABLED',
        'flags' => 'FULLMETRIX_FLAGS',
        'flags_v' => 'FULLMETRIX_FLAGS_V',
        'journal_v' => 'FULLMETRIX_JOURNAL_V',
        'journal_ack' => 'FULLMETRIX_JOURNAL_ACK',
        'journal_sleep' => 'FULLMETRIX_JOURNAL_SLEEP',
        'journal_retry_at' => 'FULLMETRIX_JOURNAL_RETRY_AT',
        'breaker' => 'FULLMETRIX_BREAKER',
        'upgrade_err' => 'FULLMETRIX_UPGRADE_ERR',
        'tracker_origin' => 'FULLMETRIX_TRACKER_ORIGIN',
        'related_tables' => 'FULLMETRIX_RELATED_TABLES',
        'relay_last' => 'FULLMETRIX_RELAY_LAST',
        'recover_down' => 'FULLMETRIX_RECOVER_DOWN',
        'plugin_config' => 'FULLMETRIX_PLUGIN_CONFIG',
        'conn_conflict' => 'FULLMETRIX_CONN_CONFLICT',
        'v2_owner' => 'FULLMETRIX_V2_OWNER',
        'updated_at' => 'FULLMETRIX_UPDATED_AT',
        'digest_columns' => 'FULLMETRIX_DIGEST_COLUMNS',
        'command_nonces' => 'FULLMETRIX_COMMAND_NONCES',
        'hooks_repaired' => 'FULLMETRIX_HOOKS_REPAIRED',
    ];

    private static $states = [
        'ready' => '2',
        'no_innodb' => 'no_innodb',
        'denied' => 'denied',
    ];

    public static function table()
    {
        return 'fullmetrix_journal';
    }

    public static function entity($name)
    {
        return isset(self::$entities[$name]) ? self::$entities[$name] : 0;
    }

    public static function key($name)
    {
        return isset(self::$keys[$name]) ? self::$keys[$name] : '';
    }

    public static function state($name)
    {
        return isset(self::$states[$name]) ? self::$states[$name] : '';
    }

    public static function write($shop, $entity, $id, $sub)
    {
        $link = Db::getInstance()->getLink();
        $sql = 'INSERT INTO `' . _DB_PREFIX_ . self::table() . '` (t_us, nonce, id_shop, entity, entity_id, sub_id) SELECT '
            . (int) (microtime(true) * 1e6) . ',' . (int) mt_rand(1, 2147483647) . ',' . (int) $shop . ',' . (int) $entity . ',' . (int) $id . ',' . (int) $sub
            . ' FROM DUAL WHERE LAST_INSERT_ID(LAST_INSERT_ID()) >= 0';

        if ($link instanceof PDO) {
            if ($link->exec($sql) !== false) {
                return 0;
            }
            $info = $link->errorInfo();

            return is_array($info) && isset($info[1]) ? (int) $info[1] : -1;
        }

        if ($link instanceof mysqli) {
            if ($link->query($sql) !== false) {
                return 0;
            }

            return $link->errno ? (int) $link->errno : -1;
        }

        return 0;
    }
}
