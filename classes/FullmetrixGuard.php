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

class FullmetrixGuard
{
    private static $maxWrites = 500;
    private static $windowSeconds = 60;
    private static $sleepAfter = 21600;
    private static $defaultOrigin = 'https://fullmetrix.com';
    private static $benignErrors = [1205, 1213];
    private static $windowStart = 0;
    private static $bootAt = 0;
    private static $depth = 0;
    private static $flags = false;
    private static $breaker = false;
    private static $seen = [];
    private static $writes = 0;
    private static $overflow = [];
    private static $cut = [];
    private static $tripped = [];
    private static $ephemeralUsed = false;

    public static function record($hook, $entity, $params, $idPath, $subPath)
    {
        if (PHP_VERSION_ID < 70100) {
            return;
        }
        if (self::$depth > 0) {
            return;
        }
        ++self::$depth;
        set_error_handler(function () {
            return true;
        });
        try {
            self::recordEnabled((string) $hook, FullmetrixJournal::entity((string) $entity), $params, $idPath, $subPath);
        } catch (Exception $e) {
        } catch (Throwable $e) {
        }
        restore_error_handler();
        --self::$depth;
    }

    public static function renderHeader()
    {
        if (PHP_VERSION_ID < 70100) {
            return '';
        }
        set_error_handler(function () {
            return true;
        });
        try {
            $html = self::header();
        } catch (Exception $e) {
            $html = '';
        } catch (Throwable $e) {
            $html = '';
        }
        restore_error_handler();

        return $html;
    }

    public static function renderBackOfficeRelay()
    {
        if (PHP_VERSION_ID < 70100) {
            return '';
        }
        set_error_handler(function () {
            return true;
        });
        try {
            $html = self::backOfficeRelay();
        } catch (Exception $e) {
            $html = '';
        } catch (Throwable $e) {
            $html = '';
        }
        restore_error_handler();

        return $html;
    }

    public static function isConnected()
    {
        if (PHP_VERSION_ID < 70100) {
            return false;
        }

        return self::connected();
    }

    public static function trip($hook, $code)
    {
        if (PHP_VERSION_ID < 70100) {
            return;
        }
        $hook = (string) $hook;
        self::$cut[$hook] = true;
        if (isset(self::$tripped[$hook]) || self::$ephemeralUsed) {
            return;
        }
        self::$tripped[$hook] = true;
        self::$ephemeralUsed = true;
        set_error_handler(function () {
            return true;
        });
        $pdo = null;
        $mysqli = null;
        try {
            $server = (string) _DB_SERVER_;
            $host = $server;
            $port = 0;
            $socket = '';
            if (preg_match('/^(.*):([0-9]+)$/', $server, $matches)) {
                $host = $matches[1];
                $port = (int) $matches[2];
            } elseif (preg_match('#^(.*):(/.*)$#', $server, $matches)) {
                $host = $matches[1];
                $socket = $matches[2];
            } elseif (preg_match('#^/#', $server)) {
                $host = '';
                $socket = $server;
            }
            $table = '`' . _DB_PREFIX_ . 'configuration`';
            $where = " WHERE name = '" . FullmetrixJournal::key('breaker') . "' AND id_shop IS NULL AND id_shop_group IS NULL";
            $settings = "SET SESSION innodb_lock_wait_timeout = 1, lock_wait_timeout = 1, sql_mode = ''";
            if (extension_loaded('pdo_mysql')) {
                $dsn = 'mysql:dbname=' . _DB_NAME_ . ';'
                    . ($socket !== '' ? 'unix_socket=' . $socket : 'host=' . $host . ($port > 0 ? ';port=' . $port : ''));
                $pdo = new PDO($dsn, _DB_USER_, _DB_PASSWD_, [
                    PDO::ATTR_TIMEOUT => 1,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
                $pdo->exec($settings);
                $pdo->exec('SET NAMES utf8');
                $statement = $pdo->query('SELECT value FROM ' . $table . $where . ' LIMIT 1');
                $raw = $statement ? $statement->fetchColumn() : false;
                $value = $pdo->quote(self::breakerValue($raw, $hook, $code));
                $pdo->exec(self::breakerWrite($table, $where, $value, $raw === false));
            } elseif (extension_loaded('mysqli')) {
                $mysqli = new mysqli();
                $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 1);
                if ($mysqli->real_connect($host !== '' ? $host : 'localhost', _DB_USER_, _DB_PASSWD_, _DB_NAME_, $port > 0 ? $port : 3306, $socket !== '' ? $socket : null)) {
                    $mysqli->query($settings);
                    $mysqli->query('SET NAMES utf8');
                    $result = $mysqli->query('SELECT value FROM ' . $table . $where . ' LIMIT 1');
                    $row = is_object($result) ? $result->fetch_row() : null;
                    $raw = is_array($row) ? $row[0] : false;
                    $value = "'" . $mysqli->real_escape_string(self::breakerValue($raw, $hook, $code)) . "'";
                    $mysqli->query(self::breakerWrite($table, $where, $value, $raw === false));
                }
            }
        } catch (Exception $e) {
        } catch (Throwable $e) {
        }
        try {
            if (is_object($mysqli)) {
                $mysqli->close();
            }
        } catch (Exception $e) {
        } catch (Throwable $e) {
        }
        $pdo = null;
        $mysqli = null;
        restore_error_handler();
    }

    private static function recordEnabled($hook, $entity, $params, $idPath, $subPath)
    {
        if ($entity <= 0) {
            return;
        }
        $now = microtime(true);
        if (self::$bootAt === 0) {
            self::$bootAt = time();
        }
        if ($now - self::$windowStart > self::$windowSeconds) {
            if (self::$windowStart > 0 && PHP_SAPI === 'cli') {
                Configuration::loadConfiguration();
                self::$bootAt = time();
            }
            self::$windowStart = $now;
            self::$seen = [];
            self::$writes = 0;
            self::$overflow = [];
            self::$cut = [];
            self::$breaker = false;
            self::$flags = false;
        }
        if (!self::connected() || self::flagOff('module') || self::flagOff('journal') || self::hookOff($hook)) {
            return;
        }
        if (Configuration::get(FullmetrixJournal::key('journal_v')) !== FullmetrixJournal::state('ready')) {
            return;
        }
        if (self::asleep() || self::isOpen($hook)) {
            return;
        }
        foreach (self::collect($params, $idPath, $subPath) as $row) {
            self::writeRow($hook, $entity, $row[0], $row[1], $row[2]);
            if (isset(self::$cut[$hook])) {
                return;
            }
        }
    }

    private static function writeRow($hook, $entity, $id, $sub, $shop)
    {
        if ($id <= 0) {
            return;
        }
        if ($shop <= 0) {
            $shop = self::contextShop();
        }
        $key = $entity . ':' . $id . ':' . $sub . ':' . $shop;
        if (isset(self::$seen[$key])) {
            return;
        }
        self::$seen[$key] = true;
        if (self::$writes >= self::$maxWrites) {
            if (isset(self::$overflow[$entity])) {
                return;
            }
            self::$overflow[$entity] = true;
            $id = $entity;
            $sub = 0;
            $entity = FullmetrixJournal::entity('overflow');
        }
        ++self::$writes;
        try {
            $code = FullmetrixJournal::write($shop, $entity, $id, $sub);
        } catch (Exception $e) {
            $code = self::errorCode($e);
        } catch (Throwable $e) {
            $code = self::errorCode($e);
        }
        if ($code === 0 || in_array($code, self::$benignErrors, true)) {
            return;
        }
        try {
            self::trip($hook, $code);
        } catch (Exception $e) {
        } catch (Throwable $e) {
        }
    }

    private static function errorCode($error)
    {
        if ($error instanceof PDOException && is_array($error->errorInfo) && isset($error->errorInfo[1])) {
            return (int) $error->errorInfo[1];
        }
        $code = (int) $error->getCode();

        return $code > 0 ? $code : -1;
    }

    private static function collect($params, $idPath, $subPath)
    {
        if (!is_array($params) || !is_string($idPath)) {
            return [];
        }
        if (preg_match('/^([A-Za-z_]+)\[\]\.([A-Za-z_]+)$/', $idPath, $matches)) {
            $rows = [];
            if (isset($params[$matches[1]]) && is_array($params[$matches[1]])) {
                foreach ($params[$matches[1]] as $item) {
                    $rows[] = [self::property($item, $matches[2]), 0, self::property($item, 'id_shop')];
                }
            }

            return $rows;
        }
        $holder = null;
        if (preg_match('/^([A-Za-z_]+)\./', $idPath, $matches) && isset($params[$matches[1]])) {
            $holder = $params[$matches[1]];
        }

        $shop = self::property($holder, 'id_shop');
        if ($shop <= 0 && isset($params['id_shop'])) {
            $shop = self::integer($params['id_shop']);
        }

        return [[self::read($params, $idPath), self::read($params, $subPath), $shop]];
    }

    private static function read($params, $path)
    {
        if (!is_string($path)) {
            return 0;
        }
        if (preg_match('/^([A-Za-z_]+)\.([A-Za-z_]+)$/', $path, $matches)) {
            return isset($params[$matches[1]]) ? self::property($params[$matches[1]], $matches[2]) : 0;
        }

        return isset($params[$path]) ? self::integer($params[$path]) : 0;
    }

    private static function property($object, $name)
    {
        if (!is_object($object) || !property_exists($object, $name) || !isset($object->$name)) {
            return 0;
        }

        return self::integer($object->$name);
    }

    private static function integer($value)
    {
        if (is_int($value)) {
            return $value > 0 ? $value : 0;
        }
        if (is_string($value) && preg_match('/^[0-9]{1,10}$/', $value)) {
            return (int) $value;
        }

        return 0;
    }

    private static function contextShop()
    {
        if (!Configuration::get('PS_MULTISHOP_FEATURE_ACTIVE')) {
            return (int) Configuration::get('PS_SHOP_DEFAULT');
        }
        $shop = (int) Shop::getContextShopID(true);

        return $shop > 0 ? $shop : (int) Configuration::get('PS_SHOP_DEFAULT');
    }

    private static function connected()
    {
        $registered = Configuration::get(FullmetrixJournal::key('registered'));
        $code = Configuration::get(FullmetrixJournal::key('code'));
        $secret = Configuration::get(FullmetrixJournal::key('secret'));

        return !empty($registered) && !empty($code) && !empty($secret);
    }

    private static function flags()
    {
        if (self::$flags === false) {
            $raw = Configuration::get(FullmetrixJournal::key('flags'));
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            self::$flags = is_array($decoded) ? $decoded : null;
        }

        return self::$flags;
    }

    private static function flagOff($name)
    {
        $flags = self::flags();

        return is_array($flags) && isset($flags[$name]) && $flags[$name] === false;
    }

    private static function hookOff($hook)
    {
        $flags = self::flags();

        return is_array($flags) && isset($flags['hooks']) && is_array($flags['hooks'])
            && isset($flags['hooks'][$hook]) && $flags['hooks'][$hook] === false;
    }

    private static function trackerOn()
    {
        if (is_array(self::flags())) {
            return !self::flagOff('tracker');
        }
        $raw = Configuration::get(FullmetrixJournal::key('plugin_config'));
        $legacy = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return !(is_array($legacy) && isset($legacy['trackerEnabled']) && $legacy['trackerEnabled'] === false);
    }

    private static function asleep()
    {
        if (Configuration::get(FullmetrixJournal::key('journal_sleep')) === '1') {
            return true;
        }
        $heartbeat = Configuration::get(FullmetrixJournal::key('journal_ack'));
        $heartbeat = is_string($heartbeat) && preg_match('/^[0-9]{1,12}$/', $heartbeat) ? (int) $heartbeat : 0;

        return (self::$bootAt > 0 ? self::$bootAt : time()) - $heartbeat > self::$sleepAfter;
    }

    private static function isOpen($hook)
    {
        if (isset(self::$cut[$hook])) {
            return true;
        }
        if (self::$breaker === false) {
            $raw = Configuration::get(FullmetrixJournal::key('breaker'));
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            self::$breaker = is_array($decoded) && isset($decoded['open']) && is_array($decoded['open']) ? $decoded['open'] : [];
        }

        return isset(self::$breaker[$hook]) && (int) self::$breaker[$hook] > time();
    }

    private static function breakerValue($raw, $hook, $code)
    {
        $now = time();
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $open = self::section($decoded, 'open');
        $trips = self::section($decoded, 'trips');
        $errors = self::section($decoded, 'err');
        foreach ($open as $name => $until) {
            if ((int) $until <= $now) {
                unset($open[$name]);
            }
        }
        $recent = [];
        if (isset($trips[$hook]) && is_array($trips[$hook])) {
            foreach ($trips[$hook] as $at) {
                if ((int) $at > $now - 86400) {
                    $recent[] = (int) $at;
                }
            }
        }
        $recent[] = $now;
        $recent = array_slice($recent, -20);
        $count = count($recent);
        $open[$hook] = $now + ($count <= 3 ? 900 : ($count <= 7 ? 3600 : 21600));
        $trips[$hook] = $recent;
        $errors[$hook] = (int) $code;

        return json_encode(['open' => (object) $open, 'trips' => (object) $trips, 'err' => (object) $errors]);
    }

    private static function section($decoded, $name)
    {
        return is_array($decoded) && isset($decoded[$name]) && is_array($decoded[$name]) ? $decoded[$name] : [];
    }

    private static function breakerWrite($table, $where, $value, $insert)
    {
        if ($insert) {
            return 'INSERT INTO ' . $table . ' (id_shop_group, id_shop, name, value, date_add, date_upd) VALUES (NULL, NULL, \''
                . FullmetrixJournal::key('breaker') . '\', ' . $value . ', NOW(), NOW())';
        }

        return 'UPDATE ' . $table . ' SET value = ' . $value . ', date_upd = NOW()' . $where;
    }

    private static function header()
    {
        if (!self::connected() || self::flagOff('module') || !self::trackerOn()) {
            return '';
        }
        $code = Configuration::get(FullmetrixJournal::key('code'));
        if (!is_string($code) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $code)) {
            return '';
        }
        $version = FullmetrixConnector::pluginVersion();
        $base = self::base();
        $attributes = ['data-key' => $code, 'data-fm-v' => $version, 'data-fm-base' => $base];
        if (self::flagOff('sessionSignals')) {
            $attributes['data-fm-signals'] = '0';
        }
        $json = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $html = '<script>(function(){try{var a=' . json_encode($attributes, $json) . ',u=' . json_encode(self::origin() . '/t.js?v=' . $version, $json)
            . ';function l(){try{var s=document.createElement("script"),k;s.async=true;s.src=u;for(k in a){if(Object.prototype.hasOwnProperty.call(a,k)){s.setAttribute(k,a[k])}}'
            . '(document.head||document.documentElement).appendChild(s)}catch(e){}}if(document.readyState==="complete"){l()}else{window.addEventListener("load",l)}}catch(e){}})();</script>';
        if (!self::flagOff('recover')) {
            $html .= self::recoverFallback($base);
        }

        return $html;
    }

    private static function recoverFallback($base)
    {
        $query = '';
        if (isset($_GET['fm_cart_id']) && is_string($_GET['fm_cart_id']) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $_GET['fm_cart_id'])) {
            $query = 'fm_cart_id=' . rawurlencode($_GET['fm_cart_id']);
        } elseif (isset($_GET['fm_cart'], $_GET['fm_cart_sig']) && is_string($_GET['fm_cart']) && is_string($_GET['fm_cart_sig'])
            && preg_match('/^(?=.{1,4096}$)[A-Za-z0-9_-]+={0,2}$/', $_GET['fm_cart'])
            && preg_match('/^[a-f0-9]{16,128}$/', $_GET['fm_cart_sig'])) {
            $query = 'fm_cart=' . rawurlencode($_GET['fm_cart']) . '&fm_cart_sig=' . rawurlencode($_GET['fm_cart_sig']);
        }
        if ($query === '') {
            return '';
        }
        $url = $base . 'index.php?fc=module&module=fullmetrixconnector&controller=recover&' . $query;

        return '<noscript><meta http-equiv="refresh" content="0;url=' . self::escape($url) . '"></noscript>';
    }

    private static function backOfficeRelay()
    {
        if (!self::connected() || self::flagOff('module') || self::flagOff('relay') || self::flagOff('relayBo')) {
            return '';
        }
        $code = Configuration::get(FullmetrixJournal::key('code'));
        if (!is_string($code) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $code)) {
            return '';
        }
        $proof = hash_hmac('sha256', 'bo|' . gmdate('Y-m-d'), (string) Configuration::get(FullmetrixJournal::key('secret')));

        return '<script>(function(){try{var k="fm_relay_at",n=Date.now(),l=+(localStorage.getItem(k)||0);if(n-l<120000)return;'
            . 'localStorage.setItem(k,String(n));var x=new XMLHttpRequest();x.open("GET","' . self::origin()
            . '/api/plugin/relay/ticket?key=' . $code . '&src=bo&p=' . $proof . '");x.onload=function(){var t=x.responseText;'
            . 'if(x.status===200&&/^[A-Za-z0-9._-]{20,600}$/.test(t)){fetch(location.origin+"' . self::base()
            . 'index.php?fc=module&module=fullmetrixconnector&controller=relay",{method:"POST",credentials:"same-origin",keepalive:true,'
            . 'headers:{"Content-Type":"text/plain"},body:t})}};x.send()}catch(e){}})();</script>';
    }

    private static function origin()
    {
        $configured = Configuration::get(FullmetrixJournal::key('tracker_origin'));
        if (is_string($configured) && preg_match('#^https?://[A-Za-z0-9.-]+(:[0-9]{1,5})?$#', $configured)) {
            return $configured;
        }
        $apiBase = Configuration::get(FullmetrixJournal::key('api_base'));
        if (is_string($apiBase) && preg_match('#^(https?://[A-Za-z0-9.-]+(:[0-9]{1,5})?)(/|$)#', $apiBase, $matches)) {
            return $matches[1];
        }

        return self::$defaultOrigin;
    }

    private static function base()
    {
        $base = defined('__PS_BASE_URI__') ? __PS_BASE_URI__ : '/';
        if (is_string($base) && preg_match('#^/([A-Za-z0-9._~-]+/)*$#', $base)) {
            return $base;
        }

        return '/';
    }

    private static function escape($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
