<?php

namespace ShipPHP\Commands;

use ShipPHP\Core\Config;
use ShipPHP\Core\State;
use ShipPHP\Core\ApiClient;
use ShipPHP\Core\Backup;
use ShipPHP\Core\ProfileManager;
use ShipPHP\Core\ProjectPaths;
use ShipPHP\Core\PlanManager;
use ShipPHP\Helpers\Output;

/**
 * Base Command Class
 * All commands extend this class
 */
abstract class BaseCommand
{
    protected $output;
    protected $config;
    protected $state;
    protected $api;
    protected $backup;

    public function __construct(Output $output)
    {
        $this->output = $output;
    }

    /**
     * Get command format for user instructions
     */
    protected function cmd($command = '')
    {
        $base = SHIPPHP_COMMAND;
        return empty($command) ? $base : "{$base} {$command}";
    }

    /**
     * Execute command - must be implemented by child classes
     */
    abstract public function execute($options);

    /**
     * Initialize configuration
     */
    protected function initConfig()
    {
        $this->config = new Config();

        if (!$this->config->exists() && !($this instanceof InitCommand)) {
            throw new \Exception(
                "ShipPHP is not initialized in this directory.\n" .
                "Run '" . $this->cmd('init') . "' to get started."
            );
        }
    }

    /**
     * Initialize state
     */
    protected function initState()
    {
        $this->state = new State();
    }

    /**
     * Initialize API client
     */
    protected function initApi()
    {
        $this->initConfig();

        $env = $this->config->getCurrentEnv();

        if (empty($env['serverUrl']) || empty($env['token'])) {
            throw new \Exception(
                "Server configuration incomplete.\n" .
                "Please update your shipphp.json with server URL and token."
            );
        }

        $this->api = new ApiClient($env['serverUrl'], $env['token']);
    }

    /**
     * Initialize backup manager
     */
    protected function initBackup()
    {
        $this->initConfig();
        $this->backup = new Backup($this->config, $this->output);
    }

    /**
     * Check if option is set
     */
    protected function hasFlag($options, $flag)
    {
        return isset($options['flags'][$flag]);
    }

    /**
     * Get parameter value
     */
    protected function getParam($options, $param, $default = null)
    {
        return $options['params'][$param] ?? $default;
    }

    /**
     * Get argument by index
     */
    protected function getArg($options, $index, $default = null)
    {
        return $options['args'][$index] ?? $default;
    }

    /**
     * Test server connection
     */
    protected function testConnection()
    {
        try {
            $this->api->test();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Show header
     */
    protected function header($title)
    {
        $this->output->writeln();
        $this->output->writeln("╔════════════════════════════════════════════════════════════╗", 'cyan');
        $this->output->writeln("║  " . str_pad($title, 57) . "║", 'cyan');
        $this->output->writeln("╚════════════════════════════════════════════════════════════╝", 'cyan');
        $this->output->writeln();
    }

    /**
     * Initialize plan manager
     */
    protected function initPlan()
    {
        $this->initConfig();
        return new PlanManager();
    }

    /**
     * Check for dangerous paths
     */
    protected function isDangerousPath($path)
    {
        $normalized = trim(str_replace('\\', '/', $path));
        $normalized = trim($normalized, '/');

        if ($normalized === '' || $normalized === '.' || $normalized === '..') {
            return true;
        }

        $dangerousRoots = ['public', 'config', 'vendor', 'storage', '.env'];
        return in_array($normalized, $dangerousRoots, true);
    }

    /**
     * Require --force for dangerous paths
     */
    protected function requireForceForDangerous(array $paths, $force, $actionLabel)
    {
        $dangerous = array_filter($paths, function ($path) {
            return $this->isDangerousPath($path);
        });

        if (empty($dangerous)) {
            return true;
        }

        $this->output->warning("Dangerous target(s) detected:");
        foreach ($dangerous as $path) {
            $this->output->writeln("  - {$path}");
        }

        if (!$force) {
            $this->output->error("Refusing to {$actionLabel} dangerous paths without --force.");
            return false;
        }

        return true;
    }

    /**
     * Show upload/download progress with time estimates
     */
    protected function showProgress($completed, $failed, $total, $startTime, $final = false)
    {
        $elapsed = microtime(true) - $startTime;
        $processed = $completed + $failed;
        $remaining = $total - $processed;
        $percentage = $total > 0 ? round(($processed / $total) * 100, 1) : 0;

        // Calculate speed and ETA
        $speed = $processed > 0 ? $processed / $elapsed : 0;
        $eta = $speed > 0 && $remaining > 0 ? $remaining / $speed : 0;

        // Format time
        $elapsedFormatted = $this->formatTime($elapsed);
        $etaFormatted = $this->formatTime($eta);

        // Build progress line
        $progressBar = $this->buildProgressBar($percentage);

        $line = sprintf(
            "\r%s %d%% | %d/%d files | ✓ %d",
            $progressBar,
            (int)$percentage,
            $processed,
            $total,
            $completed
        );

        if ($failed > 0) {
            $line .= $this->output->colorize(" ✗ {$failed}", 'red');
        }

        $line .= sprintf(
            " | Time: %s | Remaining: %s | Speed: %.1f/s",
            $elapsedFormatted,
            $remaining > 0 ? $etaFormatted : '0s',
            $speed
        );

        // Clear line and show progress
        $this->output->write($line);

        if ($final) {
            $this->output->writeln();
        }
    }

    /**
     * Build a simple progress bar
     */
    protected function buildProgressBar($percentage, $width = 20)
    {
        $filled = (int)round(($percentage / 100) * $width);
        $empty = $width - $filled;

        $bar = '[' .
               $this->output->colorize(str_repeat('=', $filled), 'green') .
               str_repeat('-', $empty) .
               ']';

        return $bar;
    }

    /**
     * Format time in human-readable format
     */
    protected function formatTime($seconds)
    {
        if ($seconds < 1) {
            return '0s';
        }

        // Convert to integer first to avoid float deprecation warnings
        $totalSeconds = (int)round($seconds);
        $hours = intdiv($totalSeconds, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $secs = $totalSeconds % 60;

        $parts = [];
        if ($hours > 0) {
            $parts[] = "{$hours}h";
        }
        if ($minutes > 0) {
            $parts[] = "{$minutes}m";
        }
        if ($secs > 0 || empty($parts)) {
            $parts[] = "{$secs}s";
        }

        return implode(' ', $parts);
    }

    /**
     * Get current profile information (from link or local config)
     *
     * @return array|null Profile data or null
     */
    protected function getCurrentProfile()
    {
        // Check for profile link first
        $linkFile = ProjectPaths::linkFile();
        if (file_exists($linkFile)) {
            $profileName = trim(file_get_contents($linkFile));

            ProfileManager::init();
            $profile = ProfileManager::get($profileName);

            if ($profile) {
                $profile['_profileName'] = $profileName;
                $profile['_source'] = 'link';
                return $profile;
            }
        }

        // Fall back to local config
        $configFile = ProjectPaths::configFile();
        if (file_exists($configFile)) {
            $config = json_decode(file_get_contents($configFile), true);
            if ($config) {
                $config['_source'] = 'local';
                return $config;
            }
        }

        return null;
    }

    /**
     * Show status bar with project information (like Claude Code)
     *
     * Shows: Project Name | Domain | Profile ID | Token Status
     */
    protected function showStatusBar()
    {
        $profile = $this->getCurrentProfile();

        if (!$profile) {
            // Not initialized - show warning
            $this->output->writeln();
            $this->output->writeln("╔══════════════════════════════════════════════════════════════════════╗", 'yellow');
            $this->output->writeln("║ ⚠ Not Initialized                                                    ║", 'yellow');
            $this->output->writeln("╚══════════════════════════════════════════════════════════════════════╝", 'yellow');
            $this->output->writeln();
            return;
        }

        // Extract info
        $projectName = $profile['projectName'] ?? 'Unknown Project';
        $domain = $profile['domain'] ?? $this->extractDomain($profile['serverUrl'] ?? '');
        $profileId = $profile['profileId'] ?? ($profile['_profileName'] ?? 'local');
        $token = $profile['token'] ?? '';
        $tokenPreview = !empty($token) ? substr($token, 0, 6) . '...' . substr($token, -3) : 'none';

        // Truncate if too long
        if (strlen($projectName) > 25) {
            $projectName = substr($projectName, 0, 22) . '...';
        }
        if (strlen($domain) > 30) {
            $domain = substr($domain, 0, 27) . '...';
        }
        if (strlen($profileId) > 20) {
            $profileId = substr($profileId, 0, 17) . '...';
        }

        // Build status bar
        $statusLine = sprintf(
            "║ 🚀 %s  │  %s  │  %s  │  ●%s  ║",
            str_pad($projectName, 25),
            str_pad($domain, 30),
            str_pad($profileId, 20),
            str_pad($tokenPreview, 10)
        );

        $this->output->writeln();
        $this->output->writeln("╔══════════════════════════════════════════════════════════════════════════════════════════════════════════╗", 'green');
        $this->output->writeln($statusLine, 'green');
        $this->output->writeln("╚══════════════════════════════════════════════════════════════════════════════════════════════════════════╝", 'green');
        $this->output->writeln();
    }

    /**
     * Normalize and validate a relative path (no absolute paths or traversal).
     */
    protected function normalizeRelativePath($path)
    {
        $path = str_replace('\\', '/', $path);
        $path = trim($path);

        if ($path === '') {
            throw new \Exception('Path cannot be empty');
        }

        if (preg_match('#^([A-Za-z]:)?/#', $path)) {
            throw new \Exception('Absolute paths are not allowed');
        }

        if (strpos($path, '..') !== false) {
            throw new \Exception('Path traversal is not allowed');
        }

        return ltrim($path, '/');
    }

    /**
     * Extract domain from URL
     */
    private function extractDomain($url)
    {
        if (empty($url)) {
            return 'unknown';
        }

        $parsed = parse_url($url);
        return $parsed['host'] ?? str_replace(['http://', 'https://'], '', $url);
    }
}
