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

require_once dirname(__FILE__) . '/FullmetrixJournal.php';

class FullmetrixJournalStore
{
    public const ACK_BATCH = 200;
    public const ACK_ROWS_PER_KEY = 10;
    public const MAX_ACK_KEYS = 5000;
    public const MAX_READ_ROWS = 5000;
    public const COUNT_CAP = 250001;
    public const SLEEP_AFTER_S = 86400;
    public const SLEEP_NO_HEARTBEAT_S = 21600;
    public const LOCAL_ROWS_CAP = 200000;
    public const HEARTBEAT_EVERY_S = 3600;
    public const RETRY_EVERY_S = 86400;
    public const LOCK_WAIT_S = 2;
    public const ERR_BINLOG_STATEMENT = 1665;
    public const ERR_LOCK_WAIT = 1205;
    public const ERR_DEADLOCK = 1213;
    public const ERR_NO_TABLE = 1146;

    private static $isolation;
    private static $binlog;
    private static $fallback = false;

    public static function table()
    {
        return _DB_PREFIX_ . FullmetrixJournal::table();
    }

    public static function config($key)
    {
        return (string) Configuration::getGlobalValue($key);
    }

    public static function setConfig($key, $value)
    {
        try {
            return (bool) Configuration::updateGlobalValue($key, (string) $value, true);
        } catch (Exception $e) {
            return false;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function errorCode($e)
    {
        $code = $e->getCode();
        if (is_int($code)) {
            return $code;
        }
        if (is_numeric($code)) {
            return (int) $code;
        }

        return 0;
    }

    public static function exec($sql)
    {
        return self::run($sql, false, true);
    }

    public static function rows($sql)
    {
        return self::run($sql, true, true);
    }

    public static function value($sql)
    {
        $rows = self::rows($sql);
        if (empty($rows)) {
            return null;
        }
        $row = reset($rows);

        return is_array($row) ? reset($row) : null;
    }

    private static function run($sql, $fetch, $allowFallback)
    {
        try {
            return self::runOnce($sql, $fetch);
        } catch (Exception $e) {
            if ($allowFallback && self::errorCode($e) === self::ERR_BINLOG_STATEMENT && self::$isolation !== 'rr') {
                self::fallbackToRepeatableRead();

                return self::runOnce($sql, $fetch);
            }
            throw $e;
        }
    }

    private static function fallbackToRepeatableRead()
    {
        self::$fallback = true;
        self::$isolation = 'rr';
        self::runOnce('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ', false);
    }

    private static function runOnce($sql, $fetch)
    {
        $link = Db::getInstance()->getLink();
        if ($link instanceof PDO) {
            return self::runPdo($link, $sql, $fetch);
        }
        if ($link instanceof mysqli) {
            return self::runMysqli($link, $sql, $fetch);
        }

        return self::runDb($sql, $fetch);
    }

    private static function runPdo($link, $sql, $fetch)
    {
        try {
            if ($fetch) {
                $stmt = $link->query($sql);
                if ($stmt === false) {
                    self::throwPdo($link->errorInfo());
                }
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $stmt->closeCursor();

                return is_array($rows) ? $rows : [];
            }
            $affected = $link->exec($sql);
            if ($affected === false) {
                self::throwPdo($link->errorInfo());
            }

            return (int) $affected;
        } catch (PDOException $e) {
            $info = $e->errorInfo;
            $errno = is_array($info) && isset($info[1]) ? (int) $info[1] : 0;
            throw new Exception($e->getMessage(), $errno);
        }
    }

    private static function throwPdo($info)
    {
        $errno = is_array($info) && isset($info[1]) ? (int) $info[1] : 0;
        $message = is_array($info) && isset($info[2]) ? (string) $info[2] : 'SQL error';
        throw new Exception($message, $errno);
    }

    private static function runMysqli($link, $sql, $fetch)
    {
        try {
            $result = $link->query($sql);
        } catch (Exception $e) {
            throw new Exception($e->getMessage(), self::errorCode($e));
        }
        if ($result === false) {
            throw new Exception((string) $link->error, (int) $link->errno);
        }
        if (!$fetch) {
            return (int) $link->affected_rows;
        }
        $rows = [];
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
        }

        return $rows;
    }

    private static function runDb($sql, $fetch)
    {
        $db = Db::getInstance();
        if ($fetch) {
            $rows = $db->executeS($sql, true, false);
            if ($rows === false) {
                throw new Exception((string) $db->getMsgError(), (int) $db->getNumberError());
            }

            return is_array($rows) ? $rows : [];
        }
        if (!$db->execute($sql, false)) {
            throw new Exception((string) $db->getMsgError(), (int) $db->getNumberError());
        }

        return (int) $db->Affected_Rows();
    }

    public static function binlog()
    {
        if (self::$binlog !== null) {
            return self::$binlog;
        }
        $logBin = null;
        $format = null;
        foreach (['@@binlog_format', '@@global.binlog_format'] as $variable) {
            try {
                $rows = self::runOnce('SELECT @@log_bin AS log_bin, ' . $variable . ' AS fmt', true);
                if (!empty($rows)) {
                    $logBin = (string) $rows[0]['log_bin'] !== '' && (string) $rows[0]['log_bin'] !== '0'
                        && strtoupper((string) $rows[0]['log_bin']) !== 'OFF';
                    $format = $rows[0]['fmt'] === null ? null : strtoupper((string) $rows[0]['fmt']);
                    break;
                }
            } catch (Exception $e) {
                continue;
            }
        }
        self::$binlog = ['log_bin' => $logBin, 'format' => $format];

        return self::$binlog;
    }

    public static function plannedIsolation()
    {
        $binlog = self::binlog();
        if ($binlog['log_bin'] === false) {
            return 'rc';
        }
        if ($binlog['log_bin'] === true && in_array($binlog['format'], ['ROW', 'MIXED'], true)) {
            return 'rc';
        }

        return 'rr';
    }

    public static function prepareSession()
    {
        if (self::$isolation !== null) {
            return self::$isolation;
        }
        $isolation = self::plannedIsolation();
        if ($isolation === 'rc') {
            try {
                self::runOnce('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED', false);
            } catch (Exception $e) {
                $isolation = 'rr';
            }
        }
        self::$isolation = $isolation;
        try {
            self::runOnce('SET SESSION innodb_lock_wait_timeout = ' . self::LOCK_WAIT_S, false);
        } catch (Exception $e) {
        }

        return self::$isolation;
    }

    public static function isolation()
    {
        return self::$isolation;
    }

    public static function isolationFallback()
    {
        return self::$fallback;
    }

    private static function shortLockWait()
    {
        try {
            self::runOnce('SET SESSION lock_wait_timeout = ' . self::LOCK_WAIT_S, false);
        } catch (Exception $e) {
            return false;
        }

        return true;
    }

    public static function ensureTable()
    {
        try {
            return self::createTable();
        } catch (Exception $e) {
            return ['state' => 'denied', 'error' => substr($e->getMessage(), 0, 255)];
        } catch (Throwable $e) {
            return ['state' => 'denied', 'error' => substr($e->getMessage(), 0, 255)];
        }
    }

    private static function createTable()
    {
        $previous = self::config(FullmetrixJournal::key('journal_v'));
        self::shortLockWait();
        try {
            self::runOnce(
                'CREATE TABLE IF NOT EXISTS `' . self::table() . '` ('
                . '`t_us` BIGINT UNSIGNED NOT NULL, `nonce` INT UNSIGNED NOT NULL, '
                . '`id_shop` INT UNSIGNED NOT NULL, `entity` TINYINT UNSIGNED NOT NULL, '
                . '`entity_id` INT UNSIGNED NOT NULL, `sub_id` INT UNSIGNED NOT NULL DEFAULT 0, '
                . 'PRIMARY KEY (`t_us`, `nonce`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8',
                false
            );
        } catch (Exception $e) {
            self::markUnavailable('denied');

            return ['state' => 'denied', 'error' => substr($e->getMessage(), 0, 255)];
        }

        $engine = self::engineOf(FullmetrixJournal::table());
        if ($engine === null || strtolower($engine) !== 'innodb') {
            if ($previous !== '2') {
                self::dropIfInert();
            }
            self::markUnavailable('no_innodb');

            return ['state' => 'no_innodb', 'error' => $engine === null ? 'engine_unknown' : 'engine_' . $engine];
        }

        $breaker = self::config(FullmetrixJournal::key('breaker'));
        if ($breaker === '') {
            self::setConfig(FullmetrixJournal::key('breaker'), '{}');
        }
        self::setConfig(FullmetrixJournal::key('journal_ack'), (string) time());
        if ($previous !== '2') {
            self::setConfig(FullmetrixJournal::key('journal_v'), '2');
        }

        return ['state' => 'ready', 'error' => null];
    }

    private static function markUnavailable($state)
    {
        if (self::config(FullmetrixJournal::key('journal_v')) !== $state) {
            self::setConfig(FullmetrixJournal::key('journal_v'), $state);
        }
        self::setConfig(FullmetrixJournal::key('journal_retry_at'), (string) (time() + self::RETRY_EVERY_S));
    }

    public static function engineOf($shortTable)
    {
        $name = _DB_PREFIX_ . $shortTable;
        try {
            $rows = self::runOnce('SHOW TABLE STATUS LIKE \'' . str_replace(['\\', '_', '%', '\''], ['\\\\', '\\_', '\\%', '\\\''], $name) . '\'', true);
        } catch (Exception $e) {
            return null;
        }
        foreach ($rows as $row) {
            if (isset($row['Name']) && $row['Name'] === $name) {
                return isset($row['Engine']) && $row['Engine'] !== null ? (string) $row['Engine'] : null;
            }
        }

        return null;
    }

    public static function dropIfInert()
    {
        if (self::config(FullmetrixJournal::key('journal_v')) === '2') {
            return false;
        }
        try {
            self::runOnce('SET SESSION lock_wait_timeout = 2', false);
            self::runOnce('DROP TABLE IF EXISTS `' . self::table() . '`', false);
        } catch (Exception $e) {
            return false;
        }

        return true;
    }

    public static function ensureLazy()
    {
        $current = self::config(FullmetrixJournal::key('journal_v'));
        if ($current === '2') {
            return 'ready';
        }
        if (($current === 'no_innodb' || $current === 'denied')
            && (int) self::config(FullmetrixJournal::key('journal_retry_at')) > time()) {
            return $current;
        }
        $result = self::ensureTable();

        return $result['state'];
    }

    public static function isUsable()
    {
        return self::config(FullmetrixJournal::key('journal_v')) === '2';
    }

    public static function isSleeping()
    {
        if (self::config(FullmetrixJournal::key('journal_sleep')) === '1') {
            return true;
        }
        $ack = (int) self::config(FullmetrixJournal::key('journal_ack'));

        return $ack <= 0 || time() - $ack > self::SLEEP_NO_HEARTBEAT_S;
    }

    public static function state()
    {
        $current = self::config(FullmetrixJournal::key('journal_v'));
        if ($current === '2') {
            return self::isSleeping() ? 'sleeping' : 'ready';
        }
        if ($current === 'no_innodb' || $current === 'denied') {
            return $current;
        }

        return 'not_ready';
    }

    public static function ackAt()
    {
        $ack = (int) self::config(FullmetrixJournal::key('journal_ack'));

        return $ack > 0 ? $ack : null;
    }

    public static function stats()
    {
        $stats = ['rows' => 0, 'rows_capped' => false, 'oldest_t_us' => null];
        if (!self::isUsable()) {
            return $stats;
        }
        try {
            $count = (int) self::value('SELECT COUNT(*) FROM (SELECT 1 FROM `' . self::table() . '` LIMIT ' . self::COUNT_CAP . ') x');
            $oldest = self::value('SELECT `t_us` FROM `' . self::table() . '` ORDER BY `t_us`, `nonce` LIMIT 1');
        } catch (Exception $e) {
            self::repairIfMissing($e);

            return $stats;
        }
        $stats['rows'] = $count;
        $stats['rows_capped'] = $count >= self::COUNT_CAP;
        $stats['oldest_t_us'] = $oldest === null ? null : (string) $oldest;

        return $stats;
    }

    public static function enforceLocalCap()
    {
        if (self::config(FullmetrixJournal::key('journal_sleep')) === '1') {
            return false;
        }
        try {
            $count = (int) self::value('SELECT COUNT(*) FROM (SELECT 1 FROM `' . self::table() . '` LIMIT ' . self::LOCAL_ROWS_CAP . ') x');
        } catch (Exception $e) {
            self::repairIfMissing($e);

            return false;
        }
        if ($count < self::LOCAL_ROWS_CAP) {
            return false;
        }

        return self::setConfig(FullmetrixJournal::key('journal_sleep'), '1');
    }

    public static function repairIfMissing($e)
    {
        if (self::errorCode($e) !== self::ERR_NO_TABLE || !self::isUsable()) {
            return false;
        }
        self::setConfig(FullmetrixJournal::key('journal_v'), 'missing');
        $result = self::ensureTable();
        self::recordError('journal_missing', 'Journal table was missing and has been recreated (' . $result['state'] . ').');

        return $result['state'] === 'ready';
    }

    public static function recordError($step, $message)
    {
        $raw = self::config(FullmetrixJournal::key('upgrade_err'));
        $errors = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($errors)) {
            $errors = [];
        }
        $errors[] = ['step' => (string) $step, 'message' => substr((string) $message, 0, 500)];

        return self::setConfig(FullmetrixJournal::key('upgrade_err'), json_encode(array_values(array_slice($errors, -10))));
    }

    public static function read($maxRows)
    {
        $maxRows = max(1, min(self::MAX_READ_ROWS, (int) $maxRows));
        try {
            $rows = self::rows(
                'SELECT `t_us`, `nonce`, `id_shop`, `entity`, `entity_id`, `sub_id` FROM `' . self::table()
                . '` ORDER BY `t_us`, `nonce` LIMIT ' . ($maxRows + 1)
            );
        } catch (Exception $e) {
            if (self::repairIfMissing($e)) {
                return ['rows' => [], 'more' => false];
            }
            throw $e;
        }
        $more = count($rows) > $maxRows;
        if ($more) {
            $rows = array_slice($rows, 0, $maxRows);
        }
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                (string) $row['t_us'],
                (int) $row['nonce'],
                (int) $row['id_shop'],
                (int) $row['entity'],
                (int) $row['entity_id'],
                (int) $row['sub_id'],
            ];
        }

        return ['rows' => $out, 'more' => $more];
    }

    public static function normalizeKeys($keys)
    {
        $valid = [];
        if (!is_array($keys)) {
            return $valid;
        }
        foreach ($keys as $key) {
            if (!is_array($key) || count($key) !== 2 || !isset($key[0], $key[1])) {
                continue;
            }
            $tUs = is_int($key[0]) ? (string) $key[0] : $key[0];
            $nonce = $key[1];
            if (!is_string($tUs) || !preg_match('/^\d{1,20}$/', $tUs)) {
                continue;
            }
            if (is_string($nonce) && preg_match('/^\d{1,10}$/', $nonce)) {
                $nonce = (int) $nonce;
            }
            if (!is_int($nonce) || $nonce < 0 || $nonce > 4294967295) {
                continue;
            }
            $tUs = ltrim($tUs, '0');
            $valid[$tUs . ':' . $nonce] = [$tUs === '' ? '0' : $tUs, $nonce];
            if (count($valid) >= self::MAX_ACK_KEYS) {
                break;
            }
        }

        return array_values($valid);
    }

    public static function ackSql(array $batch)
    {
        $clauses = [];
        foreach ($batch as $key) {
            $clauses[] = '(`t_us` = ' . $key[0] . ' AND `nonce` = ' . (int) $key[1] . ')';
        }

        return 'DELETE FROM `' . self::table() . '` WHERE ' . implode(' OR ', $clauses);
    }

    public static function ackBatchSize($rows)
    {
        return (int) min(self::ACK_BATCH, max(1, floor($rows / self::ACK_ROWS_PER_KEY)));
    }

    private static function countForAck()
    {
        try {
            return (int) self::value('SELECT COUNT(*) FROM (SELECT 1 FROM `' . self::table() . '` LIMIT ' . (self::ACK_BATCH * self::ACK_ROWS_PER_KEY) . ') x');
        } catch (Exception $e) {
            return 0;
        }
    }

    public static function ack($keys, $deadline)
    {
        $keys = self::normalizeKeys($keys);
        if (empty($keys)) {
            return [];
        }
        if (!self::isUsable()) {
            return $keys;
        }
        self::prepareSession();
        $acked = [];
        $rows = 0;
        $offset = 0;
        $total = count($keys);
        while ($offset < $total) {
            if (microtime(true) > $deadline) {
                break;
            }
            if ($rows < self::ACK_BATCH * self::ACK_ROWS_PER_KEY) {
                $rows = self::countForAck();
            }
            $batch = array_slice($keys, $offset, self::ackBatchSize($rows));
            $offset += count($batch);
            try {
                $deleted = self::exec(self::ackSql($batch));
            } catch (Exception $e) {
                if (self::errorCode($e) === self::ERR_NO_TABLE) {
                    self::repairIfMissing($e);

                    return $keys;
                }
                continue;
            }
            $rows = max(0, $rows - (int) $deleted);
            if ($rows < self::ACK_BATCH * self::ACK_ROWS_PER_KEY) {
                $rows = 0;
            }
            foreach ($batch as $key) {
                $acked[] = $key;
            }
        }

        return $acked;
    }

    public static function heartbeat()
    {
        if (!self::isUsable()) {
            return false;
        }
        $ack = (int) self::config(FullmetrixJournal::key('journal_ack'));
        if ($ack > 0 && time() - $ack < self::HEARTBEAT_EVERY_S) {
            return false;
        }

        return self::setConfig(FullmetrixJournal::key('journal_ack'), (string) time());
    }

    public static function updateSleep(array $readRows)
    {
        if (!self::isUsable()) {
            return;
        }
        $sleeping = self::config(FullmetrixJournal::key('journal_sleep')) === '1';
        if (empty($readRows)) {
            if ($sleeping) {
                self::setConfig(FullmetrixJournal::key('journal_sleep'), '0');
                self::setConfig(FullmetrixJournal::key('journal_ack'), (string) time());
            }

            return;
        }
        $oldest = (float) $readRows[0][0] / 1000000;
        if (!$sleeping && $oldest < time() - self::SLEEP_AFTER_S) {
            self::setConfig(FullmetrixJournal::key('journal_sleep'), '1');
        }
    }
}
