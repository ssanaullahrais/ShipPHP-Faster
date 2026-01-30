<?php

namespace ShipPHP\Commands;

use ShipPHP\Core\ProfileManager;
use ShipPHP\Core\ApiClient;
use ShipPHP\Core\ProjectPaths;

/**
 * Login Command
 * Connect current project to a global profile
 */
class LoginCommand extends BaseCommand
{
    public function execute($options)
    {
        // Check if project is initialized
        if (!$this->isProjectInitialized()) {
            $this->showNotInitializedError();
            return;
        }

        $this->header("ShipPHP Login");

        // Load all profiles
        ProfileManager::init();
        $profiles = ProfileManager::all();

        if (empty($profiles)) {
            $this->output->error("No profiles found.");
            $this->output->writeln();
            $this->output->writeln("Run " . $this->output->colorize($this->cmd('init'), 'green') . " to create your first profile.");
            return;
        }

        // Show profile table
        $this->showProfileTable($profiles);

        // User selection
        $profileCount = count($profiles);
        $choice = $this->output->ask("Select profile (1-{$profileCount}) or 'q' to quit");

        if ($choice === 'q' || $choice === 'Q') {
            $this->output->writeln("Cancelled.");
            return;
        }

        // Validate selection
        $choiceNum = (int)$choice;
        if ($choiceNum < 1 || $choiceNum > $profileCount) {
            $this->output->error("Invalid selection. Please enter a number between 1 and {$profileCount}.");
            return;
        }

        // Get selected profile
        $profileKeys = array_keys($profiles);
        $selectedProfileName = $profileKeys[$choiceNum - 1];
        $selectedProfile = $profiles[$selectedProfileName];

        // Link project to profile
        $this->output->writeln();
        $this->output->write("Linking to profile '{$selectedProfileName}'... ");
        $this->linkToProfile($selectedProfileName);
        $this->output->success("Done");

        // Test connection
        $this->output->write("Testing connection... ");
        if ($this->testConnectionToProfile($selectedProfile)) {
            $this->output->success("Connected!");
        } else {
            $this->output->error("Failed");
            $this->output->writeln();
            $this->output->warning("Could not connect to server. Please check:");
            $this->output->writeln("  • Server file is uploaded");
            $this->output->writeln("  • Server URL is correct: " . $selectedProfile['serverUrl']);
            $this->output->writeln("  • Token matches");
            return;
        }

        // Show connection banner
        $this->showConnectionBanner($selectedProfile, $selectedProfileName);
    }

    /**
     * Check if project is initialized
     */
    private function isProjectInitialized()
    {
        $configFile = ProjectPaths::configFile();
        $stateDir = ProjectPaths::stateDir();
        return file_exists($configFile) || is_dir($stateDir);
    }

    /**
     * Show error when project is not initialized
     */
    private function showNotInitializedError()
    {
        $this->output->writeln();
        $this->output->box(
            "⚠ Not Initialized\n\n" .
            "This directory is not initialized for ShipPHP.\n\n" .
            "Options:\n" .
            "1. Run '" . $this->cmd('init') . "' to initialize new project\n" .
            "2. Navigate to existing ShipPHP project\n\n" .
            "Current directory: " . basename(WORKING_DIR),
            'yellow'
        );
    }

    /**
     * Show profile table with nice formatting
     */
    private function showProfileTable($profiles)
    {
        $headers = ['ID', 'Profile', 'Project Name', 'Domain', 'Created'];
        $rows = [];

        $id = 1;
        foreach ($profiles as $name => $profile) {
            $rows[] = [
                $id++,
                $name,
                $profile['projectName'] ?? 'N/A',
                $profile['domain'] ?? $this->extractDomain($profile['serverUrl']),
                $this->formatDate($profile['created'] ?? 'Unknown')
            ];
        }

        $this->output->writeln();
        $this->output->table($headers, $rows);
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
        if ($dateString === 'Unknown') {
            return 'Unknown';
        }

        try {
            $date = new \DateTime($dateString);
            return $date->format('Y-m-d');
        } catch (\Exception $e) {
            return 'Unknown';
        }
    }

    /**
     * Link project to profile
     */
    private function linkToProfile($profileName)
    {
        $shipphpDir = ProjectPaths::stateDir();
        $linkFile = ProjectPaths::linkFile();

        // Create .shipphp directory if needed
        if (!is_dir($shipphpDir)) {
            mkdir($shipphpDir, 0755, true);
        }

        // Write profile name to link file
        file_put_contents($linkFile, $profileName);
        chmod($linkFile, 0600);  // Secure permissions
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
     * Show connection success banner
     */
    private function showConnectionBanner($profile, $profileName)
    {
        $projectName = $profile['projectName'] ?? 'Unknown';
        $domain = $profile['domain'] ?? $this->extractDomain($profile['serverUrl']);
        $tokenPreview = substr($profile['token'], 0, 8) . "..." . substr($profile['token'], -3);

        $this->output->writeln();
        $this->output->box(
            "🚀 ShipPHP Connected\n\n" .
            "Project:  {$projectName}\n" .
            "Domain:   {$domain}\n" .
            "Profile:  {$profileName}\n" .
            "Token:    {$tokenPreview} (active)",
            'green'
        );

        $this->output->writeln();
        $this->output->writeln("Ready to deploy! Use: " . $this->output->colorize($this->cmd('push'), 'green'));
        $this->output->writeln();
    }
}
