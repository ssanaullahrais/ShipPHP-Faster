<?php

namespace ShipPHP\Commands;

use ShipPHP\Security\Security;

/**
 * Pull Command
 * Download files from server
 */
class PullCommand extends BaseCommand
{
    public function execute($options)
    {
        $this->initConfig();
        $this->initState();
        $this->initApi();
        $this->initBackup();

        $dryRun = $this->hasFlag($options, 'dry-run');
        $force = $this->hasFlag($options, 'force');
        $skipBackup = $this->hasFlag($options, 'skip-backup');

        // Get specific file/directory to pull (if provided)
        $specificPath = $this->getArg($options, 0);
        $remoteOverride = $this->getParam($options, 'from');
        $localOverride = $this->getParam($options, 'to');

        // Show status bar
        $this->showStatusBar();

        $this->header($dryRun ? "ShipPHP Pull (Dry Run)" : "ShipPHP Pull");

        // Test connection
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

        // Direct pull for a single file with remote/local override
        if ($remoteOverride || $localOverride) {
            $remotePath = $remoteOverride ?: $specificPath;
            $localPath = $localOverride ?: $specificPath;

            if (!$remotePath || !$localPath) {
                $this->output->error("Direct pull requires both remote and local paths.");
                $this->output->writeln("Usage: " . $this->cmd("pull <remote/path> --to=local/path"));
                return;
            }

            $this->pullSingleFile($remotePath, $localPath, $dryRun, $force);
            return;
        }

        // Get current local files
        $this->output->write("Scanning local files... ");
        $currentFiles = $this->state->scanLocalFiles(
            WORKING_DIR,
            $this->config->get('ignore', [])
        );
        $this->output->writeln($this->output->colorize("Done", 'green'));

        // Get files from server
        $this->output->write("Fetching server file list... ");
        try {
            $serverFiles = $this->api->listFiles();
            $this->output->writeln($this->output->colorize("Done", 'green'));
        } catch (\Exception $e) {
            $this->output->writeln($this->output->colorize("Failed", 'red'));
            $this->output->error($e->getMessage());
            return;
        }

        // Compare CURRENT files with server
        $diff = $this->state->compareWithServer($serverFiles, $currentFiles);

        // Filter by specific path if provided
        if ($specificPath) {
            $this->output->writeln($this->output->colorize("Filtering for: {$specificPath}", 'cyan'));
            $diff = $this->filterDiffByPath($diff, $specificPath);
        }

        $this->output->writeln();

        // Show what will be done
        $totalOps = count($diff['toDownload']);

        if ($totalOps === 0 && !$force) {
            $this->output->success("Everything is already in sync. Nothing to pull!");
            $this->output->writeln();
            $this->output->writeln("Use --force to pull anyway.");
            $this->output->writeln();
            return;
        }

        if ($totalOps === 0 && $force) {
            $this->output->warning("No changes detected, but --force specified. Re-downloading all files...");
            // Add all server files to download list
            foreach (array_keys($serverFiles) as $file) {
                if (!$specificPath || $this->pathMatches($file, $specificPath)) {
                    $diff['toDownload'][] = $file;
                }
            }
            $totalOps = count($diff['toDownload']);
        }

        // Show detailed file list (like status command)
        $this->output->writeln($this->output->colorize("Files to download:", 'cyan'));
        $this->output->writeln($this->output->colorize("  ↓ " . count($diff['toDownload']) . " files", 'magenta'));

        // Show file list if <= 10 files, otherwise show first 10
        $filesToShow = array_slice($diff['toDownload'], 0, 10);
        foreach ($filesToShow as $file) {
            $this->output->writeln("    ↓ {$file}", 'magenta');
        }
        if (count($diff['toDownload']) > 10) {
            $this->output->writeln("    ... and " . (count($diff['toDownload']) - 10) . " more files");
        }

        // Show conflicts warning
        if (count($diff['conflicts']) > 0 && !$force) {
            $this->output->writeln();
            $this->output->warning("⚠ " . count($diff['conflicts']) . " conflicts detected!");
            $this->output->writeln("These files were modified both locally and on server:");
            foreach (array_slice($diff['conflicts'], 0, 5) as $file) {
                $this->output->writeln("  • {$file}", 'yellow');
            }
            if (count($diff['conflicts']) > 5) {
                $this->output->writeln("  ... and " . (count($diff['conflicts']) - 5) . " more");
            }
            $this->output->writeln();

            if (!$this->output->confirm("Pull will overwrite local changes. Continue?", false)) {
                $this->output->writeln("Pull cancelled.\n");
                return;
            }
        }

        $this->output->writeln();

        // Dry run - stop here
        if ($dryRun) {
            $this->output->info("Dry run complete. No changes were made.");
            $this->output->writeln("Run without --dry-run to pull changes.\n");
            return;
        }

        // Confirm pull
        if (!$force) {
            if (!$this->output->confirm("Pull {$totalOps} files from server?", true)) {
                $this->output->writeln("Pull cancelled.\n");
                return;
            }
        }

        $this->output->writeln();

        // Download files
        $downloaded = 0;
        $failed = 0;
        $failedDownloads = [];
        $total = count($diff['toDownload']);

        $this->output->writeln($this->output->colorize("Downloading files:", 'cyan'));
        $this->output->writeln("Total files: {$total}");
        $this->output->writeln();

        $startTime = microtime(true);

        foreach ($diff['toDownload'] as $i => $file) {
            $localPath = WORKING_DIR . '/' . $file;

            try {
                $this->api->downloadFile($file, $localPath);

                // Update state
                $hash = Security::hashFile($localPath);
                $this->state->setFileHash($file, $hash);
                $this->state->setServerFileHash($file, $hash);

                $downloaded++;
            } catch (\Exception $e) {
                $failed++;
                $failedDownloads[] = [
                    'file' => $file,
                    'error' => $e->getMessage()
                ];
            }

            // Show progress
            $this->showProgress($downloaded, $failed, $total, $startTime);
        }

        // Final progress
        $this->showProgress($downloaded, $failed, $total, $startTime, true);

        $this->output->writeln();
        $elapsed = round(microtime(true) - $startTime, 2);
        $this->output->success("Completed in {$elapsed}s - Downloaded: {$downloaded}, Failed: {$failed}");
        $this->output->writeln();

        // Update state
        $this->state->updateLastPull();
        $this->state->updateServerFiles($serverFiles);
        $this->state->save();

        // Show summary
        $this->output->writeln();
        $this->output->writeln(str_repeat("═", 60), 'cyan');
        $this->output->success("Pull complete!");
        $this->output->writeln("  ✓ Downloaded: {$downloaded}");
        if ($failed > 0) {
            $this->output->writeln("  ✗ Failed: {$failed}", 'red');
        }
        $this->output->writeln(str_repeat("═", 60), 'cyan');
        $this->output->writeln();

        if (!empty($failedDownloads)) {
            $this->output->writeln($this->output->colorize("Download failures:", 'red'));
            $rows = [];
            foreach ($failedDownloads as $failure) {
                $rows[] = [$failure['file'], $failure['error']];
            }
            $this->output->table(['File', 'Error'], $rows);
        }
    }

    /**
     * Filter diff results by specific path
     */
    private function filterDiffByPath($diff, $path)
    {
        $filtered = [
            'toUpload' => [],
            'toDelete' => [],
            'toDownload' => [],
            'conflicts' => []
        ];

        foreach ($diff as $key => $files) {
            foreach ($files as $file) {
                if ($this->pathMatches($file, $path)) {
                    $filtered[$key][] = $file;
                }
            }
        }

        return $filtered;
    }

    /**
     * Check if a file path matches the specified path/pattern
     */
    private function pathMatches($file, $pattern)
    {
        // Normalize paths
        $file = str_replace('\\', '/', $file);
        $pattern = str_replace('\\', '/', $pattern);

        // Exact match
        if ($file === $pattern) {
            return true;
        }

        // Directory match (file is inside directory)
        if (strpos($file, rtrim($pattern, '/') . '/') === 0) {
            return true;
        }

        // Wildcard pattern
        if (strpos($pattern, '*') !== false) {
            $regex = '#^' . str_replace(['*', '?'], ['.*', '.'], preg_quote($pattern, '#')) . '$#';
            return preg_match($regex, $file);
        }

        return false;
    }

    /**
     * Pull a single file from a specific remote path.
     */
    private function pullSingleFile($remotePath, $localPath, $dryRun, $force)
    {
        try {
            $remotePath = $this->normalizeRelativePath($remotePath);
            $localPath = $this->normalizeRelativePath($localPath);
        } catch (\Exception $e) {
            $this->output->error($e->getMessage());
            return;
        }

        $fullLocalPath = WORKING_DIR . '/' . $localPath;

        $this->output->writeln($this->output->colorize("Direct pull:", 'cyan'));
        $this->output->writeln("  Remote: {$remotePath}");
        $this->output->writeln("  Local:  {$localPath}");
        $this->output->writeln();

        if ($dryRun) {
            $this->output->info("Dry run: no changes were made.");
            return;
        }

        if (!$force) {
            if (!$this->output->confirm("Download '{$remotePath}' to '{$localPath}'?", true)) {
                $this->output->writeln("Pull cancelled.\n");
                return;
            }
        }

        try {
            $this->api->downloadFile($remotePath, $fullLocalPath);
            $this->output->success("Downloaded '{$remotePath}' → '{$localPath}'");
        } catch (\Exception $e) {
            $this->output->error("Download failed");
            $this->output->writeln($e->getMessage());
            return;
        }

        if ($remotePath === $localPath) {
            $hash = Security::hashFile($fullLocalPath);
            $this->state->setFileHash($localPath, $hash);
            $this->state->setServerFileHash($localPath, $hash);
            $this->state->save();
        } else {
            $this->output->warning("Note: remote path differs from local path; state tracking was not updated.");
        }

        $this->output->writeln();
    }
}
