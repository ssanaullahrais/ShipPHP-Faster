<?php

namespace ShipPHP\Core;

use ShipPHP\Security\Security;

/**
 * State Manager
 * Tracks file changes using SHA256 hashes
 */
class State
{
    private $stateDir;
    private $statePath;
    private $state;

    public function __construct($workingDir = null)
    {
        $workingDir = $workingDir ?: WORKING_DIR;
        $this->stateDir = $workingDir . '/.shipphp';
        $this->statePath = $this->stateDir . '/state.json';
        $this->ensureStateDir();
        $this->load();
    }

    /**
     * Ensure .shipphp directory exists
     */
    private function ensureStateDir()
    {
        if (!is_dir($this->stateDir)) {
            if (!mkdir($this->stateDir, 0755, true)) {
                throw new \Exception("Failed to create .shipphp directory");
            }

            // Create .gitignore to exclude state files
            $gitignore = $this->stateDir . '/.gitignore';
            file_put_contents($gitignore, "*\n!.gitignore\n");
        }
    }

    /**
     * Load state from file
     */
    public function load()
    {
        if (!file_exists($this->statePath)) {
            $this->state = [
                'version' => '2.0.0',
                'lastSync' => null,
                'lastPush' => null,
                'lastPull' => null,
                'lastBackup' => null,
                'firstPullCompleted' => false,
                'files' => [],
                'serverFiles' => []
            ];
            return;
        }

        $json = file_get_contents($this->statePath);
        $this->state = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid state.json: " . json_last_error_msg());
        }
    }

    /**
     * Save state to file
     */
    public function save()
    {
        $json = json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (file_put_contents($this->statePath, $json) === false) {
            throw new \Exception("Failed to write state.json");
        }

        return true;
    }

    /**
     * Get file hash from state
     */
    public function getFileHash($path)
    {
        $fileData = $this->state['files'][$path] ?? null;
        if (is_array($fileData)) {
            return $fileData['hash'] ?? null;
        }
        // Backward compatibility with old format
        return $fileData;
    }

    /**
     * Set file hash in state (with mtime and size)
     */
    public function setFileHash($path, $hash, $mtime = null, $size = null)
    {
        if ($mtime === null) {
            $filePath = WORKING_DIR . '/' . $path;
            if (file_exists($filePath)) {
                $mtime = filemtime($filePath);
                $size = filesize($filePath);
            }
        }

        $this->state['files'][$path] = [
            'hash' => $hash,
            'mtime' => $mtime,
            'size' => $size
        ];
        return $this;
    }

    /**
     * Remove file from state
     */
    public function removeFile($path)
    {
        if (isset($this->state['files'][$path])) {
            unset($this->state['files'][$path]);
        }
        return $this;
    }

    /**
     * Get all tracked files
     */
    public function getFiles()
    {
        return $this->state['files'];
    }

    /**
     * Set server file hash
     */
    public function setServerFileHash($path, $hash)
    {
        $this->state['serverFiles'][$path] = $hash;
        return $this;
    }

    /**
     * Get server file hash
     */
    public function getServerFileHash($path)
    {
        return $this->state['serverFiles'][$path] ?? null;
    }

    /**
     * Get all server files
     */
    public function getServerFiles()
    {
        return $this->state['serverFiles'];
    }

    /**
     * Update server files list
     */
    public function updateServerFiles($files)
    {
        $this->state['serverFiles'] = $files;
        return $this;
    }

    /**
     * Update last sync time
     */
    public function updateLastSync()
    {
        $this->state['lastSync'] = date('c');
        return $this;
    }

    /**
     * Update last push time
     */
    public function updateLastPush()
    {
        $this->state['lastPush'] = date('c');
        $this->updateLastSync();
        return $this;
    }

    /**
     * Update last pull time
     */
    public function updateLastPull()
    {
        $this->state['lastPull'] = date('c');
        $this->state['firstPullCompleted'] = true;
        $this->updateLastSync();
        return $this;
    }

    /**
     * Check if first pull has been completed
     */
    public function hasCompletedFirstPull()
    {
        return $this->state['firstPullCompleted'] ?? false;
    }

    /**
     * Update last backup time
     */
    public function updateLastBackup()
    {
        $this->state['lastBackup'] = date('c');
        return $this;
    }

    /**
     * Get last backup time
     */
    public function getLastBackup()
    {
        return $this->state['lastBackup'] ?? null;
    }

    /**
     * Get last sync time
     */
    public function getLastSync()
    {
        return $this->state['lastSync'];
    }

    /**
     * Scan local directory and detect changes
     * Uses modification time for fast detection, only calculates hash if needed
     */
    public function scanLocalFiles($workingDir, $ignore = [])
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($workingDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $relativePath = str_replace($workingDir . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $relativePath = str_replace('\\', '/', $relativePath);

            // HARDCODED: Always exclude directories/files starting with "shipphp"
            if (preg_match('#(^|/)shipphp(/|$)#i', $relativePath)) {
                continue;
            }

            // HARDCODED: Always exclude common directories
            $hardcodedExclusions = [
                'node_modules', 'vendor', '.git', '.svn', '.hg',
                '.shipphp', 'backup', '.shipphp-backups'
            ];
            $shouldIgnore = false;
            foreach ($hardcodedExclusions as $exclusion) {
                if (preg_match('#(^|/)' . preg_quote($exclusion, '#') . '(/|$)#i', $relativePath)) {
                    $shouldIgnore = true;
                    break;
                }
            }

            if ($shouldIgnore) {
                continue;
            }

            // Skip user-defined ignore patterns
            foreach ($ignore as $pattern) {
                if ($this->matchesPattern($relativePath, $pattern)) {
                    $shouldIgnore = true;
                    break;
                }
            }

            if ($shouldIgnore) {
                continue;
            }

            // Get modification time (faster than hash!)
            $mtime = filemtime($file->getPathname());

            // Get stored info
            $stored = $this->state['files'][$relativePath] ?? null;

            // If mtime unchanged, use stored hash (much faster!)
            if ($stored && isset($stored['mtime']) && $stored['mtime'] == $mtime) {
                $hash = $stored['hash'];
            } else {
                // Mtime changed or new file - calculate hash
                try {
                    $hash = Security::hashFile($file->getPathname());
                } catch (\Exception $e) {
                    // Skip files that can't be hashed
                    continue;
                }
            }

            // Store with both hash and mtime
            $files[$relativePath] = [
                'hash' => $hash,
                'mtime' => $mtime,
                'size' => filesize($file->getPathname())
            ];
        }

        return $files;
    }

    /**
     * Match file against pattern
     */
    private function matchesPattern($path, $pattern)
    {
        // Convert glob to regex
        $regex = '#^' . str_replace(['*', '?'], ['.*', '.'], preg_quote($pattern, '#')) . '$#';

        if (preg_match($regex, $path)) {
            return true;
        }

        // Check if path starts with pattern (for directories)
        if (strpos($path, rtrim($pattern, '/') . '/') === 0) {
            return true;
        }

        return false;
    }

    /**
     * Compare local files with state to find changes
     */
    public function getChanges($workingDir, $ignore = [])
    {
        $currentFiles = $this->scanLocalFiles($workingDir, $ignore);
        $trackedFiles = $this->getFiles();

        $changes = [
            'new' => [],
            'modified' => [],
            'deleted' => [],
            'unchanged' => []
        ];

        // Find new and modified files
        foreach ($currentFiles as $path => $fileData) {
            $currentHash = is_array($fileData) ? $fileData['hash'] : $fileData;
            $trackedData = $trackedFiles[$path] ?? null;
            $trackedHash = null;

            if ($trackedData) {
                $trackedHash = is_array($trackedData) ? $trackedData['hash'] : $trackedData;
            }

            if (!$trackedHash) {
                $changes['new'][] = $path;
            } elseif ($trackedHash !== $currentHash) {
                $changes['modified'][] = $path;
            } else {
                $changes['unchanged'][] = $path;
            }
        }

        // Find deleted files
        foreach ($trackedFiles as $path => $fileData) {
            if (!isset($currentFiles[$path])) {
                $changes['deleted'][] = $path;
            }
        }

        return $changes;
    }

    /**
     * Compare with server files
     * @param array $serverFiles Server file hashes
     * @param array|null $currentFiles Current local files (if null, uses stored state)
     */
    public function compareWithServer($serverFiles, $currentFiles = null)
    {
        // Use current files if provided, otherwise use stored state
        $localFiles = $currentFiles !== null ? $currentFiles : $this->getFiles();

        $diff = [
            'toUpload' => [],      // New or modified locally
            'toDownload' => [],    // New or modified on server
            'toDelete' => [],      // Deleted locally, exists on server
            'conflicts' => []      // Modified both locally and on server
        ];

        // Check local files
        foreach ($localFiles as $path => $localData) {
            $localHash = is_array($localData) ? $localData['hash'] : $localData;
            $serverHash = $serverFiles[$path] ?? null;
            $lastKnownServerHash = $this->getServerFileHash($path);

            if ($serverHash === null) {
                // File doesn't exist on server
                $diff['toUpload'][] = $path;
            } elseif ($serverHash !== $localHash) {
                // File exists but different
                if ($lastKnownServerHash && $serverHash !== $lastKnownServerHash && $localHash !== $lastKnownServerHash) {
                    // Modified both locally and on server - CONFLICT
                    $diff['conflicts'][] = $path;
                } elseif ($serverHash === $lastKnownServerHash) {
                    // Server unchanged, local modified
                    $diff['toUpload'][] = $path;
                } else {
                    // Local unchanged, server modified
                    $diff['toDownload'][] = $path;
                }
            }
        }

        // Check server files
        foreach ($serverFiles as $path => $serverHash) {
            if (!isset($localFiles[$path])) {
                $lastKnownLocalHash = $this->getFileHash($path);
                if ($lastKnownLocalHash) {
                    // File was deleted locally
                    $diff['toDelete'][] = $path;
                } else {
                    // New file on server
                    $diff['toDownload'][] = $path;
                }
            }
        }

        return $diff;
    }

    /**
     * Update file hashes from current state
     */
    public function updateFromScan($workingDir, $ignore = [])
    {
        $currentFiles = $this->scanLocalFiles($workingDir, $ignore);
        $this->state['files'] = $currentFiles;
        return $this;
    }

    /**
     * Get state directory
     */
    public function getStateDir()
    {
        return $this->stateDir;
    }

    /**
     * Reset state (clear all tracking)
     */
    public function reset()
    {
        $this->state = [
            'version' => '2.0.0',
            'lastSync' => null,
            'lastPush' => null,
            'lastPull' => null,
            'lastBackup' => null,
            'firstPullCompleted' => false,
            'files' => [],
            'serverFiles' => []
        ];
        return $this;
    }
}

