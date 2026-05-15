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

class FullmetrixLogger
{
    const MAX_ENTRIES = 30;
    const MAX_MESSAGE_LEN = 256;
    const MAX_CONTEXT_LEN = 64;
    const MAX_DETAIL_STRING_LEN = 256;
    const MAX_TOTAL_BYTES = 524288;
    const DEDUP_WINDOW_SECONDS = 60;
    const CONFIG_KEY = 'FULLMETRIX_LOGS';

    public static function log($type, $message, $details = [])
    {
        try {
            $type = self::clip((string) $type, self::MAX_CONTEXT_LEN);
            $message = self::clip((string) $message, self::MAX_MESSAGE_LEN);
            $details = self::sanitizeDetails($details);

            $raw = Configuration::get(self::CONFIG_KEY);
            if (is_string($raw) && strlen($raw) > self::MAX_TOTAL_BYTES) {
                $logs = [];
            } else {
                $logs = !empty($raw) ? json_decode($raw, true) : [];
                if (!is_array($logs)) {
                    $logs = [];
                }
            }

            $now = time();
            $dedupKey = $type . '|' . $message . '|' . self::detailsFingerprint($details);

            if (!empty($logs) && is_array($logs[0])) {
                $first = $logs[0];
                $firstKey = (isset($first['type']) ? $first['type'] : '')
                    . '|' . (isset($first['message']) ? $first['message'] : '')
                    . '|' . (isset($first['_fp']) ? $first['_fp'] : '');
                if ($firstKey === $dedupKey
                    && isset($first['time'])
                    && ($now - (int) $first['time']) < self::DEDUP_WINDOW_SECONDS) {
                    $logs[0]['count'] = (isset($first['count']) ? (int) $first['count'] : 1) + 1;
                    $logs[0]['time'] = $now;
                    Configuration::updateValue(self::CONFIG_KEY, json_encode($logs, JSON_UNESCAPED_UNICODE));

                    return;
                }
            }

            array_unshift($logs, [
                'type' => $type,
                'message' => $message,
                'details' => $details,
                'time' => $now,
                'count' => 1,
                '_fp' => self::detailsFingerprint($details),
            ]);

            if (count($logs) > self::MAX_ENTRIES) {
                $logs = array_slice($logs, 0, self::MAX_ENTRIES);
            }

            $encoded = json_encode($logs, JSON_UNESCAPED_UNICODE);
            if (!is_string($encoded) || strlen($encoded) > self::MAX_TOTAL_BYTES) {
                $logs = array_slice($logs, 0, max(1, (int) (self::MAX_ENTRIES / 2)));
                $encoded = json_encode($logs, JSON_UNESCAPED_UNICODE);
            }

            Configuration::updateValue(self::CONFIG_KEY, $encoded);
        } catch (\Throwable $inner) {
            // Logger itself must never bubble
        }
    }

    public static function getLogs()
    {
        $raw = Configuration::get(self::CONFIG_KEY);
        if (empty($raw)) {
            return [];
        }
        $logs = json_decode($raw, true);

        return is_array($logs) ? $logs : [];
    }

    public static function clear()
    {
        Configuration::updateValue(self::CONFIG_KEY, '[]');
    }

    public static function logException($context, $e)
    {
        try {
            $details = [
                'context' => self::clip((string) $context, self::MAX_CONTEXT_LEN),
                'error' => self::clip((string) $e->getMessage(), self::MAX_MESSAGE_LEN),
                'file' => self::clip(basename($e->getFile()) . ':' . $e->getLine(), self::MAX_DETAIL_STRING_LEN),
            ];
            self::log('sync_error', 'hook_exception', $details);
        } catch (\Throwable $inner) {
            // Logger itself must never bubble
        }
    }

    private static function clip($value, $max)
    {
        $value = (string) $value;
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, (int) $max);
        }

        return substr($value, 0, (int) $max);
    }

    private static function sanitizeDetails($details)
    {
        if (!is_array($details)) {
            return [];
        }
        $clean = [];
        foreach ($details as $key => $val) {
            $cleanKey = self::clip((string) $key, self::MAX_CONTEXT_LEN);
            if (is_string($val)) {
                $clean[$cleanKey] = self::clip($val, self::MAX_DETAIL_STRING_LEN);
            } elseif (is_int($val) || is_float($val) || is_bool($val) || $val === null) {
                $clean[$cleanKey] = $val;
            } elseif (is_array($val)) {
                $clean[$cleanKey] = self::sanitizeDetails($val);
            } else {
                $clean[$cleanKey] = self::clip((string) $val, self::MAX_DETAIL_STRING_LEN);
            }
        }

        return $clean;
    }

    private static function detailsFingerprint($details)
    {
        $json = json_encode($details, JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return '';
        }

        return substr(md5($json), 0, 12);
    }
}
