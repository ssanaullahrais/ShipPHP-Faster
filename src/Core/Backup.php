<?php

namespace ShipPHP\Core;

use ShipPHP\Helpers\Output;

/**
 * Backup Manager with Version Tracking
 * Handles local and server backups with semantic versioning
 */
class Backup
{
    private $config;
    private $output;
    private $backupDir;
    private $versionsFile;

    public function __construct(Config $config, Output $output)
    {
        $this->config = $config;
        $this->output = $output;
        $this->backupDir = WORKING_DIR . '/backup';
        $this->versionsFile = $this->backupDir . '/.versions.json';
    }

    /**
     * Get next version number
     */
    private function getNextVersion()
    {
        if (!file_exists($this->versionsFile)) {
            return 'v2.1.0';
        }

        $versions = json_decode(file_get_contents($this->versionsFile), true);
        if (empty($versions)) {
            return 'v2.1.0';
        }

        // Get the latest version
        $latestVersion = end($versions)['version'];

        // Parse version (v2.1.0 -> [2, 1, 0])
        $parts = explode('.', substr($latestVersion, 1)); // Remove 'v' prefix
        $major = intval($parts[0]);
        $minor = intval($parts[1]);
        $patch = intval($parts[2]);

        // Increment patch version
        $patch++;

        return "v{$major}.{$minor}.{$patch}";
    }

    /**
     * Save version to tracking file
     */
    private function saveVersion($backupId, $version, $fileCount, $totalSize)
    {
        $versions = [];
        if (file_exists($this->versionsFile)) {
            $versions = json_decode(file_get_contents($this->versionsFile), true) ?: [];
        }

        $versions[] = [
            'id' => $backupId,
            'version' => $version,
            'created' => date('c'),
            'fileCount' => $fileCount,
            'totalSize' => $totalSize
        ];

        file_put_contents($this->versionsFile, json_encode($versions, JSON_PRETTY_PRINT));
    }

    /**
     * Get all versions
     */
    public function getVersions()
    {
        if (!file_exists($this->versionsFile)) {
            return [];
        }

        return json_decode(file_get_contents($this->versionsFile), true) ?: [];
    }

    /**
     * Create local backup (FULL backup of all project files)
     *
     * @param string|null $label Optional label like 'before-pull' or 'before-push'
     * @param array|null $specificFiles Array of specific files to backup (overrides full backup)
     * @param array|null $currentFiles Fresh scanned file state to use for hashing
     */
    public function createLocal($label = null, $specificFiles = null, $currentFiles = null)
    {
        // Create backup directory if it doesn't exist
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }

        // Get next version
        $version = $this->getNextVersion();
        $timestamp = date('Y-m-d-His');
        $backupId = "{$timestamp}-{$version}";

        // Add label to backup ID if provided
        if ($label) {
            $backupId = "{$timestamp}-{$label}-{$version}";
        }

        $this->output->info("Creating local backup: {$backupId}");

        // Create backup directory
        $backupPath = $this->backupDir . '/' . $backupId;
        if (!mkdir($backupPath, 0755, true)) {
            throw new \Exception("Failed to create backup directory");
        }

        // Determine which files to backup
        if ($specificFiles !== null) {
            // Use specific files provided (for before-push/pull backups)
            $files = $specificFiles;
        } else {
            // FULL BACKUP: Get ALL files (respecting .gitignore and .ignore)
            $ignorePatterns = $this->config->get('ignore', []);
            $files = $this->scanDirectory(WORKING_DIR, $ignorePatterns);
        }

        if (empty($files)) {
            // No files to backup - clean up and return null
            if (is_dir($backupPath)) {
                rmdir($backupPath);
            }
            return null;
        }

        $copiedCount = 0;
        $totalSize = 0;
        $fileList = [];

        foreach ($files as $file) {
            $source = WORKING_DIR . '/' . $file;

            // Skip if file doesn't exist
            if (!file_exists($source)) {
                continue;
            }

            $dest = $backupPath . '/' . $file;
            $destDir = dirname($dest);

            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }

            if (copy($source, $dest)) {
                $copiedCount++;
                $size = filesize($source);
                $totalSize += $size;

                // Use hash from currentFiles if provided, otherwise calculate
                $hash = null;
                if ($currentFiles !== null && isset($currentFiles[$file])) {
                    $fileData = $currentFiles[$file];
                    $hash = is_array($fileData) ? $fileData['hash'] : $fileData;
                }

                if (!$hash) {
                    $hash = hash_file('sha256', $source);
                }

                $fileList[$file] = [
                    'hash' => $hash,
                    'size' => $size
                ];
            }
        }

        // Create manifest
        $manifest = [
            'id' => $backupId,
            'version' => $version,
            'label' => $label,
            'created' => date('c'),
            'fileCount' => $copiedCount,
            'totalSize' => $totalSize,
            'files' => $fileList
        ];

        file_put_contents($backupPath . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

        // Save version to tracking
        $this->saveVersion($backupId, $version, $copiedCount, $totalSize);

        // Update last backup time in state (via State dependency injection needed)
        // For now, store in backup metadata
        file_put_contents($this->backupDir . '/.last_backup', time());

        $this->output->success("Backup created: {$backupId} ({$copiedCount} files, " . $this->formatSize($totalSize) . ")");

        return $backupId;
    }

    /**
     * Get files that changed since last backup (incremental)
     */
    private function getChangedFilesSinceLastBackup($allFiles)
    {
        $lastBackupTime = $this->getLastBackupTime();

        // If no previous backup, backup all files
        if ($lastBackupTime === null) {
            return $allFiles;
        }

        $changedFiles = [];

        foreach ($allFiles as $file) {
            $filePath = WORKING_DIR . '/' . $file;

            if (!file_exists($filePath)) {
                continue;
            }

            // Check if file was modified after last backup
            $fileMtime = filemtime($filePath);

            if ($fileMtime > $lastBackupTime) {
                $changedFiles[] = $file;
            }
        }

        return $changedFiles;
    }

    /**
     * Get last backup timestamp
     */
    private function getLastBackupTime()
    {
        $lastBackupFile = $this->backupDir . '/.last_backup';

        if (!file_exists($lastBackupFile)) {
            return null;
        }

        return (int)file_get_contents($lastBackupFile);
    }

    /**
     * Scan directory for files
     */
    private function scanDirectory($dir, $ignorePatterns)
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $relativePath = str_replace($dir . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $relativePath = str_replace('\\', '/', $relativePath);

            // Skip if matches ignore patterns
            if ($this->shouldIgnore($relativePath, $ignorePatterns)) {
                continue;
            }

            $files[] = $relativePath;
        }

        return $files;
    }

    /**
     * Check if file should be ignored
     */
    private function shouldIgnore($file, $patterns)
    {
        // HARDCODED: Always exclude directories/files starting with "shipphp"
        if (preg_match('#(^|/)shipphp(/|$)#i', $file)) {
            return true;
        }

        // HARDCODED: Always exclude common directories/files that should never be backed up
        $hardcodedExclusions = [
            'node_modules', 'vendor', '.git', '.svn', '.hg',
            '.shipphp', 'backup', '.shipphp-backups',
            '.gitignore', '.ignore'
        ];

        foreach ($hardcodedExclusions as $exclusion) {
            // For files (starts with .), match exact filename
            if (strpos($exclusion, '.') === 0 && strpos($exclusion, '/') === false) {
                if (basename($file) === $exclusion) {
                    return true;
                }
            }
            // For directories, match path segment
            elseif (preg_match('#(^|/)' . preg_quote($exclusion, '#') . '(/|$)#i', $file)) {
                return true;
            }
        }

        // Check user-defined patterns
        foreach ($patterns as $pattern) {
            // Convert glob pattern to regex
            $regex = $this->globToRegex($pattern);
            if (preg_match($regex, $file)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert glob pattern to regex
     */
    private function globToRegex($pattern)
    {
        $pattern = str_replace('\\', '/', $pattern);
        $pattern = preg_quote($pattern, '/');
        $pattern = str_replace('\*', '.*', $pattern);
        $pattern = str_replace('\?', '.', $pattern);
        return '/^' . $pattern . '$/i';
    }

    /**
     * List all local backups
     */
    public function listLocal()
    {
        if (!is_dir($this->backupDir)) {
            return [];
        }

        $backups = [];
        $dirs = scandir($this->backupDir, SCANDIR_SORT_DESCENDING);

        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..' || $dir === '.versions.json' || $dir === '.last_backup') {
                continue;
            }

            $backupPath = $this->backupDir . '/' . $dir;
            if (!is_dir($backupPath)) {
                continue;
            }

            $manifestPath = $backupPath . '/manifest.json';
            if (file_exists($manifestPath)) {
                $manifest = json_decode(file_get_contents($manifestPath), true);
                $backups[] = $manifest;
            }
        }

        return $backups;
    }

    /**
     * Get backup by ID
     */
    public function getBackup($backupId)
    {
        $backupPath = $this->backupDir . '/' . $backupId;

        if (!is_dir($backupPath)) {
            throw new \Exception("Backup not found: {$backupId}");
        }

        $manifestPath = $backupPath . '/manifest.json';
        if (!file_exists($manifestPath)) {
            throw new \Exception("Backup manifest not found");
        }

        return json_decode(file_get_contents($manifestPath), true);
    }

    /**
     * Check if backup exists locally
     */
    public function backupExists($backupId)
    {
        $backupPath = $this->backupDir . '/' . $backupId;
        return is_dir($backupPath) && file_exists($backupPath . '/manifest.json');
    }

    /**
     * Delete local backup
     */
    public function deleteLocal($backupId)
    {
        $backupPath = $this->backupDir . '/' . $backupId;

        if (!is_dir($backupPath)) {
            throw new \Exception("Backup not found: {$backupId}");
        }

        // Recursively delete backup directory
        $this->deleteDirectory($backupPath);

        // Remove from version tracking
        $this->removeVersion($backupId);

        return true;
    }

    /**
     * Delete backup from server
     */
    public function deleteFromServer($backupId, ApiClient $api)
    {
        try {
            $result = $api->deleteBackup($backupId);
            return $result;
        } catch (\Exception $e) {
            throw new \Exception("Failed to delete backup from server: " . $e->getMessage());
        }
    }

    /**
     * Restore backup on server (without downloading)
     * This restores a backup that's already on the server directly on the server
     */
    public function restoreOnServer($backupId, ApiClient $api)
    {
        try {
            $result = $api->restoreBackup($backupId);

            $this->output->success("Backup restored on server successfully!");
            $this->output->writeln("The server files have been restored from backup: {$backupId}");

            return $result;
        } catch (\Exception $e) {
            throw new \Exception("Failed to restore backup on server: " . $e->getMessage());
        }
    }

    /**
     * Upload local backup to server and restore it there
     * This uploads a backup from local, then restores it on the server
     */
    public function uploadAndRestoreOnServer($backupId, ApiClient $api)
    {
        // First, upload the backup to server
        $this->output->info("Uploading backup to server...");
        $this->uploadToServer($backupId, $api);

        $this->output->writeln();

        // Then, restore it on the server
        $this->output->info("Restoring backup on server...");
        $this->output->writeln();

        return $this->restoreOnServer($backupId, $api);
    }

    /**
     * Recursively delete directory
     */
    private function deleteDirectory($dir)
    {
        if (!is_dir($dir)) {
            return false;
        }

        $items = scandir($dir);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        return rmdir($dir);
    }

    /**
     * Remove version from tracking file
     */
    private function removeVersion($backupId)
    {
        if (!file_exists($this->versionsFile)) {
            return;
        }

        $versions = json_decode(file_get_contents($this->versionsFile), true) ?: [];

        // Filter out the deleted backup
        $versions = array_filter($versions, function($version) use ($backupId) {
            return $version['id'] !== $backupId;
        });

        // Re-index array
        $versions = array_values($versions);

        file_put_contents($this->versionsFile, json_encode($versions, JSON_PRETTY_PRINT));
    }

    /**
     * Restore from local backup
     */
    public function restoreLocal($backupId)
    {
        $backupPath = $this->backupDir . '/' . $backupId;

        if (!is_dir($backupPath)) {
            throw new \Exception("Backup not found: {$backupId}");
        }

        $manifest = $this->getBackup($backupId);
        $files = $manifest['files'] ?? [];

        $this->output->info("Restoring backup: {$backupId}");
        $this->output->writeln();

        $restoredCount = 0;
        $total = count($files);

        $this->output->writeln($this->output->colorize("Restoring {$total} files:", 'cyan'));

        foreach (array_keys($files) as $file) {
            $source = $backupPath . '/' . $file;
            $dest = WORKING_DIR . '/' . $file;

            if (!file_exists($source)) {
                $this->output->writeln("  ✗ {$file} " . $this->output->colorize("(not found in backup)", 'red'));
                continue;
            }

            $destDir = dirname($dest);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }

            if (copy($source, $dest)) {
                $restoredCount++;
                $this->output->writeln("  ✓ {$file} " . $this->output->colorize("[restored]", 'green'));
            } else {
                $this->output->writeln("  ✗ {$file} " . $this->output->colorize("[failed]", 'red'));
            }
        }

        $this->output->writeln();
        $this->output->success("Restored {$restoredCount}/{$total} files");

        return $restoredCount;
    }

    /**
     * Format backup size
     */
    public function formatSize($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Upload backup to server
     */
    public function uploadToServer($backupId, ApiClient $api)
    {
        $backupPath = $this->backupDir . '/' . $backupId;

        if (!is_dir($backupPath)) {
            throw new \Exception("Backup not found: {$backupId}");
        }

        $manifest = $this->getBackup($backupId);
        $files = array_keys($manifest['files'] ?? []);
        $total = count($files) + 1; // +1 for manifest

        $this->output->info("Uploading backup to server: {$backupId}");
        $this->output->writeln("Total files: {$total}");
        $this->output->writeln();

        $uploadedCount = 0;
        $failedCount = 0;
        $startTime = microtime(true);

        foreach ($files as $file) {
            $source = $backupPath . '/' . $file;

            if (!file_exists($source)) {
                $failedCount++;
                continue;
            }

            $remotePath = "backup/{$backupId}/{$file}";

            try {
                // Use uploadFile() method instead of request()
                $api->uploadFile($source, $remotePath);
                $uploadedCount++;
            } catch (\Exception $e) {
                $failedCount++;
            }

            // Show progress
            $this->showProgress($uploadedCount, $failedCount, $total, $startTime);
        }

        // Upload manifest
        try {
            $manifestPath = $backupPath . '/manifest.json';
            $api->uploadFile($manifestPath, "backup/{$backupId}/manifest.json");
            $uploadedCount++;
        } catch (\Exception $e) {
            $failedCount++;
        }

        // Final progress
        $this->showProgress($uploadedCount, $failedCount, $total, $startTime, true);

        $this->output->writeln();
        $elapsed = round(microtime(true) - $startTime, 2);
        $this->output->success("Completed in {$elapsed}s - Uploaded: {$uploadedCount}, Failed: {$failedCount}");

        return $uploadedCount;
    }

    /**
     * Get local backup statistics
     */
    public function getLocalStats()
    {
        $backups = $this->listLocal();
        $totalSize = 0;
        $oldest = null;
        $newest = null;

        foreach ($backups as $backup) {
            $totalSize += $backup['totalSize'];

            $created = strtotime($backup['created']);

            if ($oldest === null || $created < $oldest) {
                $oldest = $created;
            }

            if ($newest === null || $created > $newest) {
                $newest = $created;
            }
        }

        return [
            'count' => count($backups),
            'totalSize' => $totalSize,
            'oldest' => $oldest ? date('Y-m-d H:i:s', $oldest) : null,
            'newest' => $newest ? date('Y-m-d H:i:s', $newest) : null
        ];
    }

    /**
     * List server backups
     */
    public function listServer(ApiClient $api)
    {
        try {
            // Request backup list from server
            $result = $api->request('backups', []);

            if (!$result['success']) {
                throw new \Exception("Failed to fetch server backups");
            }

            return $result['backups'] ?? [];
        } catch (\Exception $e) {
            throw new \Exception("Unable to fetch server backups: " . $e->getMessage());
        }
    }

    /**
     * Get server backup statistics
     */
    public function getServerStats(ApiClient $api)
    {
        try {
            // Request backup list from server
            $result = $api->request('backups', []);

            if (!$result['success']) {
                throw new \Exception("Failed to fetch server backups");
            }

            $backups = $result['backups'] ?? [];
            $totalSize = 0;

            foreach ($backups as $backup) {
                $totalSize += $backup['size'] ?? 0;
            }

            return [
                'count' => count($backups),
                'totalSize' => $totalSize
            ];
        } catch (\Exception $e) {
            throw new \Exception("Unable to fetch server backup stats: " . $e->getMessage());
        }
    }

    /**
     * Download backup from server
     */
    public function downloadFromServer($backupId, ApiClient $api)
    {
        $this->output->info("Downloading backup from server: {$backupId}");
        $this->output->writeln();

        // Create local backup directory
        $backupPath = $this->backupDir . '/' . $backupId;
        if (!is_dir($backupPath)) {
            mkdir($backupPath, 0755, true);
        }

        // First, download the manifest from server
        try {
            $manifestLocal = $backupPath . '/manifest.json';
            $api->downloadFile("backup/{$backupId}/manifest.json", $manifestLocal);

            $manifest = json_decode(file_get_contents($manifestLocal), true);
            $files = array_keys($manifest['files'] ?? []);
            $total = count($files) + 1; // +1 for manifest already downloaded

            $this->output->writeln("Total files: {$total}");
            $this->output->writeln();

            $downloadedCount = 1; // Manifest already downloaded
            $failedCount = 0;
            $startTime = microtime(true);

            foreach ($files as $file) {
                try {
                    $dest = $backupPath . '/' . $file;
                    $destDir = dirname($dest);

                    if (!is_dir($destDir)) {
                        mkdir($destDir, 0755, true);
                    }

                    // Use downloadFile() method instead of request()
                    $api->downloadFile("backup/{$backupId}/{$file}", $dest);
                    $downloadedCount++;
                } catch (\Exception $e) {
                    $failedCount++;
                }

                // Show progress
                $this->showProgress($downloadedCount, $failedCount, $total, $startTime);
            }

            // Final progress
            $this->showProgress($downloadedCount, $failedCount, $total, $startTime, true);

            $this->output->writeln();
            $elapsed = round(microtime(true) - $startTime, 2);
            $this->output->success("Completed in {$elapsed}s - Downloaded: {$downloadedCount}, Failed: {$failedCount}");

            return $downloadedCount;
        } catch (\Exception $e) {
            throw new \Exception("Failed to download backup: " . $e->getMessage());
        }
    }

    /**
     * Show upload/download progress with time estimates
     */
    private function showProgress($completed, $failed, $total, $startTime, $final = false)
    {
        $elapsed = microtime(true) - $startTime;
        $processed = $completed + $failed;
        $remaining = $total - $processed;
        $percentage = $total > 0 ? round(($processed / $total) * 100, 1) : 0;

        // Calculate speed and ETA
        $speed = $processed > 0 ? $processed / $elapsed : 0;
        $eta = $speed > 0 && $remaining > 0 ? $remaining / $speed : 0;

        // Format time
        $elapsedFormatted = $this->formatTime($elapsed);
        $etaFormatted = $this->formatTime($eta);

        // Build progress line
        $progressBar = $this->buildProgressBar($percentage);

        $line = sprintf(
            "\r%s %d%% | %d/%d files | ✓ %d",
            $progressBar,
            $percentage,
            $processed,
            $total,
            $completed
        );

        if ($failed > 0) {
            $line .= $this->output->colorize(" ✗ {$failed}", 'red');
        }

        $line .= sprintf(
            " | Time: %s | Remaining: %s | Speed: %.1f/s",
            $elapsedFormatted,
            $remaining > 0 ? $etaFormatted : '0s',
            $speed
        );

        // Clear line and write progress
        echo "\r" . str_repeat(' ', 120) . $line;

        // Add newline on final update
        if ($final) {
            echo PHP_EOL;
        }
    }

    /**
     * Build a simple progress bar
     */
    private function buildProgressBar($percentage, $width = 20)
    {
        $filled = round(($percentage / 100) * $width);
        $empty = $width - $filled;

        $bar = '[' .
               $this->output->colorize(str_repeat('=', $filled), 'green') .
               str_repeat('-', $empty) .
               ']';

        return $bar;
    }

    /**
     * Format time in human-readable format
     */
    private function formatTime($seconds)
    {
        if ($seconds < 1) {
            return '0s';
        }

        // Convert to integer first to avoid float deprecation warnings
        $totalSeconds = (int)round($seconds);
        $hours = intdiv($totalSeconds, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $secs = $totalSeconds % 60;

        $parts = [];
        if ($hours > 0) {
            $parts[] = "{$hours}h";
        }
        if ($minutes > 0) {
            $parts[] = "{$minutes}m";
        }
        if ($secs > 0 || empty($parts)) {
            $parts[] = "{$secs}s";
        }

        return implode(' ', $parts);
    }
}
