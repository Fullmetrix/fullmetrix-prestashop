<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class FullmetrixLogger
{
    const MAX_ENTRIES = 100;
    const CONFIG_KEY = 'FULLMETRIX_LOGS';

    /**
     * @param string $type    registered|disconnected|sync_start|sync_complete|sync_error|webhook
     * @param string $message
     * @param array  $details
     */
    public static function log($type, $message, $details = [])
    {
        $raw = Configuration::get(self::CONFIG_KEY);
        $logs = !empty($raw) ? json_decode($raw, true) : [];
        if (!is_array($logs)) {
            $logs = [];
        }

        array_unshift($logs, [
            'type'    => $type,
            'message' => $message,
            'details' => $details,
            'time'    => time(),
        ]);

        if (count($logs) > self::MAX_ENTRIES) {
            $logs = array_slice($logs, 0, self::MAX_ENTRIES);
        }

        Configuration::updateValue(self::CONFIG_KEY, json_encode($logs, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array
     */
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
}
