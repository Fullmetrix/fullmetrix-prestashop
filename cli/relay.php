<?php
/**
 * Fullmetrix - E-commerce analytics platform connector
 *
 * @author    Fullmetrix <contact@fullmetrix.com>
 * @copyright 2024-2026 Fullmetrix
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (PHP_SAPI !== 'cli') {
    exit;
}

if (PHP_VERSION_ID < 70100) {
    fwrite(STDERR, 'Fullmetrix : PHP 7.1 ou plus est requis pour le relais (PHP ' . PHP_VERSION . " détecté).\n");
    exit(1);
}

$fullmetrixRoot = realpath(dirname(__FILE__) . '/../../..');
if ($fullmetrixRoot === false || !is_file($fullmetrixRoot . '/config/config.inc.php')) {
    fwrite(STDERR, "Fullmetrix : racine PrestaShop introuvable.\n");
    exit(1);
}

$fullmetrixForce = in_array('--i-know-the-owner', isset($argv) && is_array($argv) ? $argv : [], true);
if (!function_exists('posix_geteuid')) {
    if (!$fullmetrixForce) {
        fwrite(STDERR, "Fullmetrix : l'extension posix est absente, impossible de vérifier l'utilisateur. Relancez avec --i-know-the-owner si ce cron tourne avec le propriétaire des fichiers de la boutique.\n");
        exit(1);
    }
} else {
    $fullmetrixCacheDir = is_dir($fullmetrixRoot . '/var/cache') ? $fullmetrixRoot . '/var/cache' : $fullmetrixRoot . '/cache';
    $fullmetrixOwner = @fileowner($fullmetrixCacheDir);
    if ($fullmetrixOwner !== false && posix_geteuid() !== $fullmetrixOwner) {
        $fullmetrixInfo = function_exists('posix_getpwuid') ? @posix_getpwuid($fullmetrixOwner) : false;
        $fullmetrixName = is_array($fullmetrixInfo) ? $fullmetrixInfo['name'] : (string) $fullmetrixOwner;
        fwrite(STDERR, 'Fullmetrix : lancez ce cron avec l\'utilisateur ' . $fullmetrixName . ', propriétaire de ' . $fullmetrixCacheDir . ".\n");
        exit(1);
    }
}

require_once $fullmetrixRoot . '/config/config.inc.php';

if (!defined('_PS_VERSION_')) {
    exit(1);
}

require_once _PS_MODULE_DIR_ . 'fullmetrixconnector/classes/FullmetrixRelay.php';

$fullmetrixModule = Module::isEnabled('fullmetrixconnector') ? Module::getInstanceByName('fullmetrixconnector') : false;
if (!$fullmetrixModule) {
    exit(0);
}
if (method_exists($fullmetrixModule, 'getRuntimeContext')) {
    FullmetrixFormatContext::bind($fullmetrixModule->getRuntimeContext());
}

$fullmetrixStatus = FullmetrixRelay::guardedRun('cli', FullmetrixSignature::randomHex(8));
fwrite(STDOUT, 'Fullmetrix relay: ' . $fullmetrixStatus . "\n");
exit(in_array($fullmetrixStatus, ['ok', 'nothing', 'inactive', 'locked', 'throttled'], true) ? 0 : 2);
