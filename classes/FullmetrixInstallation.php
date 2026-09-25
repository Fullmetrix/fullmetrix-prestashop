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

class FullmetrixInstallation
{
    public const CFG_OWNER = 'FULLMETRIX_V2_OWNER';
    public const CFG_CONFLICT = 'FULLMETRIX_CONN_CONFLICT';
    public const OWNER_STALE_S = 604800;
    public const TOUCH_EVERY_S = 3600;

    public static function id()
    {
        return hash('sha256', 'fullmetrix-installation|' . (defined('_COOKIE_KEY_') ? _COOKIE_KEY_ : ''));
    }

    public static function owner()
    {
        $rows = self::ownerRows();
        if (empty($rows)) {
            return null;
        }
        $decoded = json_decode((string) $rows[0]['value'], true);
        if (!is_array($decoded) || !isset($decoded['code']) || !is_string($decoded['code']) || $decoded['code'] === '') {
            return null;
        }

        return [
            'code' => $decoded['code'],
            'seen' => isset($decoded['seen']) && is_numeric($decoded['seen']) ? (int) $decoded['seen'] : 0,
        ];
    }

    public static function admit($code)
    {
        return self::decide($code) === 'ok';
    }

    public static function decide($code)
    {
        $code = (string) $code;
        if ($code === '') {
            return 'conflict';
        }
        $now = time();
        try {
            $owner = self::owner();
        } catch (Exception $e) {
            return 'busy';
        }
        if ($owner !== null && hash_equals($owner['code'], $code) && $now - $owner['seen'] < self::TOUCH_EVERY_S) {
            return 'ok';
        }
        if (!self::lock()) {
            return 'busy';
        }
        try {
            $verdict = self::decideLocked($code, $now);
        } catch (Exception $e) {
            $verdict = 'busy';
        }
        self::unlock();

        return $verdict;
    }

    private static function decideLocked($code, $now)
    {
        $rows = self::ownerRows();
        self::dropDuplicates($rows);
        $owner = self::owner();
        if ($owner !== null && hash_equals($owner['code'], $code)) {
            if ($now - $owner['seen'] >= self::TOUCH_EVERY_S) {
                self::claim($code, $now, true);
            }

            return 'ok';
        }
        if ($owner !== null && $now - $owner['seen'] < self::OWNER_STALE_S && self::isConfigured($owner['code'])) {
            self::noteConflict($code, $now);

            return 'conflict';
        }
        self::claim($code, $now, !empty($rows));

        return 'ok';
    }

    private static function lockName()
    {
        return 'fm_owner_' . _DB_PREFIX_;
    }

    private static function lock()
    {
        try {
            return (string) FullmetrixJournalStore::value('SELECT GET_LOCK(\'' . pSQL(self::lockName()) . '\', 1)') === '1';
        } catch (Exception $e) {
            return false;
        }
    }

    private static function unlock()
    {
        try {
            FullmetrixJournalStore::value('SELECT RELEASE_LOCK(\'' . pSQL(self::lockName()) . '\')');
        } catch (Exception $e) {
            return;
        }
    }

    private static function ownerRows()
    {
        return FullmetrixJournalStore::rows(
            'SELECT `id_configuration`, `value` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'' . self::CFG_OWNER
            . '\' AND `id_shop` IS NULL AND `id_shop_group` IS NULL ORDER BY `id_configuration`'
        );
    }

    private static function dropDuplicates(array $rows)
    {
        if (count($rows) < 2) {
            return;
        }
        $ids = [];
        foreach (array_slice($rows, 1) as $row) {
            $ids[] = (int) $row['id_configuration'];
        }
        FullmetrixJournalStore::exec(
            'DELETE FROM `' . _DB_PREFIX_ . 'configuration` WHERE `id_configuration` IN (' . implode(',', $ids) . ')'
        );
    }

    public static function conflictResponse()
    {
        return [
            'success' => false,
            'error' => 'connection_conflict',
            'message' => 'Another Fullmetrix connection already uses real-time sync on this PrestaShop installation. Only one 2.0 connection per installation is supported.',
            'installation_id' => self::id(),
            'server_time' => time(),
        ];
    }

    private static function claim($code, $now, $exists)
    {
        $value = '\'' . pSQL(json_encode(['code' => $code, 'seen' => $now])) . '\'';
        if ($exists) {
            FullmetrixJournalStore::exec(
                'UPDATE `' . _DB_PREFIX_ . 'configuration` SET `value` = ' . $value . ', `date_upd` = NOW() WHERE `name` = \''
                . self::CFG_OWNER . '\' AND `id_shop` IS NULL AND `id_shop_group` IS NULL'
            );

            return;
        }
        FullmetrixJournalStore::exec(
            'INSERT INTO `' . _DB_PREFIX_ . 'configuration` (`id_shop_group`, `id_shop`, `name`, `value`, `date_add`, `date_upd`) VALUES (NULL, NULL, \''
            . self::CFG_OWNER . '\', ' . $value . ', NOW(), NOW())'
        );
    }

    private static function isConfigured($code)
    {
        try {
            $found = FullmetrixJournalStore::value(
                'SELECT 1 FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'FULLMETRIX_CONNECTION_CODE\' AND `value` = \''
                . pSQL($code) . '\' LIMIT 1'
            );
        } catch (Exception $e) {
            return true;
        }

        return $found !== null;
    }

    private static function noteConflict($code, $now)
    {
        $hash = substr(hash('sha256', $code), 0, 16);
        $raw = FullmetrixJournalStore::config(self::CFG_CONFLICT);
        $previous = $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($previous) && isset($previous['code_hash'], $previous['at']) && $previous['code_hash'] === $hash
            && $now - (int) $previous['at'] < self::TOUCH_EVERY_S) {
            return;
        }
        FullmetrixJournalStore::setConfig(self::CFG_CONFLICT, json_encode(['code_hash' => $hash, 'at' => $now]));
    }
}
