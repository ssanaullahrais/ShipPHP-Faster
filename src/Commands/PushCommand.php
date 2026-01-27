<?php

namespace ShipPHP\Commands;

use ShipPHP\Security\Security;

/**
 * Push Command
 * Upload changed files to server
 */
class PushCommand extends BaseCommand
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

        // Get specific file/directory to push (if provided)
        $specificPath = $this->getArg($options, 0);

        // Show status bar
        $this->showStatusBar();

        $this->header($dryRun ? "ShipPHP Push (Dry Run)" : "ShipPHP Push");

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

        // Get changes
        $this->output->write("Scanning local files... ");
        $currentFiles = $this->state->scanLocalFiles(
            WORKING_DIR,
            $this->config->get('ignore', [])
        );
        $this->output->writeln($this->output->colorize("Done", 'green'));

        $this->output->write("Fetching server file list... ");
        try {
            $serverFiles = $this->api->listFiles();
            $this->output->writeln($this->output->colorize("Done", 'green'));
        } catch (\Exception $e) {
            $this->output->writeln($this->output->colorize("Failed", 'red'));
            $this->output->error($e->getMessage());
            return;
        }

        // Compare CURRENT files (not stored state) with server
        $diff = $this->state->compareWithServer($serverFiles, $currentFiles);

        // Filter by specific path if provided
        if ($specificPath) {
            $this->output->writeln($this->output->colorize("Filtering for: {$specificPath}", 'cyan'));
            $diff = $this->filterDiffByPath($diff, $specificPath);
        }

        // Add conflicts to upload list (they need to be pushed)
        // Conflicts = files modified both locally and on server
        if (!empty($diff['conflicts'])) {
            foreach ($diff['conflicts'] as $conflictFile) {
                if (!in_array($conflictFile, $diff['toUpload'])) {
                    $diff['toUpload'][] = $conflictFile;
                }
            }
        }

        $this->output->writeln();

        // Show what will be done
        $totalOps = count($diff['toUpload']) + count($diff['toDelete']);

        if ($totalOps === 0 && !$force) {
            $this->output->success("Everything is already in sync. Nothing to push!");
            $this->output->writeln();
            $this->output->writeln("Use --force to push anyway.");
            $this->output->writeln();
            return;
        }

        if ($totalOps === 0 && $force) {
            $this->output->warning("No changes detected, but --force specified. Re-uploading all files...");
            // Add all current files to upload list
            foreach (array_keys($currentFiles) as $file) {
                if (!$specificPath || $this->pathMatches($file, $specificPath)) {
                    $diff['toUpload'][] = $file;
                }
            }
            $totalOps = count($diff['toUpload']);
        }

        // Show detailed file list (like status command)
        $this->output->writeln($this->output->colorize("Changes to push:", 'cyan'));

        if (count($diff['toUpload']) > 0) {
            $this->output->writeln($this->output->colorize("  ↑ " . count($diff['toUpload']) . " files to upload", 'cyan'));

            // Show file list if <= 10 files, otherwise show first 10
            $filesToShow = array_slice($diff['toUpload'], 0, 10);
            foreach ($filesToShow as $file) {
                $this->output->writeln("    ↑ {$file}", 'cyan');
            }
            if (count($diff['toUpload']) > 10) {
                $this->output->writeln("    ... and " . (count($diff['toUpload']) - 10) . " more files");
            }
        }

        $deleteEnabled = $this->config->get('deleteOnPush', false);
        if (count($diff['toDelete']) > 0) {
            if ($deleteEnabled) {
                $this->output->writeln($this->output->colorize("  × " . count($diff['toDelete']) . " files to delete from server", 'yellow'));

                // Show file list if <= 10 files, otherwise show first 10
                $filesToShow = array_slice($diff['toDelete'], 0, 10);
                foreach ($filesToShow as $file) {
                    $this->output->writeln("    × {$file}", 'red');
                }
                if (count($diff['toDelete']) > 10) {
                    $this->output->writeln("    ... and " . (count($diff['toDelete']) - 10) . " more files");
                }
            } else {
                $this->output->writeln("  × " . count($diff['toDelete']) . " files deleted locally (server files will NOT be deleted)", 'dim');
            }
        }

        // Show conflicts warning
        if (count($diff['conflicts']) > 0) {
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

            if (!$force) {
                if (!$this->output->confirm("Push will overwrite server changes. Continue?", false)) {
                    $this->output->writeln("Push cancelled.\n");
                    return;
                }
            }
        }

        $this->output->writeln();

        // Dry run - stop here
        if ($dryRun) {
            $this->output->info("Dry run complete. No changes were made.");
            $this->output->writeln("Run without --dry-run to push changes.\n");
            return;
        }

        // Confirm push
        if (!$force) {
            if (!$this->output->confirm("Push {$totalOps} changes to server?", true)) {
                $this->output->writeln("Push cancelled.\n");
                return;
            }
        }

        $this->output->writeln();

        // Create backup if enabled - only backup files that will be pushed
        $backupId = null;
        if ($this->config->get('backup.beforePush') && !$skipBackup) {
            try {
                // Backup only files that will be uploaded (not deleted files)
                $filesToBackup = $diff['toUpload'];

                if (!empty($filesToBackup)) {
                    $this->output->info("Creating backup of " . count($filesToBackup) . " files before push...");
                    // Pass currentFiles so backup uses fresh scanned state, not stored state
                    $backupId = $this->backup->createLocal('before-push', $filesToBackup, $currentFiles);
                    $this->output->writeln();
                }
            } catch (\Exception $e) {
                $this->output->warning("Backup failed: " . $e->getMessage());
                if (!$this->output->confirm("Continue without backup?", false)) {
                    $this->output->writeln("Push cancelled.\n");
                    return;
                }
                $this->output->writeln();
            }
        }

        // Upload files
        $uploaded = 0;
        $failed = 0;
        $total = count($diff['toUpload']);

        if ($total > 0) {
            $this->output->writeln($this->output->colorize("Uploading files:", 'cyan'));
            $this->output->writeln("Total files: {$total}");
            $this->output->writeln();

            $startTime = microtime(true);

            foreach ($diff['toUpload'] as $i => $file) {
                $localPath = WORKING_DIR . '/' . $file;

                try {
                    $this->api->uploadFile($localPath, $file);

                    // Update state
                    $hash = Security::hashFile($localPath);
                    $this->state->setFileHash($file, $hash);
                    $this->state->setServerFileHash($file, $hash);

                    $uploaded++;
                } catch (\Exception $e) {
                    $failed++;
                }

                // Show progress
                $this->showProgress($uploaded, $failed, $total, $startTime);
            }

            // Final progress
            $this->showProgress($uploaded, $failed, $total, $startTime, true);

            $this->output->writeln();
            $elapsed = round(microtime(true) - $startTime, 2);
            $this->output->success("Completed in {$elapsed}s - Uploaded: {$uploaded}, Failed: {$failed}");
            $this->output->writeln();
        }

        // Delete files from server
        $deleted = 0;
        if ($deleteEnabled && count($diff['toDelete']) > 0) {
            $this->output->writeln($this->output->colorize("Deleting files from server:", 'yellow'));

            if (!$force) {
                $this->output->warning("About to delete " . count($diff['toDelete']) . " files from server!");
                if (!$this->output->confirm("Are you sure?", false)) {
                    $this->output->writeln("Deletion skipped.\n");
                } else {
                    $deleted = $this->deleteFiles($diff['toDelete'], $force);
                }
            } else {
                $deleted = $this->deleteFiles($diff['toDelete'], $force);
            }
        }

        // Update state
        $this->state->updateLastPush();
        $this->state->updateServerFiles($currentFiles);
        $this->state->save();

        // Show summary
        $this->output->writeln();
        $this->output->writeln(str_repeat("═", 60), 'cyan');
        $this->output->success("Push complete!");
        $this->output->writeln("  ✓ Uploaded: {$uploaded}");
        if ($deleted > 0) {
            $this->output->writeln("  ✓ Deleted: {$deleted}");
        }
        if ($failed > 0) {
            $this->output->writeln("  ✗ Failed: {$failed}", 'red');
        }
        $this->output->writeln(str_repeat("═", 60), 'cyan');

        // Show undo/revert command if backup was created
        if ($backupId) {
            $this->output->writeln();
            $this->output->writeln($this->output->colorize("💡 Oops! Need to UNDO? No stress, we got you covered:", 'yellow'));
            $this->output->writeln();
            $this->output->writeln("  " . $this->output->colorize($this->cmd("backup restore {$backupId}"), 'green'));
            $this->output->writeln();
            $this->output->writeln($this->output->colorize("  Just copy-paste the command above to revert everything! 🚀", 'dim'));
            $this->output->writeln();
        } else {
            $this->output->writeln();
        }
    }

    /**
     * Delete files from server
     */
    private function deleteFiles($files, $force)
    {
        $deleted = 0;
        $failed = 0;
        $total = count($files);

        $this->output->writeln("Total files: {$total}");
        $this->output->writeln();

        $startTime = microtime(true);

        foreach ($files as $i => $file) {
            try {
                $this->api->deleteFile($file);

                // Update state
                $this->state->removeFile($file);

                $deleted++;
            } catch (\Exception $e) {
                $failed++;
            }

            // Show progress
            $this->showProgress($deleted, $failed, $total, $startTime);
        }

        // Final progress
        $this->showProgress($deleted, $failed, $total, $startTime, true);

        $this->output->writeln();
        $elapsed = round(microtime(true) - $startTime, 2);
        $this->output->success("Completed in {$elapsed}s - Deleted: {$deleted}, Failed: {$failed}");
        $this->output->writeln();

        return $deleted;
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
}
