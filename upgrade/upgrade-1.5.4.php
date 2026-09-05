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

function upgrade_module_1_5_4($module)
{
    return $module->registerHook('actionObjectCombinationAddAfter')
        && $module->registerHook('actionObjectCombinationUpdateAfter')
        && $module->registerHook('actionObjectCombinationDeleteAfter')
        && $module->registerHook('actionObjectSpecificPriceAddAfter')
        && $module->registerHook('actionObjectSpecificPriceUpdateAfter')
        && $module->registerHook('actionObjectSpecificPriceDeleteAfter');
}
