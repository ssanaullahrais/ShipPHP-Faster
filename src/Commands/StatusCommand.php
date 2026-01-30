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

        $this->header("ShipPHP Status");

        // Get environment
        $env = $this->config->getCurrentEnv();
        $this->output->writeln("On branch: " . $this->output->colorize($env['name'], 'cyan'));
        $this->output->writeln();

        $this->output->write("Testing connection... ");
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

        $profile = $this->getCurrentProfile();
        if (!$profile) {
            $this->output->error("No active profile found. Run '" . $this->cmd('login') . "' to link a profile.");
            return;
        }

        $profileName = $profile['_profileName'] ?? $profile['profileId'] ?? 'local';
        $token = $profile['token'] ?? '';
        $tokenPreview = $token ? substr($token, 0, 6) . '...' . substr($token, -3) : 'none';

        $this->output->table(
            ['Profile', 'Server', 'Token'],
            [[
                $profileName,
                $env['serverUrl'],
                $tokenPreview
            ]]
        );

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

        $this->showChangesTable(
            "Changes to push (local → server)",
            [
                ['Added', $changes['new']],
                ['Modified', $changes['modified']],
                ['Deleted', $changes['deleted']]
            ],
            20
        );

        $this->showChangesTable(
            "Changes to pull (server → local)",
            [
                ['Added on server', $diff['toDownload']]
            ],
            20
        );

        if ($totalConflicts > 0) {
            $this->showChangesTable(
                "Conflicts (modified on both sides)",
                [
                    ['Conflict', $diff['conflicts']]
                ],
                20
            );
        }

        // Summary section
        $this->output->writeln(str_repeat('─', 60));
        $summaryRows = [
            ['To push', (string)$totalToPush],
            ['To pull', (string)$totalToPull]
        ];
        if ($totalConflicts > 0) {
            $summaryRows[] = ['Conflicts', (string)$totalConflicts];
        }
        $this->output->table(['Summary', 'Count'], $summaryRows);

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

    private function showChangesTable($title, $groups, $limit)
    {
        $this->output->writeln($this->output->colorize($title . ':', 'cyan'));

        $rows = [];
        $total = 0;

        foreach ($groups as $group) {
            [$label, $files] = $group;
            $total += count($files);
            foreach ($files as $file) {
                if (count($rows) >= $limit) {
                    break 2;
                }
                $rows[] = [$label, $file];
            }
        }

        if ($total === 0) {
            $this->output->writeln("  " . $this->output->colorize("✓ No changes", 'green'));
            $this->output->writeln();
            return;
        }

        $this->output->table(['Type', 'File'], $rows);

        if ($total > $limit) {
            $remaining = $total - $limit;
            $this->output->writeln($this->output->colorize("... and {$remaining} more", 'dim'));
            $this->output->writeln();
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
