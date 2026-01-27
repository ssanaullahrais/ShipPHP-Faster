<?php

namespace ShipPHP\Commands;

use ShipPHP\Core\ProfileManager;

/**
 * Server Command
 * Generate server files
 */
class ServerCommand extends BaseCommand
{
    public function execute($options)
    {
        $action = $options['args'][0] ?? 'generate';

        if ($action === 'generate') {
            $this->generate($options);
        } else {
            $this->showHelp();
        }
    }

    /**
     * Generate server file and create profile
     */
    private function generate($options)
    {
        $this->header("Generate ShipPHP Server File");

        $profileName = $options[1] ?? null;

        if (!$profileName) {
            $profileName = $this->output->ask("Profile name (e.g., 'myblog-prod')");
        }

        if (empty($profileName)) {
            $this->output->error("Profile name is required");
            return;
        }

        // Check if profile already exists
        ProfileManager::init();
        if (ProfileManager::exists($profileName)) {
            $this->output->error("Profile '{$profileName}' already exists");
            $this->output->writeln("Use '" . $this->cmd("profile remove {$profileName}") . "' to remove it first.");
            return;
        }

        $this->output->writeln();
        $this->output->writeln("Let's configure your server...");
        $this->output->writeln();

        // Get project info
        $projectName = $this->output->ask("Project name (e.g., 'My Blog')");
        $domain = $this->output->ask("Domain (e.g., 'myblog.com')");

        if (empty($domain)) {
            $this->output->error("Domain is required");
            return;
        }

        // Construct server URL
        if (!preg_match('#^https?://#i', $domain)) {
            $domain = 'https://' . $domain;
        }
        $serverUrl = rtrim($domain, '/') . '/shipphp-server.php';

        // Collect server configuration
        $serverConfig = $this->collectServerConfiguration();

        // Generate token
        $this->output->writeln();
        $this->output->write("Generating secure token... ");
        $token = bin2hex(random_bytes(32));
        $this->output->success("Done");

        // Generate server file
        $this->output->write("Generating shipphp-server.php... ");
        $serverFilePath = $this->generateServerFile($token, $serverConfig);
        $this->output->success("Done");

        // Extract clean domain
        $parsed = parse_url($serverUrl);
        $cleanDomain = $parsed['host'] ?? str_replace(['http://', 'https://'], '', $domain);

        // Create profile
        $this->output->write("Creating profile '{$profileName}'... ");
        try {
            ProfileManager::add($profileName, [
                'projectName' => $projectName,
                'domain' => $cleanDomain,
                'serverUrl' => $serverUrl,
                'token' => $token,
                'description' => "Generated on " . date('Y-m-d H:i:s')
            ]);
            $this->output->success("Done");
        } catch (\Exception $e) {
            $this->output->error("Failed: " . $e->getMessage());
            return;
        }

        // Show success message
        $this->output->writeln();
        $this->output->success("Server file generated successfully!");
        $this->output->writeln();

        $this->output->box(
            "✓ Generated shipphp-server.php\n" .
            "✓ Created profile: {$profileName}\n" .
            "✓ Token: " . substr($token, 0, 8) . "..." . substr($token, -8),
            'green'
        );

        // Show next steps
        $this->output->writeln($this->output->colorize("📋 FILE LOCATION:", 'cyan'));
        $this->output->writeln("   " . realpath($serverFilePath));
        $this->output->writeln();

        $this->output->writeln($this->output->colorize("🚀 NEXT STEPS:", 'yellow'));
        $this->output->writeln();
        $this->output->writeln("1. " . $this->output->colorize("Upload shipphp-server.php", 'cyan'));
        $this->output->writeln("   Upload to: " . $this->output->colorize($serverUrl, 'white'));
        $this->output->writeln();
        $this->output->writeln("2. " . $this->output->colorize("Use this profile", 'cyan'));
        $this->output->writeln("   In your project: " . $this->output->colorize($this->cmd('login'), 'green'));
        $this->output->writeln("   Select '{$profileName}' from the list");
        $this->output->writeln();
        $this->output->writeln("3. " . $this->output->colorize("Start deploying!", 'cyan'));
        $this->output->writeln("   " . $this->output->colorize($this->cmd('push'), 'green'));
        $this->output->writeln();
    }

    /**
     * Collect server configuration interactively
     */
    private function collectServerConfiguration()
    {
        $config = [];

        // Max file size
        $this->output->writeln($this->output->colorize("Max File Size:", 'yellow'));
        $this->output->writeln("Maximum size for uploaded files.");
        $this->output->writeln("Recommended: 100MB for most projects");
        $maxFileSizeMB = $this->output->ask("Max file size in MB", "100");
        $maxFileSizeMB = (int)$maxFileSizeMB;
        if ($maxFileSizeMB < 1 || $maxFileSizeMB > 2048) {
            $this->output->warning("Invalid size. Using default: 100MB");
            $maxFileSizeMB = 100;
        }
        $config['maxFileSize'] = $maxFileSizeMB * 1024 * 1024;
        $this->output->writeln();

        // IP Whitelist
        $this->output->writeln($this->output->colorize("IP Whitelist (Optional):", 'yellow'));
        $this->output->writeln("Restrict access to specific IP addresses.");
        $useWhitelist = $this->output->confirm("Enable IP whitelist?", false);
        $ipWhitelist = [];
        if ($useWhitelist) {
            $this->output->writeln("Enter IP addresses (Examples: 192.168.1.1 or 10.0.0.0/8)");
            $ipInput = $this->output->ask("IP address (or press Enter to finish)", "");
            while (!empty($ipInput)) {
                if ($this->validateIP($ipInput)) {
                    $ipWhitelist[] = $ipInput;
                    $this->output->success("✓ Added: {$ipInput}");
                } else {
                    $this->output->warning("Invalid IP format: {$ipInput}");
                }
                $ipInput = $this->output->ask("Next IP (or press Enter to finish)", "");
            }
        }
        $config['ipWhitelist'] = $ipWhitelist;
        $this->output->writeln();

        // Rate limit
        $this->output->writeln($this->output->colorize("Rate Limit:", 'yellow'));
        $this->output->writeln("Maximum API requests per minute.");
        $rateLimit = $this->output->ask("Rate limit (requests per minute)", "120");
        $rateLimit = (int)$rateLimit;
        if ($rateLimit < 10 || $rateLimit > 1000) {
            $this->output->warning("Invalid rate. Using default: 120");
            $rateLimit = 120;
        }
        $config['rateLimit'] = $rateLimit;
        $this->output->writeln();

        // Logging
        $this->output->writeln($this->output->colorize("Server Logging:", 'yellow'));
        $this->output->writeln("Logs all API requests for debugging.");
        $enableLogging = $this->output->confirm("Enable logging?", true);
        $config['enableLogging'] = $enableLogging;
        $this->output->writeln();

        return $config;
    }

    /**
     * Validate IP address or CIDR
     */
    private function validateIP($ip)
    {
        // Check CIDR notation
        if (strpos($ip, '/') !== false) {
            list($addr, $mask) = explode('/', $ip);
            $mask = (int)$mask;
            return filter_var($addr, FILTER_VALIDATE_IP) && $mask >= 0 && $mask <= 32;
        }

        // Regular IP
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Generate server file
     */
    private function generateServerFile($token, $serverConfig = [])
    {
        $serverFilePath = getcwd() . DIRECTORY_SEPARATOR . 'shipphp-server.php';
        $templatePath = SHIPPHP_ROOT . '/shipphp-server.php';

        if (!file_exists($templatePath)) {
            throw new \Exception("Server template file not found at: {$templatePath}");
        }

        // Read template
        $content = file_get_contents($templatePath);

        // Replace token
        $content = str_replace(
            "define('SHIPPHP_TOKEN', 'CHANGE_THIS_TO_YOUR_64_CHAR_TOKEN_FROM_shipphp_json');",
            "define('SHIPPHP_TOKEN', '{$token}');",
            $content
        );

        // Apply server configuration
        if (!empty($serverConfig)) {
            // Max file size
            if (isset($serverConfig['maxFileSize'])) {
                $content = preg_replace(
                    "/define\('MAX_FILE_SIZE', \d+\);/",
                    "define('MAX_FILE_SIZE', {$serverConfig['maxFileSize']});",
                    $content
                );
            }

            // Rate limit
            if (isset($serverConfig['rateLimit'])) {
                $content = preg_replace(
                    "/define\('RATE_LIMIT', \d+\);/",
                    "define('RATE_LIMIT', {$serverConfig['rateLimit']});",
                    $content
                );
            }

            // Logging
            if (isset($serverConfig['enableLogging'])) {
                $loggingValue = $serverConfig['enableLogging'] ? 'true' : 'false';
                $content = preg_replace(
                    "/define\('ENABLE_LOGGING', (true|false)\);/",
                    "define('ENABLE_LOGGING', {$loggingValue});",
                    $content
                );
            }

            // IP Whitelist
            if (isset($serverConfig['ipWhitelist']) && !empty($serverConfig['ipWhitelist'])) {
                $ipsArray = "'" . implode("', '", $serverConfig['ipWhitelist']) . "'";
                $content = preg_replace(
                    "/define\('IP_WHITELIST', \[\]\);/",
                    "define('IP_WHITELIST', [{$ipsArray}]);",
                    $content
                );
            }
        }

        // Write to current directory
        if (file_put_contents($serverFilePath, $content) === false) {
            throw new \Exception("Failed to write server file to: {$serverFilePath}");
        }

        return $serverFilePath;
    }

    /**
     * Show help
     */
    private function showHelp()
    {
        $this->header("Server File Generation");

        $this->output->writeln("Usage:");
        $this->output->writeln("  " . $this->output->colorize($this->cmd('server generate <profile-name>'), 'green'));
        $this->output->writeln();

        $this->output->writeln("Description:");
        $this->output->writeln("  Generate a shipphp-server.php file and create a global profile.");
        $this->output->writeln("  This allows you to prepare server files without initializing a project.");
        $this->output->writeln();

        $this->output->writeln("Examples:");
        $this->output->writeln("  " . $this->cmd('server generate myblog-prod') . "    # Generate server file for myblog");
        $this->output->writeln("  " . $this->cmd('server generate client-staging') . " # Generate for staging environment");
        $this->output->writeln();
    }
}
