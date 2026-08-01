<?php
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
