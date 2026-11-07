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
require_once dirname(__FILE__) . '/FullmetrixGuard.php';
require_once dirname(__FILE__) . '/FullmetrixJournalStore.php';
require_once dirname(__FILE__) . '/FullmetrixChanges.php';

class FullmetrixTrackerBundle
{
    private static $interval = 600;
    private static $maxPause = 21600;
    private static $unwritablePause = 3600;
    private static $timeoutMs = 3000;
    private static $maxResponseBytes = 2097152;
    private static $maxScriptBytes = 1048576;
    private static $scriptRetention = 604800;

    public static function directory()
    {
        return _PS_MODULE_DIR_ . 'fullmetrixconnector/views/js/fm';
    }

    public static function refreshQuietly()
    {
        set_error_handler(function () {
            return true;
        });
        try {
            $status = static::refresh(time());
        } catch (Exception $e) {
            $status = 'error';
        } catch (Throwable $e) {
            $status = 'error';
        }
        restore_error_handler();

        return $status;
    }

    public static function refresh($now)
    {
        $now = (int) $now;
        if (!FullmetrixGuard::isConnected()) {
            return 'inactive';
        }
        if (!FullmetrixChanges::flagEnabled('module') || !FullmetrixChanges::flagEnabled('tracker')) {
            return 'off';
        }
        $origin = FullmetrixGuard::trackerOrigin();
        if (!FullmetrixGuard::isOwnOrigin($origin)) {
            self::forget();

            return 'origin';
        }
        $state = self::state();
        $record = self::record();
        $upgraded = is_array($record) && (!isset($record['mv']) || $record['mv'] !== FullmetrixConnector::pluginVersion());
        if (!$upgraded && $now < $state['next']) {
            return 'wait';
        }
        $directory = self::directory();
        $local = FullmetrixChanges::flagEnabled('trackerLocal');
        $writable = is_dir($directory) && is_writable($directory);
        $code = (string) Configuration::get(FullmetrixJournal::key('code'));
        $response = static::request($origin . '/api/tracker/bundle?key=' . rawurlencode($code));
        $status = self::install($response, $origin, $code, $record, $now, $local, $writable);
        if ($status === 'ok' || $status === 'local_off') {
            self::saveState($now + self::$interval, 0, '');
        } elseif ($status === 'not_writable') {
            self::forget();
            self::saveState($now + self::$unwritablePause, $state['fails'], 'not_writable');
        } else {
            if ($status === 'http_404') {
                self::forget();
            }
            $fails = $state['fails'] + 1;
            self::saveState($now + min(self::$maxPause, self::$interval * (int) pow(2, min($fails, 10))), $fails, $status);
        }

        return $status;
    }

    public static function request($url)
    {
        $result = ['status' => 0, 'body' => null];
        if (!function_exists('curl_init')) {
            return $result;
        }
        $ch = curl_init($url);
        if (!$ch) {
            return $result;
        }
        $body = '';
        $limit = self::$maxResponseBytes;
        $overflow = false;
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Fullmetrix-Plugin-Version: ' . FullmetrixConnector::pluginVersion()],
            CURLOPT_NOSIGNAL => true,
            CURLOPT_CONNECTTIMEOUT_MS => self::$timeoutMs,
            CURLOPT_TIMEOUT_MS => self::$timeoutMs,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$body, &$overflow, $limit) {
                if (strlen($body) + strlen($chunk) > $limit) {
                    $overflow = true;

                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        $done = @curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
        if ($done === false || $overflow || $status <= 0) {
            return $result;
        }
        $result['status'] = $status;
        if ($status === 200) {
            $result['body'] = $body;
        }

        return $result;
    }

    public static function purge()
    {
        $directory = self::directory();
        if (is_dir($directory)) {
            foreach (self::entries($directory) as $name) {
                if (self::isScriptName($name) || self::isTemporaryName($name)) {
                    @unlink($directory . '/' . $name);
                }
            }
        }
        Configuration::deleteByName(FullmetrixJournal::key('tracker_local'));
        Configuration::deleteByName(FullmetrixJournal::key('tracker_bundle'));
        Configuration::deleteByName(FullmetrixJournal::key('tracker_keeper'));
        Configuration::deleteByName(FullmetrixJournal::key('tracker_tag'));
    }

    private static function install($response, $origin, $code, $record, $now, $local, $writable)
    {
        if (!is_array($response) || !isset($response['status'])) {
            return 'network';
        }
        $status = (int) $response['status'];
        if ($status === 0) {
            return 'network';
        }
        if ($status !== 200) {
            return 'http_' . $status;
        }
        $bundle = is_string($response['body']) ? json_decode($response['body'], true) : null;
        if (is_array($bundle) && isset($bundle['origin']) && $bundle['origin'] === $origin && FullmetrixGuard::isOwnOrigin($origin)) {
            self::saveSettings($bundle);
            if (!$local) {
                return 'local_off';
            }
            if (!$writable) {
                return 'not_writable';
            }
        }
        if (!is_array($bundle) || !isset($bundle['version'], $bundle['sha256'], $bundle['content'], $bundle['origin'])
            || !is_string($bundle['version']) || !preg_match('/^[a-z0-9]{6,64}$/', $bundle['version'])
            || !is_string($bundle['sha256']) || !preg_match('/^[a-f0-9]{64}$/', $bundle['sha256'])
            || !is_string($bundle['content']) || $bundle['content'] === '' || strlen($bundle['content']) > self::$maxScriptBytes
            || !is_string($bundle['origin'])) {
            return 'invalid_bundle';
        }
        if ($bundle['origin'] !== $origin || !FullmetrixGuard::isOwnOrigin($bundle['origin'])) {
            return 'foreign_origin';
        }
        if (!hash_equals($bundle['sha256'], hash('sha256', $bundle['content']))) {
            return 'sha256_mismatch';
        }
        $directory = self::directory();
        $file = 'fm-' . $bundle['version'] . '.js';
        if (!self::write($directory, $file, $bundle['content'], $bundle['sha256'])) {
            return 'write_failed';
        }
        $previous = '';
        if (is_array($record) && isset($record['file']) && is_string($record['file']) && self::isScriptName($record['file'])) {
            $previous = $record['file'] !== $file ? $record['file'] : (isset($record['prev']) && is_string($record['prev']) ? $record['prev'] : '');
        }
        if ($previous !== '' && (!self::isScriptName($previous) || !is_file($directory . '/' . $previous))) {
            $previous = '';
        }
        self::saveRecord([
            'file' => $file,
            'prev' => $previous,
            'mv' => FullmetrixConnector::pluginVersion(),
            'k' => $code,
            'o' => $origin,
            'at' => min($now, time()),
        ]);
        self::prune($directory, [$file, $previous]);

        return 'ok';
    }

    private static function saveSettings(array $bundle)
    {
        if (array_key_exists('keeper', $bundle)) {
            $keeper = FullmetrixGuard::normalizeKeeper($bundle['keeper']);
            if ($keeper !== null) {
                FullmetrixJournalStore::setConfig(FullmetrixJournal::key('tracker_keeper'), json_encode($keeper));
            }
        }
        if (array_key_exists('tagAttributes', $bundle)) {
            FullmetrixJournalStore::setConfig(
                FullmetrixJournal::key('tracker_tag'),
                json_encode((object) FullmetrixGuard::normalizeTagAttributes($bundle['tagAttributes']))
            );
        }
    }

    private static function write($directory, $file, $content, $sha256)
    {
        $target = $directory . '/' . $file;
        if (is_file($target) && hash_equals($sha256, (string) @hash_file('sha256', $target))) {
            return true;
        }
        $temporary = $directory . '/tmp-' . bin2hex(self::randomBytes(8)) . '.part';
        if (@file_put_contents($temporary, $content, LOCK_EX) !== strlen($content)) {
            @unlink($temporary);

            return false;
        }
        @chmod($temporary, 0644);
        if (!@rename($temporary, $target)) {
            @unlink($temporary);

            return false;
        }
        clearstatcache();

        return is_file($target) && hash_equals($sha256, (string) @hash_file('sha256', $target));
    }

    private static function prune($directory, array $keep)
    {
        $now = time();
        foreach (self::entries($directory) as $name) {
            $path = $directory . '/' . $name;
            if (self::isScriptName($name) && !in_array($name, $keep, true) && self::olderThan($path, $now - self::$scriptRetention)) {
                @unlink($path);
            } elseif (self::isTemporaryName($name) && self::olderThan($path, $now - self::$interval)) {
                @unlink($path);
            }
        }
    }

    private static function olderThan($path, $limit)
    {
        $modified = @filemtime($path);

        return is_int($modified) && $modified < $limit;
    }

    private static function entries($directory)
    {
        $entries = @scandir($directory);

        return is_array($entries) ? $entries : [];
    }

    private static function isScriptName($name)
    {
        return is_string($name) && preg_match('/^fm-[a-z0-9]{6,64}\.js$/', $name) === 1;
    }

    private static function isTemporaryName($name)
    {
        return is_string($name) && preg_match('/^tmp-[a-f0-9]{16}\.part$/', $name) === 1;
    }

    private static function randomBytes($length)
    {
        if (function_exists('random_bytes')) {
            return random_bytes($length);
        }
        $bytes = '';
        for ($i = 0; $i < $length; ++$i) {
            $bytes .= chr(mt_rand(0, 255));
        }

        return $bytes;
    }

    private static function state()
    {
        $raw = FullmetrixJournalStore::config(FullmetrixJournal::key('tracker_bundle'));
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $state = ['next' => 0, 'fails' => 0];
        if (is_array($decoded)) {
            $state['next'] = isset($decoded['next']) && is_numeric($decoded['next']) ? max(0, (int) $decoded['next']) : 0;
            $state['fails'] = isset($decoded['fails']) && is_numeric($decoded['fails']) ? max(0, min(1000, (int) $decoded['fails'])) : 0;
        }

        return $state;
    }

    private static function saveState($next, $fails, $error)
    {
        FullmetrixJournalStore::setConfig(FullmetrixJournal::key('tracker_bundle'), json_encode([
            'next' => (int) $next,
            'fails' => (int) $fails,
            'err' => (string) $error,
        ]));
    }

    private static function record()
    {
        $raw = FullmetrixJournalStore::config(FullmetrixJournal::key('tracker_local'));
        $decoded = $raw !== '' ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    private static function saveRecord(array $record)
    {
        FullmetrixJournalStore::setConfig(FullmetrixJournal::key('tracker_local'), json_encode($record));
    }

    private static function forget()
    {
        Configuration::deleteByName(FullmetrixJournal::key('tracker_local'));
    }
}
