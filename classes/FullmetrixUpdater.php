<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class FullmetrixUpdater
{
    const CACHE_TTL = 3600;

    public static function checkForUpdate()
    {
        $code = Configuration::get('FULLMETRIX_CONNECTION_CODE');
        if (empty($code)) {
            return null;
        }

        $cacheFile = _PS_CACHE_DIR_ . 'fullmetrix_update_check.json';

        if (file_exists($cacheFile)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['_cachedAt']) && (time() - $cached['_cachedAt']) < self::CACHE_TTL) {
                return $cached;
            }
        }

        $url = FullmetrixConnector::getApiBase() . '/check-update?'
            . http_build_query([
                'connectionCode' => $code,
                'currentVersion' => FullmetrixConnector::FULLMETRIX_VERSION,
                'platform' => 'prestashop',
            ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return null;
        }

        $data['_cachedAt'] = time();
        file_put_contents($cacheFile, json_encode($data));

        return $data;
    }

    public static function getUpdateNotice()
    {
        $data = self::checkForUpdate();

        if (!is_array($data) || empty($data['updateAvailable'])) {
            return '';
        }

        $version = htmlspecialchars($data['latestVersion'] ?? '', ENT_QUOTES, 'UTF-8');
        $downloadUrl = htmlspecialchars($data['downloadUrl'] ?? '', ENT_QUOTES, 'UTF-8');
        $changelog = htmlspecialchars($data['changelog'] ?? '', ENT_QUOTES, 'UTF-8');

        return '<div class="alert alert-warning">'
            . '<strong>Fullmetrix : mise &agrave; jour disponible (v' . $version . ')</strong><br>'
            . $changelog . '<br><br>'
            . '<a href="' . $downloadUrl . '" class="btn btn-primary" target="_blank">'
            . 'T&eacute;l&eacute;charger la mise &agrave; jour</a>'
            . '</div>';
    }
}
