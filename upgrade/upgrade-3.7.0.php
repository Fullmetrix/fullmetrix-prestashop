<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade to 3.7.0
 * - Create fullmetrix_cart_contacts table for abandoned cart contact capture
 * - Register displayHeader hook for checkout JavaScript
 */
function upgrade_module_3_7_0($module)
{
    // Create cart contacts table
    $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'fullmetrix_cart_contacts` (
        `id_cart` INT(10) UNSIGNED NOT NULL,
        `email` VARCHAR(255) DEFAULT NULL,
        `phone` VARCHAR(50) DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id_cart`)
    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';

    $tableCreated = Db::getInstance()->execute($sql);

    // Register displayHeader hook
    $hookRegistered = $module->registerHook('displayHeader');

    return $tableCreated && $hookRegistered;
}
