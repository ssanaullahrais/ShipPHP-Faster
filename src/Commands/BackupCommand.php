<?php

namespace ShipPHP\Commands;

use ShipPHP\Core\Config;
use ShipPHP\Core\Backup;
use ShipPHP\Core\ApiClient;

/**
 * Backup Command
 * Handles backup creation, restoration, and syncing with server
 */
class BackupCommand extends BaseCommand
{
    public function execute($options)
    {
        $this->initConfig();
        $this->initState();
        $this->initBackup();

        // Get subcommand
        $subcommand = $options['args'][0] ?? 'list';

        switch ($subcommand) {
            case 'create':
                $this->createBackup($options);
                break;

            case 'restore':
                $this->restoreBackup($options);
                break;

            case 'restore-server':
                $this->restoreServerBackup($options);
                break;

            case 'sync':
                $this->syncBackup($options);
                break;

            case 'pull':
                $this->pullBackup($options);
                break;

            case 'stats':
                $this->showStats($options);
                break;

            case 'clean':
                $this->cleanBackups($options);
                break;

            case 'delete':
                $this->deleteBackup($options);
                break;

            case 'list':
            default:
                $this->listBackups($options);
                break;
        }
    }

    /**
     * Create backup
     */
    private function createBackup($options)
    {
        $this->header("Create Backup");

        try {
            // Create local backup
            $backupId = $this->backup->createLocal();

            // Check if backup was actually created (returns null if no files)
            if ($backupId === null) {
                $this->output->info("We don't see any files here yet.");
                $this->output->writeln();
                $this->output->writeln("Make sure you have project files to backup.");
                $this->output->writeln("Files in .gitignore and .ignore are automatically excluded.");
                $this->output->writeln();
                return;
            }

            // If --server flag, upload to server
            if (isset($options['flags']['server'])) {
                $this->output->writeln();
                $this->initApi();

                $this->backup->uploadToServer($backupId, $this->api);
            }

            $this->output->writeln();
            $this->output->success("Backup process completed!");
        } catch (\Exception $e) {
            $this->output->error($e->getMessage());
            $this->output->writeln();
        }
    }

    /**
     * Restore backup locally
     */
    private function restoreBackup($options)
    {
        // Get backup ID
        $backupId = $options['args'][1] ?? null;

        if (!$backupId) {
            $this->output->error("Please specify a backup ID");
            $this->output->writeln("\nUsage: backup restore <backup-id> [--server]");
            $this->output->writeln();
            $this->listBackups($options);
            return;
        }

        $this->header("Restore Backup");

        try {
            // If --server flag, download from server first
            if (isset($options['flags']['server'])) {
                $this->output->info("Downloading backup from server...");
                $this->output->writeln();

                $this->initApi();
                $this->backup->downloadFromServer($backupId, $this->api);

                $this->output->writeln();
            }

            // Show backup details
            $backup = $this->backup->getBackup($backupId);

            $this->output->writeln($this->output->colorize("Backup Details:", 'cyan'));
            $this->output->writeln("  ID: {$backup['id']}");
            $this->output->writeln("  Version: {$backup['version']}");
            $this->output->writeln("  Created: {$backup['created']}");
            $this->output->writeln("  Files: {$backup['fileCount']}");
            $this->output->writeln("  Size: " . $this->backup->formatSize($backup['totalSize']));
            $this->output->writeln();

            // Confirm restore
            if (!isset($options['flags']['yes']) && !isset($options['flags']['y'])) {
                $this->output->warning("⚠ This will overwrite current local files with backup files!");
                $confirm = $this->output->ask("Do you want to continue? (yes/no)", "no");

                if (strtolower($confirm) !== 'yes') {
                    $this->output->info("Restore cancelled");
                    $this->output->writeln();
                    return;
                }
            }

            $this->output->writeln();

            // Restore backup
            $this->backup->restoreLocal($backupId);

            $this->output->writeln();
            $this->output->success("Backup restored successfully!");
        } catch (\Exception $e) {
            $this->output->error($e->getMessage());
            $this->output->writeln();
        }
    }

    /**
     * Restore backup on server (without downloading)
     */
    private function restoreServerBackup($options)
    {
        // Get backup ID
        $backupId = $options['args'][1] ?? null;

        if (!$backupId) {
            $this->output->error("Please specify a backup ID");
            $this->output->writeln("\nUsage: backup restore-server <backup-id> [--from-local]");
            $this->output->writeln("\nOptions:");
            $this->output->writeln("  --from-local    Upload local backup to server first, then restore it there");
            $this->output->writeln();
            return;
        }

        $this->header("Restore Backup on Server");

        try {
            $this->initApi();

            $fromLocal = isset($options['flags']['from-local']);

            if ($fromLocal) {
                // Upload local backup to server and restore it there
                $this->output->writeln($this->output->colorize("Mode: Upload from local and restore on server", 'cyan'));
                $this->output->writeln();

                // Check if backup exists locally
                if (!$this->backup->backupExists($backupId)) {
                    throw new \Exception("Backup not found locally: {$backupId}");
                }

                $backup = $this->backup->getBackup($backupId);

                $this->output->writeln($this->output->colorize("Backup Details:", 'cyan'));
                $this->output->writeln("  ID: {$backup['id']}");
                $this->output->writeln("  Version: {$backup['version']}");
                $this->output->writeln("  Created: {$backup['created']}");
                $this->output->writeln("  Files: {$backup['fileCount']}");
                $this->output->writeln("  Size: " . $this->backup->formatSize($backup['totalSize']));
                $this->output->writeln();

                // Confirm
                if (!isset($options['flags']['yes']) && !isset($options['flags']['y'])) {
                    $this->output->warning("⚠ This will overwrite server files with this backup!");
                    $confirm = $this->output->ask("Do you want to continue? (yes/no)", "no");

                    if (strtolower($confirm) !== 'yes') {
                        $this->output->info("Restore cancelled");
                        $this->output->writeln();
                        return;
                    }
                }

                $this->output->writeln();

                // Upload and restore
                $this->backup->uploadAndRestoreOnServer($backupId, $this->api);
            } else {
                // Restore from backup already on server
                $this->output->writeln($this->output->colorize("Mode: Restore from server backup", 'cyan'));
                $this->output->writeln();

                // Get server backup list to verify it exists
                $this->output->write("Fetching server backup list... ");
                $serverBackups = $this->backup->listServer($this->api);
                $this->output->success("Done");
                $this->output->writeln();

                $backupExists = false;
                $backup = null;
                foreach ($serverBackups as $serverBackup) {
                    if ($serverBackup['id'] === $backupId) {
                        $backupExists = true;
                        $backup = $serverBackup;
                        break;
                    }
                }

                if (!$backupExists) {
                    throw new \Exception("Backup not found on server: {$backupId}");
                }

                $this->output->writeln($this->output->colorize("Backup Details:", 'cyan'));
                $this->output->writeln("  ID: {$backup['id']}");
                $this->output->writeln("  Version: {$backup['version']}");
                $this->output->writeln("  Created: " . date('Y-m-d H:i:s', strtotime($backup['created'])));
                $this->output->writeln("  Files: {$backup['fileCount']}");
                $this->output->writeln("  Size: " . $this->backup->formatSize($backup['size'] ?? $backup['totalSize'] ?? 0));
                $this->output->writeln();

                // Confirm
                if (!isset($options['flags']['yes']) && !isset($options['flags']['y'])) {
                    $this->output->warning("⚠ This will restore the server files from this backup!");
                    $this->output->writeln("   The server will create a safety backup before restoring.");
                    $this->output->writeln();
                    $confirm = $this->output->ask("Do you want to continue? (yes/no)", "no");

                    if (strtolower($confirm) !== 'yes') {
                        $this->output->info("Restore cancelled");
                        $this->output->writeln();
                        return;
                    }
                }

                $this->output->writeln();

                // Restore on server
                $this->backup->restoreOnServer($backupId, $this->api);
            }

            $this->output->writeln();
            $this->output->success("Server restore completed!");
            $this->output->writeln();

        } catch (\Exception $e) {
            $this->output->error($e->getMessage());
            $this->output->writeln();
        }
    }

    /**
     * Sync backup to server
     */
    private function syncBackup($options)
    {
        $this->header("Sync Backup to Server");

        try {
            $this->initApi();

            // If --all flag, sync all backups
            if (isset($options['flags']['all'])) {
                $backups = $this->backup->listLocal();

                if (empty($backups)) {
                    $this->output->info("No local backups found");
                    $this->output->writeln();
                    return;
                }

                $this->output->info("Syncing " . count($backups) . " backup(s) to server...");
                $this->output->writeln();

                $successCount = 0;
                foreach ($backups as $backup) {
                    try {
                        $this->output->info("Uploading {$backup['id']}...");
                        $this->backup->uploadToServer($backup['id'], $this->api);
                        $successCount++;
                        $this->output->writeln();
                    } catch (\Exception $e) {
                        $this->output->error("Failed: " . $e->getMessage());
                        $this->output->writeln();
                    }
                }

                $this->output->success("Synced {$successCount}/" . count($backups) . " backup(s) to server!");
            } else {
                // Sync specific backup
                $backupId = $options['args'][1] ?? null;

                if (!$backupId) {
                    $this->output->error("Please specify a backup ID or use --all flag");
                    $this->output->writeln("\nUsage: backup sync <backup-id>");
                    $this->output->writeln("       backup sync --all");
                    $this->output->writeln();
                    $this->listBackups($options);
                    return;
                }

                $this->backup->uploadToServer($backupId, $this->api);
                $this->output->writeln();
                $this->output->success("Backup synced to server!");
            }

            $this->output->writeln();
        } catch (\Exception $e) {
            $this->output->error($e->getMessage());
            $this->output->writeln();
        }
    }

    /**
     * Pull/Download backup from server
     */
    private function pullBackup($options)
    {
        $this->header("Pull Backup from Server");

        try {
            $this->initApi();

            // If --all flag, pull all backups
            if (isset($options['flags']['all'])) {
                // Get server backups
                $this->output->write("Fetching server backup list... ");
                $serverBackups = $this->backup->listServer($this->api);
                $this->output->success("Done");
                $this->output->writeln();

                if (empty($serverBackups)) {
                    $this->output->info("No backups found on server");
                    $this->output->writeln();
                    return;
                }

                $this->output->info("Found " . count($serverBackups) . " backup(s) on server");
                $this->output->writeln();

                // Confirm pull
                if (!$this->output->confirm("Download all " . count($serverBackups) . " backups from server?", true)) {
                    $this->output->writeln("Pull cancelled.\n");
                    return;
                }

                $this->output->writeln();

                $successCount = 0;
                $skippedCount = 0;

                foreach ($serverBackups as $backup) {
                    $backupId = $backup['id'];

                    try {
                        // Check if already exists locally
                        if ($this->backup->backupExists($backupId)) {
                            $this->output->info("⊘ Skipped (already exists): {$backupId}");
                            $skippedCount++;
                            continue;
                        }

                        $this->output->info("⬇ Downloading {$backupId}...");
                        $this->backup->downloadFromServer($backupId, $this->api);
                        $successCount++;
                        $this->output->writeln();
                    } catch (\Exception $e) {
                        $this->output->error("Failed: " . $e->getMessage());
                        $this->output->writeln();
                    }
                }

                $this->output->success("Downloaded {$successCount} backup(s), Skipped {$skippedCount}!");
            } else {
                // Pull specific backup
                $backupId = $options['args'][1] ?? null;

                if (!$backupId) {
                    $this->output->error("Please specify a backup ID or use --all flag");
                    $this->output->writeln("\nUsage: backup pull <backup-id>");
                    $this->output->writeln("       backup pull --all");
                    $this->output->writeln();
                    return;
                }

                // Check if already exists locally
                if ($this->backup->backupExists($backupId)) {
                    $this->output->warning("Backup already exists locally: {$backupId}");

                    if (!$this->output->confirm("Overwrite existing backup?", false)) {
                        $this->output->writeln("Pull cancelled.\n");
                        return;
                    }

                    $this->output->writeln();
                }

                $this->backup->downloadFromServer($backupId, $this->api);
                $this->output->writeln();
                $this->output->success("Backup downloaded from server!");
            }

            $this->output->writeln();
        } catch (\Exception $e) {
            $this->output->error($e->getMessage());
            $this->output->writeln();
        }
    }

    /**
     * List backups
     */
    private function listBackups($options)
    {
        $this->header("Local Backups");

        try {
            $backups = $this->backup->listLocal();

            if (empty($backups)) {
                $this->output->info("No backups found");
                $this->output->writeln("\nRun 'backup create' to create your first backup.");
                $this->output->writeln();
                return;
            }

            $this->output->writeln($this->output->colorize("Available backups:", 'cyan'));

            // Prepare table data
            $headers = ['Backup ID', 'Version', 'Created', 'Files', 'Size'];
            $rows = [];

            foreach ($backups as $backup) {
                $rows[] = [
                    $backup['id'],
                    $backup['version'],
                    date('Y-m-d H:i:s', strtotime($backup['created'])),
                    $backup['fileCount'],
                    $this->backup->formatSize($backup['totalSize'])
                ];
            }

            $this->output->table($headers, $rows);

            $this->output->writeln($this->output->colorize("Commands:", 'cyan'));
            $this->output->writeln("  backup restore <id>                 Restore from local backup");
            $this->output->writeln("  backup restore <id> --server        Download and restore from server");
            $this->output->writeln("  backup restore-server <id>          Restore server files from server backup");
            $this->output->writeln("  backup restore-server <id> --from-local  Upload local backup & restore on server");
            $this->output->writeln("  backup sync <id>                    Upload backup to server");
            $this->output->writeln("  backup sync --all                   Upload all backups to server");
            $this->output->writeln("  backup pull <id>                    Download backup from server");
            $this->output->writeln("  backup pull --all                   Download all backups from server");
            $this->output->writeln("  backup delete <id>                  Delete specific backup");
            $this->output->writeln("  backup delete <id> --local          Delete from local only");
            $this->output->writeln("  backup delete <id> --server         Delete from server only");
            $this->output->writeln("  backup delete <id> --both           Delete from both local and server");
            $this->output->writeln("  backup delete --all                 Delete all backups (with confirmation)");
            $this->output->writeln("  backup stats                        Show backup comparison table");
            $this->output->writeln();
        } catch (\Exception $e) {
            $this->output->error($e->getMessage());
            $this->output->writeln();
        }
    }

    /**
     * Delete specific backup or all backups
     */
    private function deleteBackup($options)
    {
        $this->header("Delete Backup");

        try {
            // Check if --all flag is set
            if (isset($options['flags']['all'])) {
                $this->deleteAllBackups($options);
                return;
            }

            // Get backup ID
            $backupId = $options['args'][1] ?? null;

            if (!$backupId) {
                $this->output->error("Please specify a backup ID or use --all flag");
                $this->output->writeln("\nUsage: backup delete <backup-id> [--local|--server|--both]");
                $this->output->writeln("       backup delete --all");
                $this->output->writeln();
                return;
            }

            // Determine location from flags
            $deleteLocal = isset($options['flags']['local']) || isset($options['flags']['both']);
            $deleteServer = isset($options['flags']['server']) || isset($options['flags']['both']);

            // If no location flag specified, ask user
            if (!$deleteLocal && !$deleteServer) {
                $this->output->writeln($this->output->colorize("Delete from:", 'cyan'));
                $this->output->writeln("  1. Local only");
                $this->output->writeln("  2. Server only");
                $this->output->writeln("  3. Both local and server");
                $this->output->writeln();

                $location = $this->output->ask("Select option (1-3)", "1");
                $this->output->writeln();

                $deleteLocal = ($location === '1' || $location === '3');
                $deleteServer = ($location === '2' || $location === '3');
            }

            // Check if backup exists
            $backupExists = false;
            $backup = null;

            if ($deleteLocal && $this->backup->backupExists($backupId)) {
                $backupExists = true;
                $backup = $this->backup->getBackup($backupId);
            }

            if (!$backupExists && $deleteServer) {
                // Try to get from server
                $this->initApi();
                try {
                    $serverBackups = $this->backup->listServer($this->api);
                    foreach ($serverBackups as $serverBackup) {
                        if ($serverBackup['id'] === $backupId) {
                            $backupExists = true;
                            $backup = $serverBackup;
                            break;
                        }
                    }
                } catch (\Exception $e) {
                    // Ignore
                }
            }

            if (!$backupExists) {
                $this->output->error("Backup not found: {$backupId}");
                $this->output->writeln();
                return;
            }

            // Show backup details
            $this->output->writeln($this->output->colorize("Backup Details:", 'cyan'));
            $this->output->writeln("  ID: " . ($backup['id'] ?? $backupId));
            $this->output->writeln("  Version: " . ($backup['version'] ?? 'N/A'));
            $this->output->writeln("  Created: " . date('Y-m-d H:i:s', strtotime($backup['created'] ?? 'now')));
            $this->output->writeln("  Files: " . ($backup['fileCount'] ?? 'N/A'));
            $this->output->writeln("  Size: " . $this->backup->formatSize($backup['totalSize'] ?? $backup['size'] ?? 0));
            $this->output->writeln();

            // Two-step confirmation
            $this->output->warning("⚠ WARNING: This will permanently delete the backup!");
            $this->output->writeln();

            // Step 1: Are you sure?
            if (!$this->output->confirm("Are you sure you want to delete this backup?", false)) {
                $this->output->info("Deletion cancelled");
                $this->output->writeln();
                return;
            }

            // Step 2: Type "DELETE" to confirm
            $this->output->writeln();
            $this->output->writeln($this->output->colorize("To confirm, type DELETE in capital letters:", 'red'));
            $confirmation = $this->output->ask("Type DELETE to confirm", "");

            if ($confirmation !== 'DELETE') {
                $this->output->info("Deletion cancelled (incorrect confirmation)");
                $this->output->writeln();
                return;
            }

            $this->output->writeln();

            $deletedFrom = [];

            // Delete from local
            if ($deleteLocal && $this->backup->backupExists($backupId)) {
                $this->output->write("Deleting local backup... ");
                $this->backup->deleteLocal($backupId);
                $this->output->success("Done");
                $deletedFrom[] = 'local';
            }

            // Delete from server
            if ($deleteServer) {
                $this->initApi();
                $this->output->write("Deleting server backup... ");
                try {
                    $this->backup->deleteFromServer($backupId, $this->api);
                    $this->output->success("Done");
                    $deletedFrom[] = 'server';
                } catch (\Exception $e) {
                    $this->output->error("Failed: " . $e->getMessage());
                }
            }

            $this->output->writeln();
            $this->output->success("Backup deleted from: " . implode(' and ', $deletedFrom));
            $this->output->writeln();

        } catch (\Exception $e) {
            $this->output->error($e->getMessage());
            $this->output->writeln();
        }
    }

    /**
     * Delete all backups
     */
    private function deleteAllBackups($options)
    {
        try {
            // Get local and server backups
            $localBackups = $this->backup->listLocal();

            $this->initApi();
            $this->output->write("Fetching server backups... ");
            try {
                $serverBackups = $this->backup->listServer($this->api);
                $this->output->success("Done");
            } catch (\Exception $e) {
                $this->output->warning("Failed");
                $serverBackups = [];
            }

            $this->output->writeln();

            if (empty($localBackups) && empty($serverBackups)) {
                $this->output->info("No backups found to delete");
                $this->output->writeln();
                return;
            }

            // Create combined list
            $allBackupIds = [];
            foreach ($localBackups as $backup) {
                $allBackupIds[$backup['id']] = true;
            }
            foreach ($serverBackups as $backup) {
                $allBackupIds[$backup['id']] = true;
            }

            $totalBackups = count($allBackupIds);
            $localCount = count($localBackups);
            $serverCount = count($serverBackups);

            $this->output->writeln($this->output->colorize("Found backups:", 'yellow'));
            $this->output->writeln("  Total unique: {$totalBackups}");
            $this->output->writeln("  Local: {$localCount}");
            $this->output->writeln("  Server: {$serverCount}");
            $this->output->writeln();

            // Ask where to delete from
            $this->output->writeln($this->output->colorize("Delete from:", 'cyan'));
            $this->output->writeln("  1. Local only");
            $this->output->writeln("  2. Server only");
            $this->output->writeln("  3. Both local and server");
            $this->output->writeln();

            $location = $this->output->ask("Select option (1-3)", "1");
            $this->output->writeln();

            $deleteLocal = ($location === '1' || $location === '3');
            $deleteServer = ($location === '2' || $location === '3');

            // Two-step confirmation
            $this->output->warning("⚠ DANGER: This will permanently delete ALL backups!");
            $this->output->writeln();

            // Step 1: Are you sure?
            if (!$this->output->confirm("Are you ABSOLUTELY SURE you want to delete all backups?", false)) {
                $this->output->info("Deletion cancelled");
                $this->output->writeln();
                return;
            }

            // Step 2: Type "DELETE" to confirm
            $this->output->writeln();
            $this->output->writeln($this->output->colorize("To confirm, type DELETE in capital letters:", 'red'));
            $confirmation = $this->output->ask("Type DELETE to confirm", "");

            if ($confirmation !== 'DELETE') {
                $this->output->info("Deletion cancelled (incorrect confirmation)");
                $this->output->writeln();
                return;
            }

            $this->output->writeln();

            $deletedCount = 0;
            $failedCount = 0;

            foreach (array_keys($allBackupIds) as $backupId) {
                try {
                    // Delete from local
                    if ($deleteLocal && $this->backup->backupExists($backupId)) {
                        $this->backup->deleteLocal($backupId);
                        $this->output->writeln("  ✓ Deleted local: {$backupId}");
                        $deletedCount++;
                    }

                    // Delete from server
                    if ($deleteServer) {
                        try {
                            $this->backup->deleteFromServer($backupId, $this->api);
                            $this->output->writeln("  ✓ Deleted server: {$backupId}");
                            $deletedCount++;
                        } catch (\Exception $e) {
                            $this->output->writeln("  ✗ Failed server delete: {$backupId}");
                            $failedCount++;
                        }
                    }
                } catch (\Exception $e) {
                    $this->output->writeln("  ✗ Failed: {$backupId}");
                    $failedCount++;
                }
            }

            $this->output->writeln();

            $locationText = $location === '1' ? 'local' : ($location === '2' ? 'server' : 'local and server');
            $this->output->success("Deleted {$deletedCount} backup(s) from {$locationText}");

            if ($failedCount > 0) {
                $this->output->warning("Failed to delete {$failedCount} backup(s)");
            }

            $this->output->writeln();

        } catch (\Exception $e) {
            $this->output->error($e->getMessage());
            $this->output->writeln();
        }
    }

    /**
     * Clean all backups (alias for delete --all)
     */
    private function cleanBackups($options)
    {
        // Set --all flag and redirect to deleteAllBackups
        $options['flags']['all'] = true;
        $this->deleteAllBackups($options);
    }

    /**
     * Show backup statistics
     */
    private function showStats($options)
    {
        $this->header("Backup Statistics");

        try {
            $this->initApi();

            // Get local backups
            $localBackups = $this->backup->listLocal();

            // Get server backups
            $this->output->write("Fetching server backups... ");
            try {
                $serverBackups = $this->backup->listServer($this->api);
                $this->output->success("Done");
            } catch (\Exception $e) {
                $this->output->warning("Failed: " . $e->getMessage());
                $serverBackups = [];
            }

            $this->output->writeln();

            if (empty($localBackups) && empty($serverBackups)) {
                $this->output->info("No backups found locally or on server");
                $this->output->writeln();
                return;
            }

            // Create a combined list of all backup IDs
            $allBackupIds = [];
            foreach ($localBackups as $backup) {
                $allBackupIds[$backup['id']] = true;
            }
            foreach ($serverBackups as $backup) {
                $allBackupIds[$backup['id']] = true;
            }

            // Index backups by ID for easy lookup
            $localIndex = [];
            foreach ($localBackups as $backup) {
                $localIndex[$backup['id']] = $backup;
            }

            $serverIndex = [];
            foreach ($serverBackups as $backup) {
                $serverIndex[$backup['id']] = $backup;
            }

            // Build comparison table
            $headers = ['Backup ID', 'Version', 'Files', 'Size', 'Created', 'Location'];
            $rows = [];

            foreach (array_keys($allBackupIds) as $backupId) {
                $hasLocal = isset($localIndex[$backupId]);
                $hasServer = isset($serverIndex[$backupId]);

                $backup = $hasLocal ? $localIndex[$backupId] : $serverIndex[$backupId];

                // Determine location
                if ($hasLocal && $hasServer) {
                    $location = $this->output->colorize('Both', 'green');
                } elseif ($hasLocal) {
                    $location = $this->output->colorize('Local Only', 'yellow');
                } else {
                    $location = $this->output->colorize('Server Only', 'cyan');
                }

                $rows[] = [
                    $backupId,
                    $backup['version'] ?? 'N/A',
                    $backup['fileCount'] ?? 0,
                    $this->backup->formatSize($backup['totalSize'] ?? $backup['size'] ?? 0),
                    date('Y-m-d H:i:s', strtotime($backup['created'])),
                    $location
                ];
            }

            $this->output->writeln($this->output->colorize("Backup Comparison:", 'cyan'));
            $this->output->table($headers, $rows);

            // Summary stats
            $localCount = count($localBackups);
            $serverCount = count($serverBackups);
            $totalBackups = count($allBackupIds);

            $localSize = 0;
            foreach ($localBackups as $backup) {
                $localSize += $backup['totalSize'] ?? 0;
            }

            $serverSize = 0;
            foreach ($serverBackups as $backup) {
                $serverSize += $backup['size'] ?? 0;
            }

            $this->output->writeln();
            $this->output->writeln($this->output->colorize("Summary:", 'yellow'));
            $this->output->writeln("  Total Unique Backups: {$totalBackups}");
            $this->output->writeln("  Local: {$localCount} backups (" . $this->backup->formatSize($localSize) . ")");
            $this->output->writeln("  Server: {$serverCount} backups (" . $this->backup->formatSize($serverSize) . ")");
            $this->output->writeln();

        } catch (\Exception $e) {
            $this->output->error($e->getMessage());
            $this->output->writeln();
        }
    }
}
