<?php

namespace ShipPHP\Commands;

use ShipPHP\Core\ProfileManager;
use ShipPHP\Core\ApiClient;

/**
 * Profile Command
 * Manage global profiles
 */
class ProfileCommand extends BaseCommand
{
    public function execute($options)
    {
        $action = $options['args'][0] ?? 'list';

        switch ($action) {
            case 'add':
                $this->add($options);
                break;
            case 'list':
                $this->listProfiles();
                break;
            case 'remove':
            case 'delete':
                $this->remove($options);
                break;
            case 'use':
            case 'default':
                $this->setDefault($options);
                break;
            case 'show':
                $this->show($options);
                break;
            default:
                $this->showHelp();
        }
    }

    /**
     * Add a new profile
     */
    private function add($options)
    {
        $this->header("Add Profile");

        ProfileManager::init();

        // Get profile name
        $name = $options['args'][1] ?? null;
        if (!$name) {
            $name = $this->output->ask("Profile name (e.g., 'myblog-prod')");
        }

        if (empty($name)) {
            $this->output->error("Profile name is required");
            return;
        }

        // Check if already exists
        if (ProfileManager::exists($name)) {
            $this->output->error("Profile '{$name}' already exists");
            $this->output->writeln("Use '" . $this->cmd("profile remove {$name}") . "' to remove it first.");
            return;
        }

        // Get server URL
        $serverUrl = $this->output->ask("Server URL (e.g., https://example.com/shipphp-server.php)");

        if (empty($serverUrl)) {
            $this->output->error("Server URL is required");
            return;
        }

        // Ensure URL has protocol
        if (!preg_match('#^https?://#i', $serverUrl)) {
            $serverUrl = 'https://' . $serverUrl;
        }

        // Get token
        $token = $this->output->ask("Token (64 characters)");

        if (empty($token)) {
            $this->output->error("Token is required");
            return;
        }

        // Optional: Get project name and domain
        $projectName = $this->output->ask("Project name (optional)", '');
        $description = $this->output->ask("Description (optional)", '');

        // Extract domain
        $parsed = parse_url($serverUrl);
        $domain = $parsed['host'] ?? str_replace(['http://', 'https://'], '', $serverUrl);

        // Create profile data
        $profileData = [
            'serverUrl' => $serverUrl,
            'token' => $token,
            'domain' => $domain
        ];

        if (!empty($projectName)) {
            $profileData['projectName'] = $projectName;
        }

        if (!empty($description)) {
            $profileData['description'] = $description;
        }

        // Add profile
        try {
            ProfileManager::add($name, $profileData);
            $this->output->success("Profile '{$name}' added successfully!");

            // Test connection
            $this->output->writeln();
            $this->output->write("Testing connection... ");

            if ($this->testConnectionToProfile($profileData)) {
                $this->output->success("Connected!");
            } else {
                $this->output->warning("Could not connect");
                $this->output->writeln("(Profile saved, but connection test failed)");
            }

        } catch (\Exception $e) {
            $this->output->error("Failed to add profile: " . $e->getMessage());
        }
    }

    /**
     * List all profiles
     */
    private function listProfiles()
    {
        $this->header("ShipPHP Profiles");

        ProfileManager::init();
        $profiles = ProfileManager::all();

        if (empty($profiles)) {
            $this->output->info("No profiles found.");
            $this->output->writeln();
            $this->output->writeln("Create one with: " . $this->output->colorize($this->cmd('profile add'), 'green'));
            return;
        }

        $default = ProfileManager::getDefault();

        $headers = ['Profile', 'Project Name', 'Domain', 'Created', 'Default'];
        $rows = [];

        foreach ($profiles as $name => $profile) {
            $rows[] = [
                $name,
                $profile['projectName'] ?? 'N/A',
                $profile['domain'] ?? $this->extractDomain($profile['serverUrl']),
                $this->formatDate($profile['created'] ?? 'Unknown'),
                ($name === $default) ? '✓' : ''
            ];
        }

        $this->output->table($headers, $rows);

        $this->output->writeln("Total profiles: " . count($profiles));
        $this->output->writeln();
        $this->output->writeln("Profile storage: " . ProfileManager::getProfilePath());
        $this->output->writeln();
    }

    /**
     * Remove a profile
     */
    private function remove($options)
    {
        $name = $options['args'][1] ?? null;

        if (!$name) {
            $this->output->error("Profile name is required");
            $this->output->writeln("Usage: " . $this->cmd('profile remove <name>'));
            return;
        }

        ProfileManager::init();

        if (!ProfileManager::exists($name)) {
            $this->output->error("Profile '{$name}' not found");
            return;
        }

        $this->header("Remove Profile");

        $profile = ProfileManager::get($name);
        $this->output->writeln("Profile: {$name}");
        $this->output->writeln("Domain: " . ($profile['domain'] ?? 'Unknown'));
        $this->output->writeln();

        if (!$this->output->confirm("Are you sure you want to remove this profile?", false)) {
            $this->output->writeln("Cancelled.");
            return;
        }

        try {
            ProfileManager::remove($name);
            $this->output->success("Profile '{$name}' removed successfully!");
        } catch (\Exception $e) {
            $this->output->error("Failed to remove profile: " . $e->getMessage());
        }
    }

    /**
     * Set default profile
     */
    private function setDefault($options)
    {
        $name = $options['args'][1] ?? null;

        if (!$name) {
            $this->output->error("Profile name is required");
            $this->output->writeln("Usage: " . $this->cmd('profile use <name>'));
            return;
        }

        ProfileManager::init();

        if (!ProfileManager::exists($name)) {
            $this->output->error("Profile '{$name}' not found");
            return;
        }

        try {
            ProfileManager::setDefault($name);
            $this->output->success("Default profile set to '{$name}'");
        } catch (\Exception $e) {
            $this->output->error("Failed to set default profile: " . $e->getMessage());
        }
    }

    /**
     * Show profile details
     */
    private function show($options)
    {
        $name = $options['args'][1] ?? null;

        if (!$name) {
            $this->output->error("Profile name is required");
            $this->output->writeln("Usage: " . $this->cmd('profile show <name>'));
            return;
        }

        ProfileManager::init();

        if (!ProfileManager::exists($name)) {
            $this->output->error("Profile '{$name}' not found");
            return;
        }

        $profile = ProfileManager::get($name);
        $default = ProfileManager::getDefault();

        $this->header("Profile: {$name}");

        $this->output->writeln("Project Name:  " . ($profile['projectName'] ?? 'N/A'));
        $this->output->writeln("Domain:        " . ($profile['domain'] ?? 'N/A'));
        $this->output->writeln("Server URL:    " . ($profile['serverUrl'] ?? 'N/A'));
        $this->output->writeln("Token:         " . substr($profile['token'], 0, 8) . "..." . substr($profile['token'], -8));
        $this->output->writeln("Created:       " . ($profile['created'] ?? 'Unknown'));
        $this->output->writeln("Updated:       " . ($profile['updated'] ?? 'N/A'));
        $this->output->writeln("Default:       " . (($name === $default) ? 'Yes' : 'No'));

        if (!empty($profile['description'])) {
            $this->output->writeln("Description:   " . $profile['description']);
        }

        $this->output->writeln();

        // Test connection
        $this->output->write("Testing connection... ");
        if ($this->testConnectionToProfile($profile)) {
            $this->output->success("Connected!");
        } else {
            $this->output->error("Failed");
        }
    }

    /**
     * Test connection to server (overrides parent)
     */
    protected function testConnectionToProfile($profile)
    {
        try {
            $client = new ApiClient(
                $profile['serverUrl'],
                $profile['token']
            );

            $response = $client->request('test');
            return $response['success'] ?? false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Extract domain from URL
     */
    private function extractDomain($url)
    {
        $parsed = parse_url($url);
        return $parsed['host'] ?? str_replace(['http://', 'https://'], '', $url);
    }

    /**
     * Format date for display
     */
    private function formatDate($dateString)
    {
        if ($dateString === 'Unknown' || empty($dateString)) {
            return 'Unknown';
        }

        try {
            $date = new \DateTime($dateString);
            return $date->format('Y-m-d H:i');
        } catch (\Exception $e) {
            return 'Unknown';
        }
    }

    /**
     * Show help
     */
    private function showHelp()
    {
        $this->header("Profile Management");

        $this->output->writeln("Usage:");
        $this->output->writeln("  " . $this->output->colorize($this->cmd('profile list'), 'green') . "              List all profiles");
        $this->output->writeln("  " . $this->output->colorize($this->cmd('profile add'), 'green') . "               Add new profile");
        $this->output->writeln("  " . $this->output->colorize($this->cmd('profile show <name>'), 'green') . "      Show profile details");
        $this->output->writeln("  " . $this->output->colorize($this->cmd('profile use <name>'), 'green') . "       Set default profile");
        $this->output->writeln("  " . $this->output->colorize($this->cmd('profile remove <name>'), 'green') . "    Remove profile");
        $this->output->writeln();

        $this->output->writeln("Examples:");
        $this->output->writeln("  " . $this->cmd('profile list') . "                    # List all profiles");
        $this->output->writeln("  " . $this->cmd('profile add myblog-prod') . "        # Add new profile interactively");
        $this->output->writeln("  " . $this->cmd('profile show myblog-prod') . "       # Show profile details");
        $this->output->writeln("  " . $this->cmd('profile use myblog-prod') . "        # Set as default");
        $this->output->writeln("  " . $this->cmd('profile remove myblog-prod') . "     # Remove profile");
        $this->output->writeln();
    }
}
