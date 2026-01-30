<?php

namespace ShipPHP\Core;

/**
 * ProjectPaths
 * Resolve ShipPHP config/state locations with legacy support.
 */
class ProjectPaths
{
    public static function configDir($workingDir = null)
    {
        $workingDir = $workingDir ?: WORKING_DIR;
        $configDir = $workingDir . '/shipphp-config';

        if (file_exists($configDir . '/shipphp.json') || is_dir($configDir)) {
            return $configDir;
        }

        if (file_exists($workingDir . '/shipphp.json') || is_dir($workingDir . '/.shipphp')) {
            return $workingDir;
        }

        return $configDir;
    }

    public static function configFile($workingDir = null)
    {
        return self::configDir($workingDir) . '/shipphp.json';
    }

    public static function ignoreFile($workingDir = null)
    {
        return self::configDir($workingDir) . '/.ignore';
    }

    public static function stateDir($workingDir = null)
    {
        return self::configDir($workingDir) . '/.shipphp';
    }

    public static function stateFile($workingDir = null)
    {
        return self::stateDir($workingDir) . '/state.json';
    }

    public static function serverFile($workingDir = null)
    {
        return self::configDir($workingDir) . '/shipphp-server.php';
    }

    public static function linkFile($workingDir = null)
    {
        return self::stateDir($workingDir) . '/profile.link';
    }
}
