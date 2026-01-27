<?php
namespace ShipPHP\Core;

/**
 * ProfileManager - Manages global profiles stored in ~/.shipphp/profiles.json
 *
 * Handles profile creation, retrieval, updating, and deletion
 * Provides secure storage with proper file permissions
 */
class ProfileManager
{
    private static $profilePath = null;
    private static $initialized = false;

    /**
     * Initialize ProfileManager - creates ~/.shipphp directory if needed
     */
    public static function init()
    {
        if (self::$initialized) {
            return;
        }

        // Get user home directory (cross-platform)
        $home = self::getHomeDirectory();
        $dir = $home . DIRECTORY_SEPARATOR . '.shipphp';

        // Create directory if it doesn't exist
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0700, true)) {
                throw new \Exception("Failed to create profile directory: {$dir}");
            }
        }

        self::$profilePath = $dir . DIRECTORY_SEPARATOR . 'profiles.json';
        self::$initialized = true;

        // Create empty profiles file if it doesn't exist
        if (!file_exists(self::$profilePath)) {
            self::save([
                'profiles' => [],
                'default' => null,
                'version' => '2.0.0'
            ]);
        }
    }

    /**
     * Get user home directory (cross-platform)
     */
    private static function getHomeDirectory()
    {
        // Windows
        if (PHP_OS_FAMILY === 'Windows') {
            return getenv('USERPROFILE') ?: getenv('HOMEDRIVE') . getenv('HOMEPATH');
        }

        // Unix/Linux/Mac
        return getenv('HOME') ?: posix_getpwuid(posix_getuid())['dir'];
    }

    /**
     * Add or update a profile
     *
     * @param string $name Profile name (e.g., "myblog-com-a3f9")
     * @param array $data Profile data (projectName, domain, serverUrl, token, etc.)
     */
    public static function add($name, $data)
    {
        self::init();

        $profiles = self::load();

        // Add profile data
        $profiles['profiles'][$name] = array_merge([
            'created' => date('Y-m-d H:i:s'),
            'updated' => date('Y-m-d H:i:s')
        ], $data);

        // Set as default if first profile
        if (count($profiles['profiles']) === 1) {
            $profiles['default'] = $name;
        }

        self::save($profiles);
    }

    /**
     * Get a specific profile by name
     *
     * @param string $name Profile name
     * @return array|null Profile data or null if not found
     */
    public static function get($name)
    {
        self::init();

        $profiles = self::load();
        return $profiles['profiles'][$name] ?? null;
    }

    /**
     * Get all profiles
     *
     * @return array All profiles
     */
    public static function all()
    {
        self::init();

        $profiles = self::load();
        return $profiles['profiles'] ?? [];
    }

    /**
     * Check if a profile exists
     *
     * @param string $name Profile name
     * @return bool
     */
    public static function exists($name)
    {
        self::init();

        $profiles = self::load();
        return isset($profiles['profiles'][$name]);
    }

    /**
     * Remove a profile
     *
     * @param string $name Profile name
     * @return bool Success
     */
    public static function remove($name)
    {
        self::init();

        $profiles = self::load();

        if (!isset($profiles['profiles'][$name])) {
            return false;
        }

        unset($profiles['profiles'][$name]);

        // Clear default if removing default profile
        if ($profiles['default'] === $name) {
            $profiles['default'] = null;

            // Set new default to first profile if any exist
            if (!empty($profiles['profiles'])) {
                $profiles['default'] = array_key_first($profiles['profiles']);
            }
        }

        self::save($profiles);
        return true;
    }

    /**
     * Set default profile
     *
     * @param string $name Profile name
     */
    public static function setDefault($name)
    {
        self::init();

        $profiles = self::load();

        if (!isset($profiles['profiles'][$name])) {
            throw new \Exception("Profile '{$name}' not found");
        }

        $profiles['default'] = $name;
        self::save($profiles);
    }

    /**
     * Get default profile name
     *
     * @return string|null Default profile name or null
     */
    public static function getDefault()
    {
        self::init();

        $profiles = self::load();
        return $profiles['default'] ?? null;
    }

    /**
     * Get default profile data
     *
     * @return array|null Default profile data or null
     */
    public static function getDefaultProfile()
    {
        $defaultName = self::getDefault();

        if (!$defaultName) {
            return null;
        }

        return self::get($defaultName);
    }

    /**
     * Update profile token
     *
     * @param string $name Profile name
     * @param string $newToken New token
     */
    public static function updateToken($name, $newToken)
    {
        self::init();

        $profiles = self::load();

        if (!isset($profiles['profiles'][$name])) {
            throw new \Exception("Profile '{$name}' not found");
        }

        $profiles['profiles'][$name]['token'] = $newToken;
        $profiles['profiles'][$name]['updated'] = date('Y-m-d H:i:s');

        self::save($profiles);
    }

    /**
     * Update profile data
     *
     * @param string $name Profile name
     * @param array $data Data to update
     */
    public static function update($name, $data)
    {
        self::init();

        $profiles = self::load();

        if (!isset($profiles['profiles'][$name])) {
            throw new \Exception("Profile '{$name}' not found");
        }

        $profiles['profiles'][$name] = array_merge(
            $profiles['profiles'][$name],
            $data,
            ['updated' => date('Y-m-d H:i:s')]
        );

        self::save($profiles);
    }

    /**
     * Get profile by server URL
     *
     * @param string $url Server URL
     * @return array|null Profile data or null
     */
    public static function getByUrl($url)
    {
        self::init();

        $profiles = self::all();

        foreach ($profiles as $name => $profile) {
            if (($profile['serverUrl'] ?? '') === $url) {
                return array_merge($profile, ['name' => $name]);
            }
        }

        return null;
    }

    /**
     * Load profiles from disk
     *
     * @return array Profiles data
     */
    private static function load()
    {
        if (!file_exists(self::$profilePath)) {
            return [
                'profiles' => [],
                'default' => null,
                'version' => '2.0.0'
            ];
        }

        $content = file_get_contents(self::$profilePath);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Failed to parse profiles.json: " . json_last_error_msg());
        }

        return $data;
    }

    /**
     * Save profiles to disk with secure permissions
     *
     * @param array $data Profiles data
     */
    private static function save($data)
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (file_put_contents(self::$profilePath, $json) === false) {
            throw new \Exception("Failed to save profiles to: " . self::$profilePath);
        }

        // Set secure permissions (owner read/write only)
        chmod(self::$profilePath, 0600);
    }

    /**
     * Get profile path for debugging
     *
     * @return string Profile file path
     */
    public static function getProfilePath()
    {
        self::init();
        return self::$profilePath;
    }

    /**
     * Generate unique profile ID from domain
     *
     * @param string $domain Domain name (e.g., "myblog.com")
     * @return string Profile ID (e.g., "myblog-com-a3f9")
     */
    public static function generateProfileId($domain)
    {
        // Clean domain for use in profile name
        $cleanDomain = str_replace(['.', '/', ':', '@'], '-', $domain);
        $cleanDomain = preg_replace('/[^a-z0-9\-]/i', '', $cleanDomain);
        $cleanDomain = strtolower($cleanDomain);

        // Generate 4-character unique ID
        $uniqueId = substr(bin2hex(random_bytes(2)), 0, 4);

        // Ensure uniqueness
        $profileId = "{$cleanDomain}-{$uniqueId}";

        // If somehow already exists, regenerate
        $attempts = 0;
        while (self::exists($profileId) && $attempts < 10) {
            $uniqueId = substr(bin2hex(random_bytes(2)), 0, 4);
            $profileId = "{$cleanDomain}-{$uniqueId}";
            $attempts++;
        }

        return $profileId;
    }
}

