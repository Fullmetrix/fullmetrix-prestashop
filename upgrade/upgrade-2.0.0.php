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

function upgrade_module_2_0_0($module)
{
    if (PHP_VERSION_ID < 70100) {
        return true;
    }
    try {
        $module->installJournal();
    } catch (Exception $e) {
        $module->recordUpgradeError('journal', $e->getMessage());
    } catch (Throwable $e) {
        $module->recordUpgradeError('journal', $e->getMessage());
    }

    try {
        Configuration::updateGlobalValue(FullmetrixJournal::key('journal_ack'), (string) time());
    } catch (Exception $e) {
        $module->recordUpgradeError('heartbeat', $e->getMessage());
    } catch (Throwable $e) {
        $module->recordUpgradeError('heartbeat', $e->getMessage());
    }

    try {
        if (empty(Configuration::getGlobalValue(FullmetrixJournal::key('flags')))) {
            $legacy = json_decode((string) Configuration::get(FullmetrixJournal::key('plugin_config')), true);
            $tracker = !(is_array($legacy) && isset($legacy['trackerEnabled']) && $legacy['trackerEnabled'] === false);
            Configuration::updateGlobalValue(FullmetrixJournal::key('flags'), json_encode(['v' => 0, 'tracker' => $tracker]));
        }
    } catch (Exception $e) {
        $module->recordUpgradeError('flags', $e->getMessage());
    } catch (Throwable $e) {
        $module->recordUpgradeError('flags', $e->getMessage());
    }

    try {
        foreach ($module->registerFullmetrixHooks(true) as $failure) {
            $module->recordUpgradeError('register_hooks', $failure);
        }
    } catch (Exception $e) {
        $module->recordUpgradeError('register_hooks', $e->getMessage());
    } catch (Throwable $e) {
        $module->recordUpgradeError('register_hooks', $e->getMessage());
    }

    $obsolete = [
        'FULLMETRIX_PLUGIN_CONFIG',
        'FULLMETRIX_LOGS',
        'FULLMETRIX_CART_RESOLVE_DOWN',
        'FULLMETRIX_CART_RESOLVE_FAILURES',
        'FULLMETRIX_EXPORT_COUNT',
        'FULLMETRIX_SYNC_IN_PROGRESS',
    ];
    foreach ($obsolete as $name) {
        try {
            Configuration::deleteByName($name);
        } catch (Exception $e) {
            $module->recordUpgradeError('cleanup_config', $e->getMessage());
        } catch (Throwable $e) {
            $module->recordUpgradeError('cleanup_config', $e->getMessage());
        }
    }

    $db = null;
    $previous = 0;
    try {
        $db = Db::getInstance();
        $previous = (int) $db->getValue('SELECT @@SESSION.lock_wait_timeout');
        $db->execute('SET SESSION lock_wait_timeout = 2');
        if (!$db->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'fullmetrix_cart_contacts`')) {
            $module->recordUpgradeError('drop_cart_contacts', 'DROP TABLE refused or timed out');
        }
    } catch (Exception $e) {
        $module->recordUpgradeError('drop_cart_contacts', $e->getMessage());
    } catch (Throwable $e) {
        $module->recordUpgradeError('drop_cart_contacts', $e->getMessage());
    }
    try {
        if ($db !== null && $previous > 0) {
            $db->execute('SET SESSION lock_wait_timeout = ' . $previous);
        }
    } catch (Exception $e) {
        $module->recordUpgradeError('lock_wait_restore', $e->getMessage());
    } catch (Throwable $e) {
        $module->recordUpgradeError('lock_wait_restore', $e->getMessage());
    }

    try {
        $module->flushUpgradeErrors();
    } catch (Exception $e) {
        return true;
    } catch (Throwable $e) {
        return true;
    }

    return true;
}
