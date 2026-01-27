<?php

namespace ShipPHP\Commands;

/**
 * Status Command
 * Show changes since last sync (git-like output)
 */
class StatusCommand extends BaseCommand
{
    public function execute($options)
    {
        $this->initConfig();
        $this->initState();
        $this->initApi();

        $detailed = $this->hasFlag($options, 'detailed');

        // Show status bar only if not initialized
        if (!$this->state->hasCompletedFirstPull()) {
            $this->showStatusBar();
        }

        $this->header("ShipPHP Status");

        // Get environment
        $env = $this->config->getCurrentEnv();
        $this->output->writeln("On branch: " . $this->output->colorize($env['name'], 'cyan'));
        $this->output->writeln();

        // Show detailed connection info only with --detailed flag
        if ($detailed) {
            $this->output->write("Server: " . $env['serverUrl'] . " ");
            try {
                $this->api->test();
                $this->output->success("Connected");
            } catch (\Exception $e) {
                $this->output->error("Connection failed");
                $this->output->writeln();
                $this->output->error($e->getMessage());
                return;
            }
            $this->output->writeln();
        }

        // Scan files (show progress only in detailed mode)
        if ($detailed) {
            $this->output->write("Scanning local files... ");
        }

        $currentFiles = $this->state->scanLocalFiles(
            WORKING_DIR,
            $this->config->get('ignore', [])
        );

        $changes = $this->state->getChanges(
            WORKING_DIR,
            $this->config->get('ignore', [])
        );

        if ($detailed) {
            $this->output->writeln($this->output->colorize("Done", 'green'));
            $this->output->write("Fetching server file list... ");
        }

        // Get server files
        try {
            $serverFiles = $this->api->listFiles();
            if ($detailed) {
                $this->output->writeln($this->output->colorize("Done", 'green'));
                $this->output->writeln();
            }
        } catch (\Exception $e) {
            if ($detailed) {
                $this->output->writeln($this->output->colorize("Failed", 'red'));
            }
            $this->output->error("Failed to connect to server: " . $e->getMessage());
            return;
        }

        // Compare files
        $diff = $this->state->compareWithServer($serverFiles, $currentFiles);

        // Calculate totals
        $totalToPush = count($changes['new']) + count($changes['modified']) + count($changes['deleted']);
        $totalToPull = count($diff['toDownload']);
        $totalConflicts = count($diff['conflicts']);

        // Show changes to push
        $this->output->writeln($this->output->colorize("Changes to push", 'cyan') . " (local → server):");

        if ($totalToPush > 0) {
            $this->showFileGroup($changes['new'], '+', 'added', 'green', $detailed);
            $this->showFileGroup($changes['modified'], 'M', 'modified', 'yellow', $detailed);
            $this->showFileGroup($changes['deleted'], '-', 'deleted', 'red', $detailed);
        } else {
            $this->output->writeln("  " . $this->output->colorize("✓ No changes", 'green'));
        }

        $this->output->writeln();

        // Show changes to pull
        $this->output->writeln($this->output->colorize("Changes to pull", 'cyan') . " (server → local):");

        if ($totalToPull > 0) {
            $this->showFileGroup($diff['toDownload'], '+', 'added on server', 'magenta', $detailed);
        } else {
            $this->output->writeln("  " . $this->output->colorize("✓ No changes", 'green'));
        }

        $this->output->writeln();

        // Show conflicts if any
        if ($totalConflicts > 0) {
            $this->output->writeln($this->output->colorize("⚠ Conflicts", 'yellow') . " (modified on both sides):");
            $this->showFileGroup($diff['conflicts'], '⚠', 'conflicting', 'yellow', true); // Always show conflicts
            $this->output->writeln();
        }

        // Summary section
        $this->output->writeln(str_repeat('─', 60));
        $this->output->writeln($this->output->colorize("Summary:", 'cyan'));
        $this->output->writeln("  {$totalToPush} files to push");
        $this->output->writeln("  {$totalToPull} files to pull");

        if ($totalConflicts > 0) {
            $this->output->writeln("  " . $this->output->colorize("{$totalConflicts} conflicts", 'yellow'));
        }

        $this->output->writeln();

        // Show warnings and next steps
        $this->showNextSteps($totalToPush, $totalToPull, $totalConflicts);

        // Show last sync time in detailed mode
        if ($detailed) {
            $lastSync = $this->state->getLastSync();
            if ($lastSync) {
                $this->output->writeln($this->output->colorize("Last sync: ", 'dim') .
                                     $this->output->colorize(date('Y-m-d H:i:s', strtotime($lastSync)), 'dim'));
                $this->output->writeln();
            }
        }
    }

    /**
     * Show a group of files with consistent formatting
     */
    private function showFileGroup($files, $symbol, $label, $color, $detailed)
    {
        if (empty($files)) {
            return;
        }

        $count = count($files);
        $this->output->writeln("  {$symbol} {$count} " . ($count === 1 ? rtrim($label, 's') : $label), $color);

        // Show individual files if detailed OR <= 5 files
        if ($detailed || $count <= 5) {
            foreach ($files as $file) {
                $this->output->writeln("    {$symbol} {$file}", $color);
            }
        } elseif ($count > 5) {
            // Show first 3 files
            for ($i = 0; $i < 3; $i++) {
                $this->output->writeln("    {$symbol} {$files[$i]}", $color);
            }
            $remaining = $count - 3;
            $this->output->writeln("    " . $this->output->colorize("... and {$remaining} more", 'dim'));
        }
    }

    /**
     * Show contextual next steps
     */
    private function showNextSteps($toPush, $toPull, $conflicts)
    {
        if ($toPush === 0 && $toPull === 0 && $conflicts === 0) {
            $this->output->success("✓ Everything is in sync! No action needed.");
            $this->output->writeln();
            return;
        }

        // Warning if both sides have changes
        if ($toPush > 0 && $toPull > 0) {
            $this->output->writeln($this->output->colorize("⚠ Warning:", 'yellow') . " Both local and server have changes");
            $this->output->writeln("  Consider pulling first to avoid conflicts");
            $this->output->writeln();
        }

        // Warning if conflicts exist
        if ($conflicts > 0) {
            $this->output->writeln($this->output->colorize("⚠ Warning:", 'yellow') . " Conflicts detected!");
            $this->output->writeln("  These files were modified on both local and server.");
            $this->output->writeln("  • Pushing will overwrite server changes");
            $this->output->writeln("  • Pulling will overwrite local changes");
            $this->output->writeln();
            $this->output->writeln("  Use '" . $this->cmd('diff <file>') . "' to compare changes");
            $this->output->writeln();
        }

        // Show next steps
        $this->output->writeln($this->output->colorize("Next steps:", 'cyan'));

        if ($toPull > 0 && $conflicts === 0) {
            $this->output->writeln("  Run '" . $this->output->colorize($this->cmd("pull"), 'green') . "' to download server changes");
        }

        if ($toPush > 0 && $toPull === 0 && $conflicts === 0) {
            $this->output->writeln("  Run '" . $this->output->colorize($this->cmd("push"), 'green') . "' to deploy local changes");
        }

        if ($toPush > 0 && $toPull > 0) {
            $this->output->writeln("  1. Run '" . $this->output->colorize($this->cmd("pull"), 'green') . "' to get server changes");
            $this->output->writeln("  2. Review and merge changes");
            $this->output->writeln("  3. Run '" . $this->output->colorize($this->cmd("push"), 'green') . "' to deploy");
        }

        if ($conflicts > 0) {
            $this->output->writeln("  1. Review conflicts with '" . $this->output->colorize($this->cmd("status --detailed"), 'green') . "'");
            $this->output->writeln("  2. Compare files with '" . $this->output->colorize($this->cmd("diff <file>"), 'green') . "'");
            $this->output->writeln("  3. Decide to pull or push (will overwrite one side)");
        }

        $this->output->writeln();
    }
}
