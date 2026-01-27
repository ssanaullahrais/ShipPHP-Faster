<?php

namespace ShipPHP\Core;

use ShipPHP\Commands\InitCommand;
use ShipPHP\Commands\StatusCommand;
use ShipPHP\Commands\PushCommand;
use ShipPHP\Commands\PullCommand;
use ShipPHP\Commands\SyncCommand;
use ShipPHP\Commands\BackupCommand;
use ShipPHP\Commands\EnvCommand;
use ShipPHP\Commands\DiffCommand;
use ShipPHP\Commands\BootstrapCommand;
use ShipPHP\Commands\HealthCommand;
use ShipPHP\Commands\LoginCommand;
use ShipPHP\Commands\TokenCommand;
use ShipPHP\Commands\ProfileCommand;
use ShipPHP\Commands\ServerCommand;
use ShipPHP\Commands\InstallCommand;
use ShipPHP\Helpers\Output;
use ShipPHP\Core\VersionChecker;

/**
 * Main Application Class
 * Handles command routing and application lifecycle
 */
class Application
{
    private $commands = [];
    private $output;

    public function __construct()
    {
        $this->output = new Output();
        $this->registerCommands();
    }

    /**
     * Register all available commands
     */
    private function registerCommands()
    {
        $this->commands = [
            'init' => InitCommand::class,
            'login' => LoginCommand::class,
            'status' => StatusCommand::class,
            'push' => PushCommand::class,
            'pull' => PullCommand::class,
            'sync' => SyncCommand::class,
            'backup' => BackupCommand::class,
            'env' => EnvCommand::class,
            'diff' => DiffCommand::class,
            'bootstrap' => BootstrapCommand::class,
            'health' => HealthCommand::class,
            'token' => TokenCommand::class,
            'profile' => ProfileCommand::class,
            'server' => ServerCommand::class,
            'install' => InstallCommand::class,
        ];
    }

    /**
     * Run the application
     */
    public function run($argv)
    {
        // Remove script name
        array_shift($argv);

        // Get command
        $command = $argv[0] ?? '';

        // Parse options and arguments
        $options = $this->parseArguments(array_slice($argv, 1));

        // Show version
        if (in_array($command, ['--version', '-v'])) {
            $this->showVersion();
            return;
        }

        // Show quick start dashboard (no command given)
        if (empty($command)) {
            $this->showDashboard();
            return;
        }

        // Show detailed help
        if (in_array($command, ['help', '--help', '-h'])) {
            $this->showHelp();
            return;
        }

        // Execute command
        if (isset($this->commands[$command])) {
            try {
                $commandClass = $this->commands[$command];
                $commandInstance = new $commandClass($this->output);
                $commandInstance->execute($options);
            } catch (\Exception $e) {
                // Catch exceptions and show friendly error messages
                $this->handleCommandError($e, $command);
            }
        } else {
            $this->output->error("Unknown command: {$command}");
            $this->output->writeln("\nRun 'shipphp help' to see available commands.\n");
            exit(1);
        }
    }

    /**
     * Handle command execution errors with friendly messages
     */
    private function handleCommandError(\Exception $e, $command)
    {
        $message = $e->getMessage();
        $cmd = SHIPPHP_COMMAND;

        $this->output->writeln("");

        // Check if it's an initialization error
        if (strpos($message, 'not initialized') !== false) {
            $this->output->writeln("╔════════════════════════════════════════════════════════════╗", 'yellow');
            $this->output->writeln("║  ⚠ Project Not Initialized                                ║", 'yellow');
            $this->output->writeln("╚════════════════════════════════════════════════════════════╝", 'yellow');
            $this->output->writeln("");
            $this->output->writeln("This directory is not set up for ShipPHP yet.", 'yellow');
            $this->output->writeln("");
            $this->output->writeln($this->output->colorize("Choose one of these options:", 'cyan'));
            $this->output->writeln("");
            $this->output->writeln("  1️⃣  " . $this->output->colorize("Create new project configuration:", 'white'));
            $this->output->writeln("     " . $this->output->colorize("{$cmd} init", 'green'));
            $this->output->writeln("     (Creates new shipphp.json and server file)");
            $this->output->writeln("");
            $this->output->writeln("  2️⃣  " . $this->output->colorize("Connect to existing profile:", 'white'));
            $this->output->writeln("     " . $this->output->colorize("{$cmd} login", 'green'));
            $this->output->writeln("     (Links this directory to a global profile)");
            $this->output->writeln("");
            $this->output->writeln("  3️⃣  " . $this->output->colorize("View your profiles:", 'white'));
            $this->output->writeln("     " . $this->output->colorize("{$cmd} profile list", 'green'));
            $this->output->writeln("");

        } else if (strpos($message, 'Server configuration incomplete') !== false) {
            $this->output->writeln("╔════════════════════════════════════════════════════════════╗", 'red');
            $this->output->writeln("║  ✗ Server Configuration Incomplete                        ║", 'red');
            $this->output->writeln("╚════════════════════════════════════════════════════════════╝", 'red');
            $this->output->writeln("");
            $this->output->writeln("Your shipphp.json is missing server URL or token.", 'red');
            $this->output->writeln("");
            $this->output->writeln($this->output->colorize("To fix this:", 'cyan'));
            $this->output->writeln("  1. Run " . $this->output->colorize("{$cmd} init", 'green') . " to reconfigure");
            $this->output->writeln("  2. Or manually edit your shipphp.json file");
            $this->output->writeln("");

        } else {
            // Generic error display
            $this->output->writeln("╔════════════════════════════════════════════════════════════╗", 'red');
            $this->output->writeln("║  ✗ Error                                                  ║", 'red');
            $this->output->writeln("╚════════════════════════════════════════════════════════════╝", 'red');
            $this->output->writeln("");
            $this->output->error($message);
            $this->output->writeln("");
        }

        exit(1);
    }

    /**
     * Parse command line arguments
     */
    private function parseArguments($args)
    {
        $options = [
            'flags' => [],
            'params' => [],
            'args' => []
        ];

        foreach ($args as $arg) {
            if (strpos($arg, '--') === 0) {
                // Long option (--option or --option=value)
                $parts = explode('=', substr($arg, 2), 2);
                if (count($parts) === 2) {
                    $options['params'][$parts[0]] = $parts[1];
                } else {
                    $options['flags'][$parts[0]] = true;
                }
            } elseif (strpos($arg, '-') === 0 && strlen($arg) > 1) {
                // Short option (-o)
                $flag = substr($arg, 1);
                $options['flags'][$flag] = true;
            } else {
                // Regular argument
                $options['args'][] = $arg;
            }
        }

        return $options;
    }

    /**
     * Show quick start dashboard
     */
    private function showDashboard()
    {
        $this->output->writeln("");
        $this->output->writeln("╔════════════════════════════════════════════════════════════╗");
        $this->output->writeln("║                                                            ║");
        $this->output->writeln("║            🚀 ShipPHP Faster v" . SHIPPHP_VERSION . "                   ║");
        $this->output->writeln("║                                                            ║");
        $this->output->writeln("╚════════════════════════════════════════════════════════════╝");
        $this->output->writeln("");

        // Check installation status
        $installType = VersionChecker::getInstallationType();
        $isGlobal = ($installType === 'global');
        $isInitialized = file_exists(WORKING_DIR . '/shipphp.json');

        $this->output->writeln($this->output->colorize("STATUS", 'cyan'));
        $this->output->writeln("  Installation:  " . ($isGlobal ? $this->output->colorize("✓ Global", 'green') : $this->output->colorize("○ Local", 'yellow')));
        $this->output->writeln("  Current Dir:   " . ($isInitialized ? $this->output->colorize("✓ Initialized", 'green') : $this->output->colorize("○ Not initialized", 'yellow')));
        $this->output->writeln("");

        // Quick start guide
        $cmd = SHIPPHP_COMMAND;
        $this->output->writeln($this->output->colorize("QUICK START", 'cyan'));

        if (!$isInitialized) {
            $this->output->writeln("  1. Initialize project:     " . $this->output->colorize("{$cmd} init", 'green'));
            $this->output->writeln("  2. Connect to profile:     " . $this->output->colorize("{$cmd} login", 'green'));
            $this->output->writeln("  3. Deploy your changes:    " . $this->output->colorize("{$cmd} push", 'green'));
        } else {
            $this->output->writeln("  Check changes:             " . $this->output->colorize("{$cmd} status", 'green'));
            $this->output->writeln("  Deploy to server:          " . $this->output->colorize("{$cmd} push", 'green'));
            $this->output->writeln("  Download from server:      " . $this->output->colorize("{$cmd} pull", 'green'));
            $this->output->writeln("  Create backup:             " . $this->output->colorize("{$cmd} backup create", 'green'));
        }

        $this->output->writeln("");

        // Common commands
        $this->output->writeln($this->output->colorize("COMMON COMMANDS", 'cyan'));
        $this->output->writeln("  {$cmd} status              Check what changed");
        $this->output->writeln("  {$cmd} push                Deploy to server");
        $this->output->writeln("  {$cmd} backup create       Create backup");
        $this->output->writeln("  {$cmd} profile list        Manage profiles");
        $this->output->writeln("  {$cmd} health              Check server health");
        $this->output->writeln("");

        // Full help
        $this->output->writeln($this->output->colorize("NEED HELP?", 'cyan'));
        $this->output->writeln("  Full command list:         " . $this->output->colorize("{$cmd} help", 'green'));
        $this->output->writeln("  Documentation:             https://github.com/ssanaullahrais/ShipPHP-Faster");
        $this->output->writeln("");

        // Check for updates
        $update = VersionChecker::checkForUpdate();
        if ($update) {
            $this->output->writeln($this->output->colorize("💡 UPDATE AVAILABLE", 'yellow'));
            $this->output->writeln("  New version: " . $this->output->colorize("v{$update['version']}", 'green'));
            $this->output->writeln("  Update: " . $this->output->colorize(VersionChecker::getUpdateCommand(), 'cyan'));
            $this->output->writeln("");
        }
    }

    /**
     * Show version information
     */
    private function showVersion()
    {
        $this->output->writeln("");
        $this->output->success("ShipPHP Faster v" . SHIPPHP_VERSION);

        // Check for updates
        $update = VersionChecker::checkForUpdate();
        if ($update) {
            $this->output->writeln("");
            $this->output->writeln($this->output->colorize("💡 New version available: v{$update['version']}", 'yellow'));
            $this->output->writeln("   Update: " . $this->output->colorize(VersionChecker::getUpdateCommand(), 'cyan'));
        }

        $this->output->writeln("");
    }

    /**
     * Show help information
     */
    private function showHelp()
    {
        $this->output->writeln("");
        $this->output->writeln("╔════════════════════════════════════════════════════════════╗");
        $this->output->writeln("║                                                            ║");
        $this->output->writeln("║            🚀 ShipPHP Faster v" . SHIPPHP_VERSION . "                   ║");
        $this->output->writeln("║                                                            ║");
        $this->output->writeln("║     Secure, Git-like PHP Deployment with Backups          ║");
        $this->output->writeln("║                                                            ║");
        $this->output->writeln("╚════════════════════════════════════════════════════════════╝");
        $cmd = SHIPPHP_COMMAND;

        $this->output->writeln("");
        $this->output->writeln($this->output->colorize("USAGE:", 'cyan'));
        $this->output->writeln("  {$cmd} <command> [options] [arguments]");
        $this->output->writeln("");
        $this->output->writeln($this->output->colorize("COMMANDS:", 'cyan'));
        $this->output->writeln("");
        $this->output->writeln($this->output->colorize("  Setup & Configuration:", 'yellow'));
        $this->output->writeln("    install --global  Install ShipPHP globally (use from anywhere)");
        $this->output->writeln("    init              Initialize ShipPHP in current directory");
        $this->output->writeln("    login             Connect project to a global profile");
        $this->output->writeln("    bootstrap [path]  Create bootstrap file for easier command usage");
        $this->output->writeln("    env [name]        Switch between environments (staging/production)");
        $this->output->writeln("");
        $this->output->writeln($this->output->colorize("  Deployment:", 'yellow'));
        $this->output->writeln("    status            Show changes since last sync");
        $this->output->writeln("    push [path]       Upload changed files to server");
        $this->output->writeln("    pull [path]       Download changed files from server");
        $this->output->writeln("    sync              Status + Push (with confirmation)");
        $this->output->writeln("");
        $this->output->writeln($this->output->colorize("  Backup Management:", 'yellow'));
        $this->output->writeln("    backup create            Create local backup with version tracking");
        $this->output->writeln("    backup create --server   Create and upload backup to server");
        $this->output->writeln("    backup restore <id>      Restore from local backup");
        $this->output->writeln("    backup restore <id> --server  Download and restore from server");
        $this->output->writeln("    backup sync <id>         Upload specific backup to server");
        $this->output->writeln("    backup sync --all        Upload all backups to server");
        $this->output->writeln("    backup pull <id>         Download specific backup from server");
        $this->output->writeln("    backup pull --all        Download all backups from server");
        $this->output->writeln("    backup delete <id>       Delete specific backup (with confirmation)");
        $this->output->writeln("    backup delete <id> --local   Delete from local only");
        $this->output->writeln("    backup delete <id> --server  Delete from server only");
        $this->output->writeln("    backup delete <id> --both    Delete from both local and server");
        $this->output->writeln("    backup delete --all      Delete all backups (with confirmation)");
        $this->output->writeln("    backup stats             Show backup comparison table (local & server)");
        $this->output->writeln("    backup                   List all local backups");
        $this->output->writeln("");
        $this->output->writeln($this->output->colorize("  Profile Management:", 'yellow'));
        $this->output->writeln("    profile list              List all global profiles");
        $this->output->writeln("    profile add               Add new profile interactively");
        $this->output->writeln("    profile show <name>       Show profile details");
        $this->output->writeln("    profile use <name>        Set default profile");
        $this->output->writeln("    profile remove <name>     Remove profile");
        $this->output->writeln("    server generate <name>    Generate server file & create profile");
        $this->output->writeln("");
        $this->output->writeln($this->output->colorize("  Security:", 'yellow'));
        $this->output->writeln("    token show                Show current authentication token");
        $this->output->writeln("    token rotate              Generate new token (requires server upload)");
        $this->output->writeln("");
        $this->output->writeln($this->output->colorize("  Utilities:", 'yellow'));
        $this->output->writeln("    health            Check server health and diagnostics");
        $this->output->writeln("    diff [file]       Show differences for specific file");
        $this->output->writeln("");
        $this->output->writeln($this->output->colorize("OPTIONS:", 'cyan'));
        $this->output->writeln("    --help, -h        Show help information");
        $this->output->writeln("    --version, -v     Show version information");
        $this->output->writeln("    --dry-run         Preview changes without executing");
        $this->output->writeln("    --force           Skip confirmations (use with caution)");
        $this->output->writeln("    --detailed, -d    Show detailed output (for health, status, etc.)");
        $this->output->writeln("    --yes, -y         Auto-confirm all prompts");
        $this->output->writeln("");
        $this->output->writeln($this->output->colorize("EXAMPLES:", 'cyan'));
        $this->output->writeln("    {$cmd} install --global       # Install globally");
        $this->output->writeln("    {$cmd} init                   # Setup new project");
        $this->output->writeln("    {$cmd} bootstrap ./ship       # Create bootstrap file");
        $this->output->writeln("    {$cmd} health                 # Check server health");
        $this->output->writeln("    {$cmd} health --detailed      # Detailed health report");
        $this->output->writeln("    {$cmd} status                 # Check what changed");
        $this->output->writeln("    {$cmd} push                   # Deploy all changes");
        $this->output->writeln("    {$cmd} push index.php         # Deploy specific file");
        $this->output->writeln("    {$cmd} push src/              # Deploy specific directory");
        $this->output->writeln("    {$cmd} push --dry-run         # Preview push");
        $this->output->writeln("    {$cmd} pull                   # Download changes");
        $this->output->writeln("    {$cmd} backup                 # List all backups");
        $this->output->writeln("    {$cmd} backup create          # Create local backup");
        $this->output->writeln("    {$cmd} backup create --server # Create and upload to server");
        $this->output->writeln("    {$cmd} backup restore <id>    # Restore from backup");
        $this->output->writeln("    {$cmd} backup sync --all      # Sync all backups to server");
        $this->output->writeln("    {$cmd} backup pull --all      # Download all backups from server");
        $this->output->writeln("    {$cmd} backup delete <id> --both  # Delete backup from local & server");
        $this->output->writeln("    {$cmd} backup stats           # Show backup comparison table");
        $this->output->writeln("");
        $this->output->writeln($this->output->colorize("GETTING STARTED:", 'cyan'));
        $this->output->writeln("  1. Put shipphp/ folder in your project root");
        $this->output->writeln("  2. Run '{$cmd} init' → Enter project name & domain");
        $this->output->writeln("  3. Upload the generated shipphp-server.php to your server");
        $this->output->writeln("  4. Run '{$cmd} login' → Select your profile");
        $this->output->writeln("  5. Run '{$cmd} push' to deploy!");
        $this->output->writeln("");
        $this->output->writeln($this->output->colorize("GLOBAL INSTALLATION:", 'cyan'));
        $this->output->writeln("  {$cmd} install --global");
        $this->output->writeln("  Then use 'shipphp' command from anywhere!");
        $this->output->writeln("");

        // Show bootstrap tip only if using full path
        if (strpos($cmd, '/') !== false && strpos($cmd, 'shipphp.php') !== false) {
            $this->output->writeln($this->output->colorize("  💡 TIP:", 'yellow') . " Create a bootstrap file for shorter commands:");
            $this->output->writeln("     {$cmd} bootstrap ./ship");
            $this->output->writeln("     Then run: php ship init, php ship status, php ship push, etc.");
            $this->output->writeln("     Or on Unix: ./ship init, ./ship status, ./ship push");
            $this->output->writeln("");
        }
        $this->output->writeln($this->output->colorize("DOCUMENTATION:", 'cyan'));
        $this->output->writeln("  https://github.com/ssanaullahrais/ShipPHP-Faster");
        $this->output->writeln("");
    }
}
