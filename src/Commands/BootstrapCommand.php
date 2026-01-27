<?php

namespace ShipPHP\Commands;

/**
 * Bootstrap Command
 * Create bootstrap files for easier command usage
 */
class BootstrapCommand extends BaseCommand
{
    public function execute($options)
    {
        $this->header("Create Bootstrap File");

        // Get target path from arguments
        $targetPath = $options['args'][0] ?? null;

        if (!$targetPath) {
            $this->output->error("Please specify a bootstrap file path");
            $this->output->writeln("\nExamples:");
            $this->output->writeln("  " . $this->cmd('bootstrap ./ship'));
            $this->output->writeln("  " . $this->cmd('bootstrap ./shipphp'));
            $this->output->writeln("  " . $this->cmd('bootstrap ship'));
            $this->output->writeln();
            return;
        }

        // Resolve target path (make it relative to WORKING_DIR)
        if (!preg_match('#^(/|[a-z]:\\\\|\\./|\\\\)#i', $targetPath)) {
            // If no path prefix, assume current directory
            $targetPath = WORKING_DIR . '/' . $targetPath;
        } else {
            $targetPath = WORKING_DIR . '/' . ltrim($targetPath, './');
        }

        // Check if file already exists
        if (file_exists($targetPath) && !$this->hasFlag($options, 'force')) {
            $this->output->error("File already exists: " . basename($targetPath));
            $this->output->writeln("Use --force to overwrite\n");
            return;
        }

        // Calculate relative path from target to shipphp.php
        $relativePath = $this->getRelativePath(dirname($targetPath), SHIPPHP_ROOT . '/shipphp.php');

        // Generate bootstrap content
        $bootstrapContent = $this->generateBootstrapContent($relativePath);

        // Write bootstrap file
        if (file_put_contents($targetPath, $bootstrapContent) === false) {
            $this->output->error("Failed to create bootstrap file");
            return;
        }

        // Make executable on Unix systems
        if (DIRECTORY_SEPARATOR === '/') {
            chmod($targetPath, 0755);
        }

        $this->output->writeln();
        $this->output->success("Bootstrap file created successfully!");
        $this->output->writeln();

        // Show usage instructions
        $this->showUsageInstructions(basename($targetPath));
    }

    /**
     * Generate bootstrap file content
     */
    private function generateBootstrapContent($shipphpPath)
    {
        return <<<PHP
#!/usr/bin/env php
<?php
/**
 * ShipPHP Bootstrap Loader
 *
 * This file allows you to run ShipPHP commands more easily:
 *   php ship init
 *   php ship status
 *   php ship push
 *
 * Or on Unix systems with executable permissions:
 *   ./ship init
 *   ./ship status
 *   ./ship push
 */

// Load the main ShipPHP executable
\$shipphpPath = __DIR__ . '/{$shipphpPath}';

if (!file_exists(\$shipphpPath)) {
    die("Error: ShipPHP executable not found at: \$shipphpPath" . PHP_EOL);
}

// Include and run ShipPHP with the same arguments
require \$shipphpPath;

PHP;
    }

    /**
     * Calculate relative path between two directories
     */
    private function getRelativePath($from, $to)
    {
        $from = str_replace('\\', '/', realpath($from));
        $to = str_replace('\\', '/', realpath($to));

        $fromParts = explode('/', $from);
        $toParts = explode('/', $to);

        // Find common base
        $commonLength = 0;
        $minLength = min(count($fromParts), count($toParts));

        for ($i = 0; $i < $minLength; $i++) {
            if ($fromParts[$i] === $toParts[$i]) {
                $commonLength++;
            } else {
                break;
            }
        }

        // Build relative path
        $relativeParts = [];

        // Add ../ for each directory we need to go up
        $upCount = count($fromParts) - $commonLength;
        for ($i = 0; $i < $upCount; $i++) {
            $relativeParts[] = '..';
        }

        // Add path segments to target
        for ($i = $commonLength; $i < count($toParts); $i++) {
            $relativeParts[] = $toParts[$i];
        }

        return implode('/', $relativeParts);
    }

    /**
     * Show usage instructions
     */
    private function showUsageInstructions($filename)
    {
        $this->output->writeln($this->output->colorize("📋 BOOTSTRAP FILE CREATED:", 'cyan'));
        $this->output->writeln("   ✓ File: " . $this->output->colorize($filename, 'white'));
        $this->output->writeln();

        $this->output->writeln($this->output->colorize("💡 USAGE:", 'yellow'));
        $this->output->writeln();

        $this->output->writeln("   Now you can use shorter commands:");
        $this->output->writeln("   " . $this->output->colorize("php {$filename} init", 'green') . str_repeat(' ', 6) . "# Initialize project");
        $this->output->writeln("   " . $this->output->colorize("php {$filename} status", 'green') . str_repeat(' ', 4) . "# Check status");
        $this->output->writeln("   " . $this->output->colorize("php {$filename} push", 'green') . str_repeat(' ', 6) . "# Deploy changes");
        $this->output->writeln("   " . $this->output->colorize("php {$filename} pull", 'green') . str_repeat(' ', 6) . "# Pull from server");
        $this->output->writeln("   " . $this->output->colorize("php {$filename} backups", 'green') . str_repeat(' ', 3) . "# List backups");
        $this->output->writeln();

        // Unix-specific instructions
        if (DIRECTORY_SEPARATOR === '/') {
            $this->output->writeln($this->output->colorize("🐧 UNIX/LINUX/MAC:", 'cyan'));
            $this->output->writeln("   The file is already executable. You can also run:");
            $this->output->writeln("   " . $this->output->colorize("./{$filename} init", 'green'));
            $this->output->writeln("   " . $this->output->colorize("./{$filename} status", 'green'));
            $this->output->writeln("   " . $this->output->colorize("./{$filename} push", 'green'));
            $this->output->writeln();
        }

        $this->output->writeln($this->output->colorize("✨ TIP:", 'yellow'));
        $this->output->writeln("   Add {$filename} to your .gitignore if you don't want to commit it");
        $this->output->writeln();
        $this->output->writeln($this->output->colorize("🎉 READY TO GO!", 'green'));
        $this->output->writeln("   Run: " . $this->output->colorize("php {$filename} status", 'green') . " to test your connection");
        $this->output->writeln();
    }
}
