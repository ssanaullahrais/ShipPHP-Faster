<?php

namespace ShipPHP\Core;

/**
 * VersionChecker - Checks for new ShipPHP releases
 *
 * Queries GitHub API for latest release and caches result for 24 hours
 */
class VersionChecker
{
    private static $cacheFile = null;
    private static $cacheExpiry = 86400; // 24 hours

    /**
     * Initialize cache file path
     */
    private static function init()
    {
        if (self::$cacheFile !== null) {
            return;
        }

        // Store cache in ~/.shipphp/version-cache.json
        $home = self::getHomeDirectory();
        $dir = $home . DIRECTORY_SEPARATOR . '.shipphp';

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        self::$cacheFile = $dir . DIRECTORY_SEPARATOR . 'version-cache.json';
    }

    /**
     * Check if a new version is available
     *
     * @return array|null ['version' => '2.1.0', 'url' => 'https://...'] or null
     */
    public static function checkForUpdate()
    {
        try {
            $latestVersion = self::fetchLatestVersion();

            if ($latestVersion && version_compare($latestVersion['version'], SHIPPHP_VERSION, '>')) {
                // New version available
                return $latestVersion;
            } else {
                // No update available
                return null;
            }
        } catch (\Exception $e) {
            // Silently fail - don't bother user with update check errors
            return null;
        }
    }

    /**
     * Fetch latest version from GitHub releases API
     *
     * @return array|null ['version' => '2.1.0', 'url' => 'https://...']
     */
    private static function fetchLatestVersion()
    {
        $apiUrl = 'https://api.github.com/repos/ssanaullahrais/ShipPHP-Faster/releases/latest';

        // Use curl if available, fallback to file_get_contents
        if (function_exists('curl_init')) {
            return self::fetchWithCurl($apiUrl);
        } else {
            return self::fetchWithFileGetContents($apiUrl);
        }
    }

    /**
     * Fetch using cURL
     */
    private static function fetchWithCurl($url)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, 'ShipPHP/' . SHIPPHP_VERSION);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return null;
        }

        $data = json_decode($response, true);

        if (!$data || !isset($data['tag_name'])) {
            return null;
        }

        // Remove 'v' prefix if present (v2.1.0 -> 2.1.0)
        $version = ltrim($data['tag_name'], 'v');

        return [
            'version' => $version,
            'url' => $data['html_url'] ?? 'https://github.com/ssanaullahrais/ShipPHP-Faster/releases'
        ];
    }

    /**
     * Fetch using file_get_contents
     */
    private static function fetchWithFileGetContents($url)
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'User-Agent: ShipPHP/' . SHIPPHP_VERSION . "\r\n",
                'timeout' => 5
            ]
        ]);

        $response = @file_get_contents($url, false, $context);

        if (!$response) {
            return null;
        }

        $data = json_decode($response, true);

        if (!$data || !isset($data['tag_name'])) {
            return null;
        }

        $version = ltrim($data['tag_name'], 'v');

        return [
            'version' => $version,
            'url' => $data['html_url'] ?? 'https://github.com/ssanaullahrais/ShipPHP-Faster/releases'
        ];
    }

    /**
     * Get cached update check result
     */
    private static function getCached()
    {
        if (!file_exists(self::$cacheFile)) {
            return null;
        }

        $cache = json_decode(file_get_contents(self::$cacheFile), true);

        if (!$cache || !isset($cache['timestamp'])) {
            return null;
        }

        // Check if cache expired
        if (time() - $cache['timestamp'] > self::$cacheExpiry) {
            return null;
        }

        return $cache['update'] ?? null;
    }

    /**
     * Save to cache
     */
    private static function saveCache($updateData)
    {
        $cache = [
            'timestamp' => time(),
            'update' => $updateData
        ];

        @file_put_contents(self::$cacheFile, json_encode($cache, JSON_PRETTY_PRINT));
        @chmod(self::$cacheFile, 0600);
    }

    /**
     * Get user home directory (cross-platform)
     */
    private static function getHomeDirectory()
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return getenv('USERPROFILE') ?: getenv('HOMEDRIVE') . getenv('HOMEPATH');
        }

        return getenv('HOME') ?: posix_getpwuid(posix_getuid())['dir'];
    }

    /**
     * Determine installation method
     *
     * @return string 'global' or 'local'
     */
    public static function getInstallationType()
    {
        // Check if installed globally via Composer
        $composerBin = getenv('COMPOSER_BIN_DIR') ?: (getenv('HOME') ?: getenv('USERPROFILE')) . DIRECTORY_SEPARATOR . '.composer' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin';

        if (strpos(SHIPPHP_ROOT, $composerBin) !== false || strpos(SHIPPHP_ROOT, 'vendor/shipphp') !== false) {
            return 'global';
        }

        return 'local';
    }

    /**
     * Get update command based on installation type
     *
     * @return string Update command
     */
    public static function getUpdateCommand()
    {
        if (self::getInstallationType() === 'global') {
            return 'composer global update shipphp/faster';
        }

        // For local installations, provide direct instructions
        return 'cd shipphp && git pull && cd ..';
    }
}
