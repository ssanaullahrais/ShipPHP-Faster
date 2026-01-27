<?php
namespace ShipPHP\Commands;

use ShipPHP\Helpers\Output;

class InstallCommand extends BaseCommand
{
    private $isWindows;
    private $installDir;
    private $binFile;

    public function execute($options)
    {
        $this->isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        $this->header("ShipPHP Global Installer");
        $this->output->writeln();

        // Check if already installed globally
        if ($this->isAlreadyInstalled()) {
            $this->output->warning("ShipPHP is already installed globally!");
            $this->output->writeln("  Location: " . $this->getInstalledLocation());
            $this->output->writeln();

            $update = $this->output->ask("Do you want to update it?", ['yes', 'no'], 'no');
            if ($update !== 'yes') {
                $this->output->info("Installation cancelled.");
                return;
            }
            $this->output->writeln();
        }

        // Confirm installation
        $this->output->writeln("This will install ShipPHP globally so you can use it from anywhere:");
        $this->output->writeln("  • Run 'shipphp' from any directory");
        $this->output->writeln("  • No need to specify full path");
        $this->output->writeln("  • Works across all projects");
        $this->output->writeln();

        $confirm = $this->output->ask("Proceed with global installation?", ['yes', 'no'], 'yes');
        if ($confirm !== 'yes') {
            $this->output->info("Installation cancelled.");
            return;
        }

        $this->output->writeln();
        $this->output->info("Installing ShipPHP globally...");
        $this->output->writeln();

        try {
            if ($this->isWindows) {
                $this->installWindows();
            } else {
                $this->installUnix();
            }

            $this->output->writeln();
            $this->output->success("✓ ShipPHP installed successfully!");
            $this->output->writeln();
            $this->showUsageInstructions();

        } catch (\Exception $e) {
            $this->output->writeln();
            $this->output->error("Installation failed: " . $e->getMessage());
            $this->output->writeln();
            $this->showManualInstructions();
        }
    }

    private function isAlreadyInstalled()
    {
        if ($this->isWindows) {
            // Check if shipphp.bat exists in PATH
            exec('where shipphp 2>nul', $output, $returnCode);
            return $returnCode === 0;
        } else {
            // Check if shipphp exists in PATH
            exec('which shipphp 2>/dev/null', $output, $returnCode);
            return $returnCode === 0;
        }
    }

    private function getInstalledLocation()
    {
        if ($this->isWindows) {
            exec('where shipphp 2>nul', $output);
        } else {
            exec('which shipphp 2>/dev/null', $output);
        }
        return $output[0] ?? 'Unknown';
    }

    private function installWindows()
    {
        // Determine installation directory
        $this->installDir = getenv('PROGRAMFILES') . '\\ShipPHP';
        if (!$this->installDir) {
            $this->installDir = 'C:\\Program Files\\ShipPHP';
        }

        $this->output->writeln("→ Installing to: {$this->installDir}");

        // Create installation directory
        if (!is_dir($this->installDir)) {
            if (!mkdir($this->installDir, 0755, true)) {
                throw new \Exception("Failed to create directory: {$this->installDir}. Try running as Administrator.");
            }
        }

        // Copy entire shipphp directory
        $sourceDir = dirname(dirname(__DIR__));
        $this->copyDirectory($sourceDir, $this->installDir);
        $this->output->writeln("→ Files copied successfully");

        // Create batch file wrapper
        $this->binFile = $this->installDir . '\\shipphp.bat';
        $batContent = '@echo off' . PHP_EOL;
        $batContent .= 'php "%~dp0shipphp.php" %*' . PHP_EOL;

        if (file_put_contents($this->binFile, $batContent) === false) {
            throw new \Exception("Failed to create batch file");
        }
        $this->output->writeln("→ Created shipphp.bat wrapper");

        // Add to PATH
        $this->addToWindowsPath($this->installDir);
        $this->output->writeln("→ Added to system PATH");
    }

    private function installUnix()
    {
        // Determine installation directory
        $possibleDirs = [
            '/usr/local/bin',
            '/usr/bin',
            getenv('HOME') . '/.local/bin'
        ];

        $this->installDir = null;
        foreach ($possibleDirs as $dir) {
            if (is_writable($dir)) {
                $this->installDir = $dir;
                break;
            }
        }

        if (!$this->installDir) {
            // Try to create user local bin
            $homeLocal = getenv('HOME') . '/.local/bin';
            if (!is_dir($homeLocal)) {
                mkdir($homeLocal, 0755, true);
            }
            $this->installDir = $homeLocal;
        }

        $this->output->writeln("→ Installing to: {$this->installDir}");

        // Copy entire shipphp directory to a lib location
        $libDir = dirname($this->installDir) . '/lib/shipphp';
        if (!is_dir($libDir)) {
            mkdir($libDir, 0755, true);
        }

        $sourceDir = dirname(dirname(__DIR__));
        $this->copyDirectory($sourceDir, $libDir);
        $this->output->writeln("→ Files copied to: {$libDir}");

        // Create symlink in bin directory
        $this->binFile = $this->installDir . '/shipphp';

        // Remove existing symlink if present
        if (file_exists($this->binFile)) {
            unlink($this->binFile);
        }

        if (!symlink($libDir . '/shipphp.php', $this->binFile)) {
            throw new \Exception("Failed to create symlink. Try: sudo php shipphp.php install --global");
        }

        // Make executable
        chmod($this->binFile, 0755);
        chmod($libDir . '/shipphp.php', 0755);

        $this->output->writeln("→ Created executable: {$this->binFile}");

        // Add to PATH if needed
        $this->addToUnixPath($this->installDir);
    }

    private function copyDirectory($source, $destination)
    {
        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }

        $dir = opendir($source);
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $srcPath = $source . DIRECTORY_SEPARATOR . $file;
            $dstPath = $destination . DIRECTORY_SEPARATOR . $file;

            if (is_dir($srcPath)) {
                $this->copyDirectory($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
        closedir($dir);
    }

    private function addToWindowsPath($directory)
    {
        // Check if already in PATH
        $currentPath = getenv('PATH');
        if (stripos($currentPath, $directory) !== false) {
            return;
        }

        $this->output->writeln();
        $this->output->warning("⚠ PATH update requires manual action:");
        $this->output->writeln("  1. Press Win + R, type: SystemPropertiesAdvanced");
        $this->output->writeln("  2. Click 'Environment Variables'");
        $this->output->writeln("  3. Under 'System variables', find 'Path', click 'Edit'");
        $this->output->writeln("  4. Click 'New' and add: {$directory}");
        $this->output->writeln("  5. Click 'OK' on all windows");
        $this->output->writeln("  6. Restart your terminal/CMD");
        $this->output->writeln();
        $this->output->info("Or copy this to run as Administrator in CMD:");
        $this->output->writeln("  setx /M PATH \"%PATH%;{$directory}\"");
    }

    private function addToUnixPath($directory)
    {
        // Check if already in PATH
        $currentPath = getenv('PATH');
        if (strpos($currentPath, $directory) !== false) {
            return;
        }

        // Try to add to shell profile
        $home = getenv('HOME');
        $profiles = [
            $home . '/.bashrc',
            $home . '/.zshrc',
            $home . '/.profile'
        ];

        $pathLine = PHP_EOL . '# Added by ShipPHP' . PHP_EOL;
        $pathLine .= 'export PATH="$PATH:' . $directory . '"' . PHP_EOL;

        foreach ($profiles as $profile) {
            if (file_exists($profile)) {
                // Check if already added
                $content = file_get_contents($profile);
                if (strpos($content, $directory) === false) {
                    file_put_contents($profile, $pathLine, FILE_APPEND);
                    $this->output->writeln("→ Updated: {$profile}");
                }
            }
        }

        $this->output->writeln();
        $this->output->info("Run this to activate in current session:");
        $this->output->writeln("  export PATH=\"\$PATH:{$directory}\"");
    }

    private function showUsageInstructions()
    {
        $this->output->box([
            "ShipPHP is now installed globally!",
            "",
            "Usage from any project:",
            "  cd /path/to/your/project",
            "  shipphp init",
            "  shipphp status",
            "  shipphp push",
            "",
            $this->isWindows ? "Note: Restart your terminal for PATH changes to take effect" : "Note: Restart terminal or run: source ~/.bashrc"
        ], 'green');
    }

    private function showManualInstructions()
    {
        $this->output->warning("Automatic installation failed. Manual steps:");
        $this->output->writeln();

        if ($this->isWindows) {
            $this->output->writeln("1. Copy shipphp folder to: C:\\shipphp");
            $this->output->writeln("2. Add C:\\shipphp to your PATH:");
            $this->output->writeln("   - Search 'Environment Variables'");
            $this->output->writeln("   - Edit 'Path', add: C:\\shipphp");
            $this->output->writeln("3. Create C:\\shipphp\\shipphp.bat:");
            $this->output->writeln("   @echo off");
            $this->output->writeln("   php \"%~dp0shipphp.php\" %*");
        } else {
            $this->output->writeln("Run these commands:");
            $this->output->writeln("  sudo mkdir -p /usr/local/lib/shipphp");
            $this->output->writeln("  sudo cp -r . /usr/local/lib/shipphp/");
            $this->output->writeln("  sudo ln -s /usr/local/lib/shipphp/shipphp.php /usr/local/bin/shipphp");
            $this->output->writeln("  sudo chmod +x /usr/local/bin/shipphp");
        }
    }
}
