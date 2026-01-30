<?php

namespace ShipPHP\Commands;

use ShipPHP\Core\Config;
use ShipPHP\Core\State;
use ShipPHP\Core\ProfileManager;
use ShipPHP\Core\ProjectPaths;

/**
 * Init Command
 * Initialize ShipPHP in current directory
 */
class InitCommand extends BaseCommand
{
    public function execute($options)
    {
        $this->header("Initialize ShipPHP Faster");

        $force = $this->hasFlag($options, 'force');

        // Check if already initialized
        $config = new Config();

        if ($config->exists() && !$force) {
            $this->output->error("ShipPHP is already initialized in this directory");
            $this->output->writeln("Use --force to reinitialize\n");
            return;
        }

        $this->output->writeln("Welcome to ShipPHP Faster! Let's set up your deployment.\n", 'green');

        // Get project name first
        $projectName = $this->output->ask(
            "Project name (e.g., 'My Blog', 'Client Website')",
            basename(WORKING_DIR)
        );

        if (empty($projectName)) {
            $projectName = basename(WORKING_DIR);
        }

        $this->output->writeln();

        // Get domain/URL where project will run
        $domain = $this->output->ask(
            "Where will your project run? (enter your domain or full URL)",
            "https://example.com"
        );

        if (empty($domain)) {
            $this->output->error("Domain/URL is required");
            return;
        }

        // Auto-construct full server URL
        $domain = rtrim($domain, '/');
        // If user entered just domain without protocol, add https://
        if (!preg_match('#^https?://#i', $domain)) {
            $domain = 'https://' . $domain;
        }
        // Construct full URL
        $serverUrl = $domain . '/shipphp-server.php';

        $this->output->writeln();
        $this->output->info("Server URL will be: {$serverUrl}");
        $this->output->writeln();

        // Collect server configuration
        $serverConfig = $this->collectServerConfiguration();

        // Initialize config
        try {
            $result = $config->init($serverUrl, $force, $serverConfig);
            $token = $result['token'];

            // Generate .gitignore
            $this->output->write("Generating .gitignore... ");
            $gitignoreCreated = $config->generateGitignore();
            if ($gitignoreCreated) {
                $this->output->success("Done");
            } else {
                $this->output->info("Already exists (skipped)");
            }

            // Generate .ignore file (ShipPHP-specific)
            $this->output->write("Generating .ignore... ");
            $ignoreCreated = $config->generateIgnoreFile();
            if ($ignoreCreated) {
                $this->output->success("Done");
            } else {
                $this->output->info("Already exists (skipped)");
            }

            // Generate server file with token pre-configured
            $this->output->write("Generating shipphp-server.php... ");
            $this->generateServerFile($token, $serverConfig);
            $this->output->success("Done");

            // CRITICAL: Do initial file scan to establish baseline
            $this->output->write("Scanning project files... ");
            $state = new State();
            $ignore = $config->get('ignore', []);
            $state->updateFromScan(WORKING_DIR, $ignore);
            $state->save();
            $this->output->success("Done");

            $this->output->writeln();
            $this->output->success("ShipPHP initialized successfully!");
            $this->output->writeln();

            // AUTO-CREATE GLOBAL PROFILE
            try {
                ProfileManager::init();

                // Extract clean domain for profile ID
                $parsedUrl = parse_url($serverUrl);
                $cleanDomain = $parsedUrl['host'] ?? str_replace(['http://', 'https://'], '', $domain);

                // Generate unique profile ID
                $profileId = ProfileManager::generateProfileId($cleanDomain);

                // Create profile data
                $profileData = [
                    'projectName' => $projectName,
                    'domain' => $cleanDomain,
                    'serverUrl' => $serverUrl,
                    'token' => $token,
                    'profileId' => $profileId
                ];

                // Add profile
                ProfileManager::add($profileId, $profileData);

                // Update local config with profileId
                $configPath = ProjectPaths::configFile();
                if (file_exists($configPath)) {
                    $currentConfig = json_decode(file_get_contents($configPath), true);
                    $currentConfig['projectName'] = $projectName;
                    $currentConfig['profileId'] = $profileId;
                    file_put_contents(
                        $configPath,
                        json_encode($currentConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                    );
                }

                $this->output->success("Created global profile: {$profileId}");
                $this->output->writeln();

            } catch (\Exception $e) {
                $this->output->warning("Note: Could not create global profile: " . $e->getMessage());
                $this->output->writeln();
            }

            // Show next steps
            $this->showNextSteps($token, $serverUrl, $serverConfig, $projectName, $profileId ?? null);

        } catch (\Exception $e) {
            $this->output->error($e->getMessage());
        }
    }

    /**
     * Collect server configuration interactively
     */
    private function collectServerConfiguration()
    {
        $config = [];

        $this->output->writeln($this->output->colorize("Server Configuration", 'cyan'));
        $this->output->writeln("Let's configure your server settings.\n");

        // Max file size
        $this->output->writeln($this->output->colorize("Max File Size:", 'yellow'));
        $this->output->writeln("Maximum size for uploaded files.");
        $this->output->writeln("Recommended: 100MB for most projects, 500MB for large media files");
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
        $this->output->writeln("Restrict access to specific IP addresses for extra security.");
        $this->output->writeln("Leave empty to allow all IPs (you can configure this later)");
        $useWhitelist = $this->output->confirm("Enable IP whitelist?", false);
        $ipWhitelist = [];
        if ($useWhitelist) {
            $this->output->writeln("Enter IP addresses (one per line, empty line to finish):");
            $this->output->writeln("Examples: 192.168.1.1 or 10.0.0.0/8");
            $ipInput = $this->output->ask("IP address (or press Enter to finish)", "");
            while (!empty($ipInput)) {
                // Basic IP validation
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
        $this->output->writeln("Maximum API requests per minute to prevent abuse.");
        $this->output->writeln("Recommended: 120 requests/minute for normal usage");
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
        $this->output->writeln("Logs all API requests to .shipphp-server.log for debugging/audit.");
        $this->output->writeln("Recommended: Yes - helps troubleshoot issues and monitor activity");
        $enableLogging = $this->output->confirm("Enable detailed logging?", true);
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
     * Generate server file with token pre-configured
     */
    private function generateServerFile($token, $serverConfig = [])
    {
        $serverFilePath = ProjectPaths::serverFile();
        $templatePath = SHIPPHP_ROOT . '/templates/shipphp-server.template.php';

        if (!file_exists($templatePath)) {
            throw new \Exception("Server template file not found");
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

            // Enable backups
            if (isset($serverConfig['enableBackups'])) {
                $backupValue = $serverConfig['enableBackups'] ? 'true' : 'false';
                $content = preg_replace(
                    "/define\('ENABLE_BACKUPS', (true|false)\);/",
                    "define('ENABLE_BACKUPS', {$backupValue});",
                    $content
                );
            }

            // Backup retention
            if (isset($serverConfig['backupRetention'])) {
                $content = preg_replace(
                    "/define\('BACKUP_RETENTION', \d+\);/",
                    "define('BACKUP_RETENTION', {$serverConfig['backupRetention']});",
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

        $serverDir = dirname($serverFilePath);
        if (!is_dir($serverDir)) {
            if (!mkdir($serverDir, 0755, true)) {
                throw new \Exception("Failed to create configuration directory");
            }
        }

        // Write to project directory
        if (file_put_contents($serverFilePath, $content) === false) {
            throw new \Exception("Failed to generate server file");
        }

        return $serverFilePath;
    }

    /**
     * Show next steps
     */
    private function showNextSteps($token, $serverUrl, $serverConfig = [], $projectName = null, $profileId = null)
    {
        $configDir = ProjectPaths::configDir();
        $relativeConfigDir = str_replace(WORKING_DIR . '/', '', $configDir);
        if ($relativeConfigDir === '') {
            $relativeConfigDir = '.';
        }

        $createdItems = "✓ Created .gitignore\n" .
            "✓ Created {$relativeConfigDir}/.ignore\n" .
            "✓ Created {$relativeConfigDir}/shipphp.json\n" .
            "✓ Created {$relativeConfigDir}/.shipphp/ directory\n" .
            "✓ Generated secure token\n" .
            "✓ Generated shipphp-server.php (fully configured!)";

        if ($profileId) {
            $createdItems .= "\n✓ Created global profile: {$profileId}";
        }

        $this->output->box($createdItems, 'green');

        $this->output->writeln($this->output->colorize("📋 WHAT WE CREATED:", 'cyan'));
        $this->output->writeln("   • .gitignore (ignore patterns for Git)");
        $this->output->writeln("   • {$relativeConfigDir}/.ignore (ShipPHP-specific ignore patterns)");
        $this->output->writeln("   • {$relativeConfigDir}/shipphp.json (your config)");
        $this->output->writeln("   • {$relativeConfigDir}/shipphp-server.php (ready to upload!)");
        $this->output->writeln("   • {$relativeConfigDir}/.shipphp/ (tracking directory)");

        if ($profileId) {
            $this->output->writeln("   • Global profile: {$profileId} (saved in ~/.shipphp/)");
        }

        $this->output->writeln();

        // Show server configuration summary
        if (!empty($serverConfig)) {
            $this->output->writeln($this->output->colorize("⚙️  SERVER CONFIGURATION:", 'cyan'));

            if (isset($serverConfig['maxFileSize'])) {
                $sizeMB = round($serverConfig['maxFileSize'] / 1024 / 1024);
                $this->output->writeln("   • Max file size: {$sizeMB}MB");
            }

            if (isset($serverConfig['ipWhitelist']) && !empty($serverConfig['ipWhitelist'])) {
                $ipCount = count($serverConfig['ipWhitelist']);
                $this->output->writeln("   • IP whitelist: {$ipCount} IP(s) configured");
            } else {
                $this->output->writeln("   • IP whitelist: Disabled (all IPs allowed)");
            }

            if (isset($serverConfig['rateLimit'])) {
                $this->output->writeln("   • Rate limit: {$serverConfig['rateLimit']} req/min");
            }

            if (isset($serverConfig['enableLogging'])) {
                $status = $serverConfig['enableLogging'] ? 'Enabled' : 'Disabled';
                $this->output->writeln("   • Server logging: {$status}");
            }

            $this->output->writeln();
        }

        // Show local backup info
        $this->output->writeln($this->output->colorize("💾 LOCAL BACKUPS:", 'cyan'));
        $this->output->writeln("   • Automatic backups: Enabled (before every push and pull)");
        $this->output->writeln("   • Backup retention: 100 backups (auto-cleanup)");
        $this->output->writeln("   • Location: {$relativeConfigDir}/.shipphp/backups/");
        $this->output->writeln();

        // Only show bootstrap tip if using full path
        if (strpos(SHIPPHP_COMMAND, '/') !== false && strpos(SHIPPHP_COMMAND, 'shipphp.php') !== false) {
            $this->output->writeln($this->output->colorize("💡 OPTIONAL: Easier Command Usage", 'cyan'));
            $this->output->writeln("   Create a bootstrap file for shorter commands:");
            $this->output->writeln("   " . $this->output->colorize($this->cmd('bootstrap ./ship'), 'green'));
            $this->output->writeln("   Then run: " . $this->output->colorize("php ship status", 'green') . " instead of " . $this->output->colorize($this->cmd('status'), 'white'));
            $this->output->writeln("   Or on Unix: " . $this->output->colorize("./ship status", 'green'));
            $this->output->writeln();
        }

        $this->output->writeln($this->output->colorize("🚀 NEXT STEPS:", 'yellow'));
        $this->output->writeln();

        $this->output->writeln("1. " . $this->output->colorize("Upload shipphp-server.php", 'cyan'));
        $this->output->writeln("   📁 File is here: " . $this->output->colorize(ProjectPaths::serverFile(), 'white'));
        $this->output->writeln("   🌐 Upload to: " . $this->output->colorize($serverUrl, 'white'));
        $this->output->writeln("   💡 All settings are already configured - just upload!");
        $this->output->writeln();

        if ($profileId) {
            $this->output->writeln("2. " . $this->output->colorize("Login to your profile", 'cyan'));
            $this->output->writeln("   Run: " . $this->output->colorize($this->cmd('login'), 'green'));
            $this->output->writeln("   📝 Select '{$profileId}' from the list");
            $this->output->writeln();

            $this->output->writeln("3. " . $this->output->colorize("Test connection", 'cyan'));
            $this->output->writeln("   Run: " . $this->output->colorize($this->cmd('status'), 'green'));
            $this->output->writeln();

            $this->output->writeln("4. " . $this->output->colorize("Deploy!", 'cyan'));
            $this->output->writeln("   Run: " . $this->output->colorize($this->cmd('push'), 'green'));
            $this->output->writeln("   ShipPHP automatically detects all changes - no staging required!");
            $this->output->writeln();
        } else {
            $this->output->writeln("2. " . $this->output->colorize("Test connection", 'cyan'));
            $this->output->writeln("   Run: " . $this->output->colorize($this->cmd('status'), 'green'));
            $this->output->writeln();

            $this->output->writeln("3. " . $this->output->colorize("Deploy!", 'cyan'));
            $this->output->writeln("   Run: " . $this->output->colorize($this->cmd('push'), 'green'));
            $this->output->writeln("   ShipPHP automatically detects all changes - no staging required!");
            $this->output->writeln();
        }

        $this->output->box(
            "💡 SUPER EASY!\n" .
            "   1. Answer configuration questions\n" .
            "   2. Upload the generated shipphp-server.php file\n" .
            "   3. Run: " . $this->cmd('status') . "\n" .
            "   4. Deploy: " . $this->cmd('push') . "\n\n" .
            "✨ ShipPHP automatically tracks all changes!\n" .
            "📝 Patterns in .gitignore are automatically respected",
            'yellow'
        );
    }
}
