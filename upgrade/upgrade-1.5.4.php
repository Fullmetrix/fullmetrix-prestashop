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
    if (PHP_VERSION_ID < 70100) {
        return true;
    }
    $hooks = [
        'actionObjectCombinationAddAfter',
        'actionObjectCombinationUpdateAfter',
        'actionObjectCombinationDeleteAfter',
        'actionObjectSpecificPriceAddAfter',
        'actionObjectSpecificPriceUpdateAfter',
        'actionObjectSpecificPriceDeleteAfter',
    ];
    foreach ($hooks as $hook) {
        try {
            if (!$module->registerHook($hook)) {
                $module->recordUpgradeError('register_hooks_1_5_4', $hook);
            }
        } catch (Exception $e) {
            $module->recordUpgradeError('register_hooks_1_5_4', $hook . ': ' . $e->getMessage());
        } catch (Throwable $e) {
            $module->recordUpgradeError('register_hooks_1_5_4', $hook . ': ' . $e->getMessage());
        }
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
