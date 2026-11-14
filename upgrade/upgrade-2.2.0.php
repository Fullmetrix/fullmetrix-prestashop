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

function upgrade_module_2_2_0($module)
{
    if (PHP_VERSION_ID < 70100) {
        return true;
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
