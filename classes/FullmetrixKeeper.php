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

class FullmetrixKeeper
{
    private static $defaultDays = 390;
    private static $maxDays = 390;
    private static $cookieName = '_kvo_v';
    private static $legacyCookieName = 'fm_vid';
    private static $maxBodyBytes = 64;
    private static $uuidV4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public static function days()
    {
        if (!self::filled('registered') || !self::filled('code') || !self::filled('secret')) {
            return 0;
        }
        $flags = self::stored('flags');
        if (is_array($flags)) {
            foreach (['module', 'keeper', 'tracker'] as $name) {
                if (isset($flags[$name]) && $flags[$name] === false) {
                    return 0;
                }
            }
        } else {
            $legacy = self::stored('plugin_config');
            if (is_array($legacy) && isset($legacy['trackerEnabled']) && $legacy['trackerEnabled'] === false) {
                return 0;
            }
        }
        $settings = self::stored('tracker_keeper');
        if (!is_array($settings)) {
            return self::$defaultDays;
        }
        if (isset($settings['enabled']) && $settings['enabled'] === false) {
            return 0;
        }
        if (isset($settings['days']) && is_int($settings['days']) && $settings['days'] >= 1 && $settings['days'] <= self::$maxDays) {
            return $settings['days'];
        }

        return self::$defaultDays;
    }

    public static function maxBodyBytes()
    {
        return self::$maxBodyBytes;
    }

    public static function decide($days, $method, $cookieHeader, $cookies, $body, $fetchSite)
    {
        $days = (int) $days;
        if ($days < 1) {
            return ['status' => 404, 'value' => null, 'legacy' => false];
        }
        if (strtoupper((string) $method) !== 'POST') {
            return ['status' => 405, 'value' => null, 'legacy' => false];
        }
        if ($fetchSite !== null && $fetchSite !== 'same-origin') {
            return ['status' => 403, 'value' => null, 'legacy' => false];
        }
        $fresh = self::occurrences($cookieHeader, self::$cookieName);
        $legacy = self::occurrences($cookieHeader, self::$legacyCookieName);
        if ($fresh > 1 || $legacy > 1 || ($fresh === 0 && $legacy === 0) || !is_array($cookies)) {
            return ['status' => 400, 'value' => null, 'legacy' => false];
        }
        $name = $fresh === 1 ? self::$cookieName : self::$legacyCookieName;
        if (!isset($cookies[$name])) {
            return ['status' => 400, 'value' => null, 'legacy' => false];
        }
        $value = $cookies[$name];
        if (!is_string($value) || preg_match(self::$uuidV4, $value) !== 1) {
            return ['status' => 400, 'value' => null, 'legacy' => false];
        }
        if (!is_string($body) || !hash_equals($value, $body)) {
            return ['status' => 400, 'value' => null, 'legacy' => false];
        }
        $legacyValue = $legacy === 1 && isset($cookies[self::$legacyCookieName]) ? $cookies[self::$legacyCookieName] : null;

        return ['status' => 204, 'value' => $value, 'legacy' => is_string($legacyValue) && $legacyValue === $value];
    }

    public static function headers($decision, $days, $secure)
    {
        $headers = [
            'Cache-Control: no-store, private',
            'CDN-Cache-Control: no-store',
            'X-Robots-Tag: noindex',
            'Content-Length: 0',
        ];
        if ($decision['status'] !== 204 || !is_string($decision['value'])) {
            return $headers;
        }
        $headers[] = 'X-Fm-Keeper: 1';
        $headers[] = 'X-Kvo-K: 1';
        $suffix = '=' . $decision['value'] . '; Max-Age=' . ((int) $days * 86400) . '; Path=/; SameSite=Lax' . ($secure ? '; Secure' : '');
        $headers[] = 'Set-Cookie: ' . self::$cookieName . $suffix;
        if (!empty($decision['legacy'])) {
            $headers[] = 'Set-Cookie: ' . self::$legacyCookieName . $suffix;
        }

        return $headers;
    }

    private static function filled($name)
    {
        $value = Configuration::get(FullmetrixJournal::key($name));

        return !empty($value);
    }

    private static function stored($name)
    {
        $raw = Configuration::get(FullmetrixJournal::key($name));
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    private static function occurrences($cookieHeader, $cookieName)
    {
        if (!is_string($cookieHeader) || $cookieHeader === '') {
            return 0;
        }
        $count = 0;
        foreach (explode(';', $cookieHeader) as $pair) {
            $position = strpos($pair, '=');
            $name = trim($position === false ? $pair : substr($pair, 0, $position));
            if ($name === $cookieName) {
                ++$count;
            }
        }

        return $count;
    }
}
