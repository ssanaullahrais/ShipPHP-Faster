#!/usr/bin/env php
<?php
/**
 * ShipPHP Faster - Secure PHP Deployment Tool
 *
 * A Git-like deployment tool for PHP projects with backup support
 * Works with shared hosting, VPS, and any PHP environment
 *
 * @version 2.1.1
 * @author ShipPHP Team
 * @license MIT
 */

// Prevent running from web server
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from the command line.' . PHP_EOL);
}

// Set error reporting for CLI
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Define constants
define('SHIPPHP_VERSION', require __DIR__ . '/version.php'); // Single source of truth for CLI version output.
define('SHIPPHP_ROOT', __DIR__);
define('WORKING_DIR', getcwd());

// Detect how the script was invoked for dynamic help text
$scriptPath = $argv[0] ?? 'shipphp';
// Get relative path from working directory
$scriptPath = str_replace('\\', '/', $scriptPath);
$workingDir = str_replace('\\', '/', WORKING_DIR);
// Make it relative if possible
if (strpos($scriptPath, $workingDir) === 0) {
    $scriptPath = substr($scriptPath, strlen($workingDir) + 1);
}
// Determine command format
if (strpos($scriptPath, './') === 0 || strpos($scriptPath, '../') === 0 || strpos($scriptPath, '/') === 0) {
    // Unix-style: ./ship or ../ship
    define('SHIPPHP_COMMAND', $scriptPath);
} else {
    // Default to php prefix
    define('SHIPPHP_COMMAND', 'php ' . $scriptPath);
}

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'ShipPHP\\';
    $baseDir = SHIPPHP_ROOT . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Bootstrap the application
try {
    $app = new \ShipPHP\Core\Application();
    $app->run($argv);
} catch (\Exception $e) {
    echo "\n";
    echo "❌ Fatal Error: " . $e->getMessage() . "\n";
    echo "\n";
    exit(1);
}
