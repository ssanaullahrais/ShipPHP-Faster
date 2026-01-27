<?php

namespace ShipPHP\Commands;

use ShipPHP\Core\ProfileManager;

/**
 * Token Command
 * Manage authentication tokens
 */
class TokenCommand extends BaseCommand
{
    public function execute($options)
    {
        $action = $options['args'][0] ?? 'show';

        switch ($action) {
            case 'show':
                $this->show();
                break;
            case 'rotate':
                $this->rotate();
                break;
            default:
                $this->showHelp();
        }
    }

    /**
     * Show current token
     */
    private function show()
    {
        try {
            $this->initConfig();

            $this->header("Current Token");

            $token = $this->config->get('token');

            if (!$token) {
                $this->output->error("No token found in configuration");
                return;
            }

            $this->output->box(
                "Full Token:\n{$token}\n\n" .
                "Preview: " . substr($token, 0, 8) . "..." . substr($token, -8),
                'cyan'
            );

            $this->output->writeln();
            $this->output->warning("⚠ Keep this token secret! Do not share publicly.");

        } catch (\Exception $e) {
            $this->output->error($e->getMessage());
        }
    }

    /**
     * Rotate token (generate new one)
     */
    private function rotate()
    {
        try {
            $this->initConfig();

            $this->header("Token Rotation");

            $this->output->warning("Token rotation will:");
            $this->output->writeln("  1. Generate a new secure token");
            $this->output->writeln("  2. Update local shipphp.json");
            $this->output->writeln("  3. Update global profile (if linked)");
            $this->output->writeln("  4. Regenerate shipphp-server.php");
            $this->output->writeln("  5. Invalidate the old token");
            $this->output->writeln();
            $this->output->warning("⚠ You must upload the new server file for this to take effect!");
            $this->output->writeln();

            if (!$this->output->confirm("Continue with token rotation?", false)) {
                $this->output->writeln("Cancelled.");
                return;
            }

            // Generate new token
            $this->output->write("Generating new token... ");
            $newToken = bin2hex(random_bytes(32));
            $this->output->success("Done");

            // Get current config
            $configFile = WORKING_DIR . '/shipphp.json';
            $config = json_decode(file_get_contents($configFile), true);
            $oldToken = $config['token'] ?? '';

            // Update config with new token
            $config['token'] = $newToken;

            // Save updated config
            $this->output->write("Updating local config... ");
            file_put_contents(
                $configFile,
                json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
            $this->output->success("Done");

            // Update profile if linked
            $linkFile = WORKING_DIR . '/.shipphp/profile.link';
            if (file_exists($linkFile)) {
                $profileName = trim(file_get_contents($linkFile));
                $this->output->write("Updating global profile '{$profileName}'... ");

                try {
                    ProfileManager::init();
                    ProfileManager::updateToken($profileName, $newToken);
                    $this->output->success("Done");
                } catch (\Exception $e) {
                    $this->output->warning("Could not update profile: " . $e->getMessage());
                }
            }

            // Regenerate server file
            $this->output->write("Regenerating shipphp-server.php... ");
            $this->regenerateServerFile($newToken, $config);
            $this->output->success("Done");

            $this->output->writeln();
            $this->output->success("Token rotated successfully!");
            $this->output->writeln();

            // Show new token
            $this->output->box(
                "New Token:\n{$newToken}\n\n" .
                "Preview: " . substr($newToken, 0, 8) . "..." . substr($newToken, -8),
                'green'
            );

            $this->output->writeln();
            $this->output->box(
                "⚠ IMPORTANT: Upload the new shipphp-server.php file!\n\n" .
                "File location: " . basename(WORKING_DIR) . "/shipphp-server.php\n" .
                "Upload to: " . ($config['serverUrl'] ?? 'your server') . "\n\n" .
                "The old token will stop working after you upload the new file.",
                'yellow'
            );

        } catch (\Exception $e) {
            $this->output->error($e->getMessage());
        }
    }

    /**
     * Regenerate server file with new token
     */
    private function regenerateServerFile($token, $config)
    {
        $serverFilePath = WORKING_DIR . '/shipphp-server.php';
        $templatePath = SHIPPHP_ROOT . '/shipphp-server.php';

        if (!file_exists($templatePath)) {
            throw new \Exception("Server template file not found");
        }

        // Read template
        $content = file_get_contents($templatePath);

        // Replace token
        $content = preg_replace(
            "/define\('SHIPPHP_TOKEN', '[^']*'\);/",
            "define('SHIPPHP_TOKEN', '{$token}');",
            $content
        );

        // Apply other configurations if they exist
        $security = $config['security'] ?? [];

        if (isset($security['maxFileSize'])) {
            $content = preg_replace(
                "/define\('MAX_FILE_SIZE', \d+\);/",
                "define('MAX_FILE_SIZE', {$security['maxFileSize']});",
                $content
            );
        }

        if (isset($security['rateLimit'])) {
            $content = preg_replace(
                "/define\('RATE_LIMIT', \d+\);/",
                "define('RATE_LIMIT', {$security['rateLimit']});",
                $content
            );
        }

        if (isset($security['enableLogging'])) {
            $loggingValue = $security['enableLogging'] ? 'true' : 'false';
            $content = preg_replace(
                "/define\('ENABLE_LOGGING', (true|false)\);/",
                "define('ENABLE_LOGGING', {$loggingValue});",
                $content
            );
        }

        if (isset($security['ipWhitelist']) && !empty($security['ipWhitelist'])) {
            $ipsArray = "'" . implode("', '", $security['ipWhitelist']) . "'";
            $content = preg_replace(
                "/define\('IP_WHITELIST', \[.*?\]\);/s",
                "define('IP_WHITELIST', [{$ipsArray}]);",
                $content
            );
        }

        // Write to project directory
        if (file_put_contents($serverFilePath, $content) === false) {
            throw new \Exception("Failed to regenerate server file");
        }
    }

    /**
     * Show help
     */
    private function showHelp()
    {
        $this->header("Token Management");

        $this->output->writeln("Usage:");
        $this->output->writeln("  " . $this->output->colorize($this->cmd('token show'), 'green') . "     Show current token");
        $this->output->writeln("  " . $this->output->colorize($this->cmd('token rotate'), 'green') . "   Generate new token");
        $this->output->writeln();

        $this->output->writeln("Examples:");
        $this->output->writeln("  " . $this->cmd('token show') . "     # Display current token");
        $this->output->writeln("  " . $this->cmd('token rotate') . "   # Rotate to new token (upload required)");
        $this->output->writeln();
    }
}
