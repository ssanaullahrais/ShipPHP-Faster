<?php

namespace ShipPHP\Commands;

/**
 * Chmod Command
 * Change file permissions on server
 */
class ChmodCommand extends BaseCommand
{
    public function execute($options)
    {
        $this->initApi();

        $path = $this->getArg($options, 0);
        $mode = $this->getArg($options, 1);
        $recursive = $this->hasFlag($options, 'recursive') || $this->hasFlag($options, 'R');

        if (empty($path) || empty($mode)) {
            $this->output->error("Usage: " . $this->cmd('chmod') . " <path> <mode>");
            $this->output->writeln();
            $this->output->writeln("Examples:");
            $this->output->writeln("  " . $this->cmd('chmod') . " public/upload 755");
            $this->output->writeln("  " . $this->cmd('chmod') . " config.php 644");
            $this->output->writeln("  " . $this->cmd('chmod') . " scripts/ 755 --recursive");
            $this->output->writeln();
            $this->output->writeln("Common Modes:");
            $this->output->writeln("  644  - Read/write owner, read others (files)");
            $this->output->writeln("  755  - Read/write/execute owner, read/execute others (directories)");
            $this->output->writeln("  600  - Read/write owner only (sensitive files)");
            $this->output->writeln("  700  - Full access owner only (private directories)");
            $this->output->writeln();
            $this->output->writeln("Options:");
            $this->output->writeln("  --recursive, -R   Apply recursively to directories");
            return;
        }

        // Validate mode
        if (!preg_match('/^[0-7]{3,4}$/', $mode)) {
            $this->output->error("Invalid mode '{$mode}'. Use octal format like 755 or 644.");
            return;
        }

        $path = $this->normalizeRelativePath($path);

        $this->header("Change Permissions");

        $this->output->writeln("Path: " . $this->output->colorize($path, 'cyan'));
        $this->output->writeln("Mode: " . $this->output->colorize($mode, 'cyan'));
        if ($recursive) {
            $this->output->writeln($this->output->colorize("  (recursive mode)", 'yellow'));
        }
        $this->output->writeln();

        try {
            $response = $this->api->chmod($path, $mode, $recursive);

            $changed = $response['changed'] ?? 1;
            $this->output->success("Permissions changed for {$changed} item(s)");
            $this->output->writeln("  Path: {$path}");
            $this->output->writeln("  Mode: " . ($response['mode'] ?? $mode));
        } catch (\Exception $e) {
            $this->output->error($e->getMessage());
        }

        $this->output->writeln();
    }
}
