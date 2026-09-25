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
require_once dirname(__FILE__) . '/FullmetrixChanges.php';
require_once dirname(__FILE__) . '/FullmetrixInstallation.php';

class FullmetrixHealth
{
    private static $engineTables = ['stock_available', 'specific_price', 'product_attribute'];

    public static function moduleVersion()
    {
        return FullmetrixStreamExporter::moduleVersion();
    }

    public static function local()
    {
        try {
            return self::build();
        } catch (Exception $e) {
            return self::minimal();
        } catch (Throwable $e) {
            return self::minimal();
        }
    }

    public static function claimSummary()
    {
        $stats = FullmetrixJournalStore::stats();

        return [
            'state' => FullmetrixJournalStore::state(),
            'rows' => $stats['rows'],
            'oldest_t_us' => $stats['oldest_t_us'],
            'isolation' => FullmetrixJournalStore::isUsable() ? FullmetrixJournalStore::plannedIsolation() : null,
        ];
    }

    private static function minimal()
    {
        return [
            'v' => 1,
            'module_version' => self::moduleVersion(),
            'ps_version' => _PS_VERSION_,
            'php_version' => PHP_VERSION,
            'mysql_version' => '',
            'journal' => [
                'state' => 'not_ready',
                'rows' => 0,
                'rows_capped' => false,
                'oldest_t_us' => null,
                'ack_at' => null,
                'isolation' => null,
                'log_bin' => null,
                'binlog_format' => null,
            ],
            'upgrade_errors' => [],
            'breaker' => ['open' => new stdClass()],
            'flags_v' => 0,
            'hooks' => [],
            'max_ids' => ['order' => 0, 'order_history' => 0, 'order_slip' => 0, 'customer' => 0],
            'auto_increment_increment' => 1,
            'engines' => new stdClass(),
            'statsdata' => false,
            'base_uri' => defined('__PS_BASE_URI__') ? (string) __PS_BASE_URI__ : '/',
            'installation_id' => FullmetrixInstallation::id(),
            'server_time' => time(),
        ];
    }

    private static function build()
    {
        $stats = FullmetrixJournalStore::stats();
        $binlog = FullmetrixJournalStore::binlog();
        $usable = FullmetrixJournalStore::isUsable();
        $isolation = FullmetrixJournalStore::isolation();
        if ($isolation === null && $usable) {
            $isolation = FullmetrixJournalStore::plannedIsolation();
        }

        return [
            'v' => 1,
            'module_version' => self::moduleVersion(),
            'ps_version' => _PS_VERSION_,
            'php_version' => PHP_VERSION,
            'mysql_version' => self::mysqlVersion(),
            'journal' => [
                'state' => FullmetrixJournalStore::state(),
                'rows' => $stats['rows'],
                'rows_capped' => $stats['rows_capped'],
                'oldest_t_us' => $stats['oldest_t_us'],
                'ack_at' => FullmetrixJournalStore::ackAt(),
                'isolation' => $usable ? $isolation : null,
                'log_bin' => $binlog['log_bin'],
                'binlog_format' => $binlog['format'],
            ],
            'upgrade_errors' => self::upgradeErrors(),
            'breaker' => FullmetrixChanges::breaker(),
            'flags_v' => FullmetrixChanges::flagsVersion(),
            'hooks' => self::hooks(),
            'max_ids' => self::maxIds(),
            'auto_increment_increment' => FullmetrixChanges::autoIncrementStep(),
            'engines' => FullmetrixChanges::obj(self::engines()),
            'statsdata' => self::statsdata(),
            'base_uri' => defined('__PS_BASE_URI__') ? (string) __PS_BASE_URI__ : '/',
            'installation_id' => FullmetrixInstallation::id(),
            'server_time' => time(),
        ];
    }

    private static function mysqlVersion()
    {
        try {
            return (string) FullmetrixJournalStore::value('SELECT VERSION()');
        } catch (Exception $e) {
            return '';
        }
    }

    public static function upgradeErrors()
    {
        $raw = FullmetrixJournalStore::config(FullmetrixJournal::key('upgrade_err'));
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $out = [];
        if (!is_array($decoded)) {
            return $out;
        }
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $out[] = [
                'step' => isset($entry['step']) ? substr((string) $entry['step'], 0, 64) : '',
                'message' => isset($entry['message']) ? substr((string) $entry['message'], 0, 500) : '',
            ];
            if (count($out) >= 10) {
                break;
            }
        }

        return $out;
    }

    public static function hooks()
    {
        $idModule = (int) Module::getModuleIdByName('fullmetrixconnector');
        if ($idModule <= 0) {
            return [];
        }
        $names = [];
        try {
            foreach (FullmetrixJournalStore::rows(
                'SELECT DISTINCT h.`name` FROM `' . _DB_PREFIX_ . 'hook_module` hm INNER JOIN `' . _DB_PREFIX_ . 'hook` h ON (h.`id_hook` = hm.`id_hook`)'
                . ' WHERE hm.`id_module` = ' . $idModule . ' ORDER BY h.`name`'
            ) as $row) {
                $names[] = (string) $row['name'];
            }
        } catch (Exception $e) {
            return [];
        }

        return $names;
    }

    private static function maxIds()
    {
        $p = _DB_PREFIX_;
        $out = [];
        foreach ([
            'order' => 'SELECT MAX(`id_order`) FROM `' . $p . 'orders`',
            'order_history' => 'SELECT MAX(`id_order_history`) FROM `' . $p . 'order_history`',
            'order_slip' => 'SELECT MAX(`id_order_slip`) FROM `' . $p . 'order_slip`',
            'customer' => 'SELECT MAX(`id_customer`) FROM `' . $p . 'customer`',
        ] as $key => $sql) {
            try {
                $out[$key] = (int) FullmetrixJournalStore::value($sql);
            } catch (Exception $e) {
                $out[$key] = 0;
            }
        }

        return $out;
    }

    private static function engines()
    {
        $out = [];
        foreach (self::$engineTables as $table) {
            $engine = FullmetrixJournalStore::engineOf($table);
            if ($engine !== null) {
                $out[$table] = $engine;
            }
        }

        return $out;
    }

    private static function statsdata()
    {
        try {
            return (bool) Module::isEnabled('statsdata');
        } catch (Exception $e) {
            return false;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function shopRoot()
    {
        return defined('_PS_ROOT_DIR_') ? rtrim(_PS_ROOT_DIR_, '/') : rtrim(dirname(dirname(dirname(dirname(__FILE__)))), '/');
    }

    public static function cacheOwnerDir($root)
    {
        return is_dir($root . '/var/cache') ? $root . '/var/cache' : $root . '/cache';
    }

    public static function cliPhpBinary()
    {
        $candidates = [];
        if (defined('PHP_BINARY') && PHP_BINARY !== '' && stripos(basename(PHP_BINARY), 'fpm') === false
            && stripos(basename(PHP_BINARY), 'httpd') === false && stripos(basename(PHP_BINARY), 'apache') === false) {
            $candidates[] = PHP_BINARY;
        }
        if (defined('PHP_BINDIR')) {
            $candidates[] = PHP_BINDIR . '/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
            $candidates[] = PHP_BINDIR . '/php';
        }
        foreach ($candidates as $candidate) {
            if (@is_file($candidate) && @is_executable($candidate)) {
                return $candidate;
            }
        }

        return 'php';
    }

    public static function cronCommand()
    {
        if (!function_exists('posix_getpwuid')) {
            return '';
        }
        try {
            $root = self::shopRoot();
            $owner = @fileowner(self::cacheOwnerDir($root));
            if ($owner === false) {
                return '';
            }
            $info = @posix_getpwuid($owner);
            $user = is_array($info) ? $info['name'] : (string) $owner;
            $script = $root . '/modules/fullmetrixconnector/cli/relay.php';

            return '* * * * * sudo -u ' . escapeshellarg($user) . ' ' . escapeshellarg(self::cliPhpBinary()) . ' ' . escapeshellarg($script) . ' > /dev/null 2>&1';
        } catch (Exception $e) {
            return '';
        } catch (Throwable $e) {
            return '';
        }
    }
}
