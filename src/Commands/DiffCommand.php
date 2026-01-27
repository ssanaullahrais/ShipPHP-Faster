<?php

namespace ShipPHP\Commands;

/**
 * Diff Command
 * Show differences for specific file
 */
class DiffCommand extends BaseCommand
{
    public function execute($options)
    {
        $this->initConfig();
        $this->initState();

        $this->header("ShipPHP Diff");

        $file = $this->getArg($options, 0);

        if (!$file) {
            $this->output->error("File path required");
            $this->output->writeln("Usage: " . $this->cmd('diff <file>') . "\n");
            return;
        }

        $localPath = WORKING_DIR . '/' . $file;

        if (!file_exists($localPath)) {
            $this->output->error("File not found: {$file}");
            $this->output->writeln();
            return;
        }

        // Get file info
        $currentHash = hash_file('sha256', $localPath);
        $trackedHash = $this->state->getFileHash($file);
        $serverHash = $this->state->getServerFileHash($file);

        $this->output->writeln($this->output->colorize("File: {$file}", 'cyan'));
        $this->output->writeln();

        // Show hash comparison
        $this->output->writeln("Hash comparison:");
        $this->output->writeln("  Current:  " . $currentHash);

        if ($trackedHash) {
            $match = ($currentHash === $trackedHash) ? '✓' : '✗';
            $color = ($currentHash === $trackedHash) ? 'green' : 'red';
            $this->output->writeln("  Tracked:  " . $trackedHash . " " . $this->output->colorize($match, $color));
        } else {
            $this->output->writeln("  Tracked:  " . $this->output->colorize("(not tracked)", 'dim'));
        }

        if ($serverHash) {
            $match = ($currentHash === $serverHash) ? '✓' : '✗';
            $color = ($currentHash === $serverHash) ? 'green' : 'red';
            $this->output->writeln("  Server:   " . $serverHash . " " . $this->output->colorize($match, $color));
        } else {
            $this->output->writeln("  Server:   " . $this->output->colorize("(not on server)", 'dim'));
        }

        $this->output->writeln();

        // Show status
        if (!$trackedHash) {
            $this->output->info("This is a new file (not yet tracked)");
        } elseif ($currentHash !== $trackedHash) {
            $this->output->warning("File has been modified locally");
        } elseif ($serverHash && $currentHash !== $serverHash) {
            $this->output->warning("File is different on server");
        } else {
            $this->output->success("File is in sync");
        }

        $this->output->writeln();
    }
}
