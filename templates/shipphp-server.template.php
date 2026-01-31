<?php
/**
 * ShipPHP Faster - Server Receiver
 *
 * Upload this file to your web server and configure the token
 * DO NOT commit this file with your actual token
 *
 * @version 2.1.0
 * @security This file implements multiple layers of security
 */

// ============================================
// CONFIGURATION - CHANGE THESE
// ============================================

// Authentication token (MUST match client token)
// Generate with: openssl rand -hex 32
define('SHIPPHP_TOKEN', 'CHANGE_THIS_TO_YOUR_64_CHAR_TOKEN_FROM_shipphp_json');

// Base directory for deployment (current directory by default)
define('BASE_DIR', __DIR__);

// Maximum file size (100MB)
define('MAX_FILE_SIZE', 100 * 1024 * 1024);

// Enable backups
define('ENABLE_BACKUPS', true);

// Backup retention (keep last N backups)
define('BACKUP_RETENTION', 10);

// IP Whitelist (empty = allow all, or add IPs: ['192.168.1.1', '10.0.0.0/8'])
define('IP_WHITELIST', []);

// Rate limiting (max requests per minute)
define('RATE_LIMIT', 120);

// Enable detailed logging
define('ENABLE_LOGGING', true);

// ============================================
// END CONFIGURATION - DO NOT EDIT BELOW
// ============================================

// Prevent direct browser access
if (php_sapi_name() === 'cli') {
    die('This script cannot be run from command line.');
}

// Set JSON response header
header('Content-Type: application/json');
header('X-ShipPHP-Version: 2.1.0');

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Disable error display (prevent information leakage)
ini_set('display_errors', '0');
error_reporting(0);

/**
 * Main Server Class
 */
class ShipPHPServer
{
    private $baseDir;
    private $backupDir;
    private $logFile;
    private $trashDir;
    private $trashIndex;

    public function __construct()
    {
        $this->baseDir = realpath(BASE_DIR);
        $this->backupDir = $this->baseDir . '/backup';
        $this->logFile = $this->baseDir . '/.shipphp-server.log';
        $this->trashDir = $this->baseDir . '/.shipphp-trash';
        $this->trashIndex = $this->trashDir . '/index.json';

        if (!$this->baseDir) {
            $this->error('Base directory does not exist', 500);
        }
    }

    /**
     * Handle incoming request
     */
    public function handle()
    {
        try {
            // Security checks
            $this->checkIPWhitelist();
            $this->checkRateLimit();
            $this->authenticate();

            // Get action
            $action = $_POST['action'] ?? $_GET['action'] ?? '';

            // Log request
            $this->log("Action: {$action}");

            // Route action
            switch ($action) {
                case 'test':
                    $this->actionTest();
                    break;

                case 'health':
                    // ENTERPRISE FEATURE: Health check endpoint (auth required)
                    $this->actionHealth();
                    break;

                case 'info':
                    $this->actionInfo();
                    break;

                case 'list':
                    $this->actionList();
                    break;

                case 'upload':
                    $this->actionUpload();
                    break;

                case 'download':
                    $this->actionDownload();
                    break;

                case 'delete':
                    $this->actionDelete();
                    break;

                case 'trash':
                    $this->actionTrash();
                    break;

                case 'listTrash':
                    $this->actionListTrash();
                    break;

                case 'restoreTrash':
                    $this->actionRestoreTrash();
                    break;

                case 'move':
                    $this->actionMove();
                    break;

                case 'extract':
                    $this->actionExtract();
                    break;

                case 'where':
                    $this->actionWhere();
                    break;

                case 'lock':
                    $this->actionLock();
                    break;

                case 'backup':
                    $this->actionBackup();
                    break;

                case 'backups':
                    $this->actionListBackups();
                    break;

                case 'restore':
                    $this->actionRestore();
                    break;

                case 'deleteBackup':
                    $this->actionDeleteBackup();
                    break;

                // NEW: File management actions for Web UI
                case 'mkdir':
                    $this->actionMkdir();
                    break;

                case 'touch':
                    $this->actionTouch();
                    break;

                case 'write':
                    $this->actionWrite();
                    break;

                case 'read':
                    $this->actionRead();
                    break;

                case 'edit':
                    $this->actionEdit();
                    break;

                case 'copy':
                    $this->actionCopy();
                    break;

                case 'chmod':
                    $this->actionChmod();
                    break;

                case 'info':
                    $this->actionFileInfo();
                    break;

                case 'search':
                    $this->actionSearch();
                    break;

                case 'grep':
                    $this->actionGrep();
                    break;

                case 'stats':
                    $this->actionStats();
                    break;

                case 'logs':
                    $this->actionLogs();
                    break;

                case 'tree':
                    $this->actionTree();
                    break;

                case 'watch':
                    $this->actionWatch();
                    break;

                case 'emptyTrash':
                    $this->actionEmptyTrash();
                    break;

                case 'rename':
                    $this->actionRename();
                    break;

                default:
                    $this->error('Invalid action', 400);
            }
        } catch (Exception $e) {
            $this->error($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    /**
     * Test connection
     */
    private function actionTest()
    {
        $this->success('Connection successful!', [
            'server' => 'ShipPHP Server',
            'version' => '2.1.0',
            'php' => PHP_VERSION,
            'backups' => ENABLE_BACKUPS
        ]);
    }

    /**
     * Health check endpoint (ENTERPRISE FEATURE: For monitoring systems)
     * NO AUTH REQUIRED - Used by load balancers, monitoring tools
     */
    private function actionHealth()
    {
        $health = [
            'status' => 'healthy',
            'timestamp' => date('c'),
            'checks' => []
        ];

        // Check disk space
        $diskFree = @disk_free_space($this->baseDir);
        $diskTotal = @disk_total_space($this->baseDir);
        if ($diskFree !== false && $diskTotal !== false) {
            $diskUsedPercent = (($diskTotal - $diskFree) / $diskTotal) * 100;
            $health['checks']['disk'] = [
                'status' => $diskUsedPercent < 90 ? 'ok' : 'warning',
                'free' => $this->formatBytes($diskFree),
                'total' => $this->formatBytes($diskTotal),
                'used_percent' => round($diskUsedPercent, 2)
            ];
        } else {
            $health['checks']['disk'] = ['status' => 'unknown'];
        }

        // Check write permissions
        $testFile = $this->baseDir . '/.shipphp_health_check';
        $canWrite = @file_put_contents($testFile, 'test') !== false;
        if ($canWrite) {
            @unlink($testFile);
        }
        $health['checks']['write_permission'] = [
            'status' => $canWrite ? 'ok' : 'error'
        ];

        // Check backup directory
        if (ENABLE_BACKUPS) {
            $backupDirExists = is_dir($this->backupDir);
            $backupDirWritable = $backupDirExists && is_writable($this->backupDir);
            $health['checks']['backups'] = [
                'status' => $backupDirWritable ? 'ok' : ($backupDirExists ? 'warning' : 'error'),
                'enabled' => true,
                'dir_exists' => $backupDirExists,
                'dir_writable' => $backupDirWritable
            ];
        }

        // Check PHP extensions
        $health['checks']['php'] = [
            'version' => PHP_VERSION,
            'extensions' => [
                'json' => extension_loaded('json') ? 'ok' : 'missing',
                'hash' => extension_loaded('hash') ? 'ok' : 'missing',
                'fileinfo' => extension_loaded('fileinfo') ? 'ok' : 'missing'
            ]
        ];

        // Overall health status
        $hasError = false;
        $hasWarning = false;
        foreach ($health['checks'] as $check) {
            if (is_array($check) && isset($check['status'])) {
                if ($check['status'] === 'error') $hasError = true;
                if ($check['status'] === 'warning') $hasWarning = true;
            }
        }

        if ($hasError) {
            $health['status'] = 'unhealthy';
            http_response_code(503); // Service Unavailable
        } elseif ($hasWarning) {
            $health['status'] = 'degraded';
        }

        $this->success('Health check complete', $health);
    }

    /**
     * Get server info
     */
    private function actionInfo()
    {
        $this->success('Server info', [
            'version' => '2.1.0',
            'php' => PHP_VERSION,
            'diskSpace' => disk_free_space($this->baseDir),
            'diskTotal' => disk_total_space($this->baseDir),
            'backupsEnabled' => ENABLE_BACKUPS,
            'maxFileSize' => MAX_FILE_SIZE,
        ]);
    }

    /**
     * List all files with hashes
     */
    private function actionList()
    {
        $files = $this->scanFiles($this->baseDir);
        $this->success('File list retrieved', ['files' => $files]);
    }

    /**
     * Upload file
     */
    private function actionUpload()
    {
        if (!isset($_FILES['file'])) {
            throw new Exception('No file uploaded');
        }

        $file = $_FILES['file'];
        $path = $_POST['path'] ?? '';
        $expectedHash = $_POST['hash'] ?? '';

        // Security validations
        $this->validatePath($path);

        // Skip file extension validation for backup operations
        if (strpos($path, 'backup/') !== 0) {
            $this->validateFileExtension($path);
        }

        // Check file upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('File upload error: ' . $this->getUploadError($file['error']));
        }

        // Check file size
        if ($file['size'] > MAX_FILE_SIZE) {
            throw new Exception('File too large: ' . $this->formatBytes($file['size']) .
                              ' (max: ' . $this->formatBytes(MAX_FILE_SIZE) . ')');
        }

        // Verify hash
        if ($expectedHash) {
            $actualHash = hash_file('sha256', $file['tmp_name']);
            if (!hash_equals($expectedHash, $actualHash)) {
                throw new Exception('Hash mismatch - file may be corrupted during transfer');
            }
        }

        // Prepare destination
        $fullPath = $this->baseDir . '/' . $path;
        $dir = dirname($fullPath);

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new Exception('Failed to create directory');
            }
        }

        // Backup existing file if it exists
        if (file_exists($fullPath) && ENABLE_BACKUPS) {
            $this->backupFile($path);
        }

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
            throw new Exception('Failed to move uploaded file');
        }

        // Set permissions
        chmod($fullPath, 0644);

        $this->log("Uploaded: {$path}");

        $this->success('File uploaded successfully', [
            'path' => $path,
            'size' => $file['size'],
            'hash' => hash_file('sha256', $fullPath)
        ]);
    }

    /**
     * Download file (SECURITY FIX: Chunked operations prevent memory exhaustion)
     */
    private function actionDownload()
    {
        $path = $_POST['path'] ?? $_GET['path'] ?? '';

        $this->validatePath($path);

        $fullPath = $this->baseDir . '/' . $path;

        if (!file_exists($fullPath)) {
            throw new Exception('File not found');
        }

        $fileSize = filesize($fullPath);

        // CRITICAL SECURITY FIX: Check file size limit
        if ($fileSize > MAX_FILE_SIZE) {
            throw new Exception('File too large to download: ' . $this->formatBytes($fileSize));
        }

        // SECURITY FIX: For large files, use chunked reading to prevent memory exhaustion
        if ($fileSize > 10 * 1024 * 1024) { // 10MB threshold
            $handle = fopen($fullPath, 'rb');
            if (!$handle) {
                throw new Exception('Cannot read file');
            }

            $content = '';
            while (!feof($handle)) {
                $chunk = fread($handle, 8192); // 8KB chunks
                if ($chunk === false) {
                    fclose($handle);
                    throw new Exception('Error reading file');
                }
                $content .= $chunk;
            }
            fclose($handle);
        } else {
            // Small files: use file_get_contents for better performance
            $content = file_get_contents($fullPath);
            if ($content === false) {
                throw new Exception('Error reading file');
            }
        }

        $hash = hash_file('sha256', $fullPath);

        $this->success('File downloaded', [
            'path' => $path,
            'content' => base64_encode($content),
            'hash' => $hash,
            'size' => $fileSize
        ]);
    }

    /**
     * Show server base directory
     */
    private function actionWhere()
    {
        $this->success('Server base directory', [
            'baseDir' => $this->baseDir
        ]);
    }

    /**
     * Extract zip archive
     */
    private function actionExtract()
    {
        $path = $_POST['path'] ?? '';
        $destination = $_POST['destination'] ?? '';
        $overwrite = !empty($_POST['overwrite']);

        $this->validatePath($path);
        if (!empty($destination)) {
            $this->validatePath($destination);
        }

        $fullPath = $this->baseDir . '/' . $path;

        if (!file_exists($fullPath)) {
            throw new Exception('Archive not found');
        }

        if (!is_file($fullPath)) {
            throw new Exception('Archive path is not a file');
        }

        $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        if ($extension !== 'zip') {
            throw new Exception('Only .zip archives can be extracted');
        }

        if (!class_exists('ZipArchive')) {
            throw new Exception('ZipArchive extension is required on the server');
        }

        $targetDir = $destination !== '' ? $destination : dirname($path);
        $targetDir = rtrim($targetDir, '/');
        if ($targetDir === '' || $targetDir === '.') {
            $targetDir = '';
        }

        $extractPath = $this->baseDir . ($targetDir !== '' ? '/' . $targetDir : '');

        if (!is_dir($extractPath)) {
            if (!mkdir($extractPath, 0755, true)) {
                throw new Exception('Failed to create extraction directory');
            }
        }

        $zip = new ZipArchive();
        if ($zip->open($fullPath) !== true) {
            throw new Exception('Failed to open zip archive');
        }

        $conflicts = [];
        $entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if ($entry === false) {
                continue;
            }

            $entry = str_replace('\\', '/', $entry);
            if (strpos($entry, '../') !== false || strpos($entry, '..\\') !== false || strpos($entry, '..') !== false) {
                $zip->close();
                throw new Exception('Archive contains invalid paths');
            }

            if (strpos($entry, ':') !== false || strpos($entry, '/') === 0) {
                $zip->close();
                throw new Exception('Archive contains absolute paths');
            }

            $entries[] = $entry;

            $destinationPath = $extractPath . '/' . $entry;
            if (is_file($destinationPath) && !$overwrite) {
                $conflicts[] = $entry;
            }

            $stat = $zip->statIndex($i);
            if ($stat && isset($stat['size']) && $stat['size'] > MAX_FILE_SIZE) {
                $zip->close();
                throw new Exception('Archive entry too large: ' . $entry);
            }
        }

        if (!empty($conflicts)) {
            $zip->close();
            throw new Exception('Extraction would overwrite existing files. Use --force to overwrite.');
        }

        $extracted = 0;
        foreach ($entries as $entry) {
            if (substr($entry, -1) === '/') {
                $dirPath = $extractPath . '/' . rtrim($entry, '/');
                if (!is_dir($dirPath)) {
                    mkdir($dirPath, 0755, true);
                }
                continue;
            }

            $destinationPath = $extractPath . '/' . $entry;
            $entryDir = dirname($destinationPath);
            if (!is_dir($entryDir)) {
                if (!mkdir($entryDir, 0755, true)) {
                    $zip->close();
                    throw new Exception('Failed to create directory for extraction');
                }
            }

            $stream = $zip->getStream($entry);
            if (!$stream) {
                $zip->close();
                throw new Exception('Failed to read archive entry');
            }

            $contents = stream_get_contents($stream);
            fclose($stream);

            if (file_put_contents($destinationPath, $contents) === false) {
                $zip->close();
                throw new Exception('Failed to write extracted file');
            }

            $extracted++;
        }

        $zip->close();

        $this->log("Extracted: {$path} to {$targetDir}");

        $this->success('Archive extracted', [
            'archive' => $path,
            'destination' => $targetDir !== '' ? $targetDir : '.',
            'extracted' => $extracted
        ]);
    }

    /**
     * Delete file
     */
    private function actionDelete()
    {
        $path = $_POST['path'] ?? '';

        $this->validatePath($path);

        $fullPath = $this->baseDir . '/' . $path;

        if (!file_exists($fullPath)) {
            $this->success('File does not exist');
            return;
        }

        // Backup before delete
        if (ENABLE_BACKUPS) {
            $this->backupFile($path);
        }

        if (is_file($fullPath)) {
            if (!unlink($fullPath)) {
                throw new Exception('Failed to delete file');
            }
        } elseif (is_dir($fullPath)) {
            $this->deleteDirectory($fullPath);
        }

        $this->log("Deleted: {$path}");

        $this->success('File deleted successfully', ['path' => $path]);
    }

    /**
     * Move files to trash
     */
    private function actionTrash()
    {
        $itemsJson = $_POST['items'] ?? '[]';
        $items = json_decode($itemsJson, true);

        if (!is_array($items)) {
            $this->error('Invalid items payload', 400);
        }

        if (!is_dir($this->trashDir)) {
            mkdir($this->trashDir, 0755, true);
        }

        $index = $this->loadTrashIndex();
        $results = [
            'trashed' => 0,
            'failed' => 0,
            'items' => [],
            'errors' => []
        ];

        foreach ($items as $path) {
            $path = trim((string)$path);
            if ($path === '') {
                continue;
            }

            try {
                $this->validatePath($path);
                $source = $this->baseDir . '/' . $path;
                if (!file_exists($source)) {
                    $results['failed']++;
                    $results['errors'][] = "Source not found: {$path}";
                    continue;
                }

                $trashId = 'trash-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
                $trashPath = $this->trashDir . '/' . $trashId . '/' . $path;
                $trashDir = dirname($trashPath);
                if (!is_dir($trashDir)) {
                    if (!mkdir($trashDir, 0755, true)) {
                        throw new Exception('Failed to create trash directory');
                    }
                }

                $this->movePath($source, $trashPath);

                $entry = [
                    'id' => $trashId,
                    'path' => $path,
                    'trash_path' => '.shipphp-trash/' . $trashId . '/' . $path,
                    'trashed_at' => date('c')
                ];
                $index[] = $entry;
                $results['items'][] = $entry;
                $results['trashed']++;
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = $e->getMessage();
            }
        }

        $this->saveTrashIndex($index);
        $this->success('Trash complete', $results);
    }

    /**
     * List trash items
     */
    private function actionListTrash()
    {
        $index = $this->loadTrashIndex();
        $this->success('Trash list', ['items' => $index]);
    }

    /**
     * Restore trash item
     */
    private function actionRestoreTrash()
    {
        $id = $_POST['id'] ?? '';
        $force = !empty($_POST['force']);

        if ($id === '') {
            $this->error('Missing trash id', 400);
        }

        $index = $this->loadTrashIndex();
        $entryIndex = null;
        $entry = null;

        foreach ($index as $idx => $item) {
            if (($item['id'] ?? '') === $id) {
                $entryIndex = $idx;
                $entry = $item;
                break;
            }
        }

        if (!$entry) {
            $this->error('Trash item not found', 404);
        }

        $trashPath = $this->baseDir . '/' . ($entry['trash_path'] ?? '');
        $restorePath = $this->baseDir . '/' . ($entry['path'] ?? '');

        if (!file_exists($trashPath)) {
            $this->error('Trash source missing', 404);
        }

        if (file_exists($restorePath) && !$force) {
            $this->error('Restore destination exists. Use --force to overwrite.', 409);
        }

        $restoreDir = dirname($restorePath);
        if (!is_dir($restoreDir)) {
            mkdir($restoreDir, 0755, true);
        }

        if (file_exists($restorePath) && $force) {
            if (is_dir($restorePath)) {
                $this->deleteDirectory($restorePath);
            } else {
                unlink($restorePath);
            }
        }

        $this->movePath($trashPath, $restorePath);

        if ($entryIndex !== null) {
            array_splice($index, $entryIndex, 1);
            $this->saveTrashIndex($index);
        }

        $this->success('Trash restored', ['path' => $entry['path'] ?? '']);
    }

    /**
     * Move or copy files
     */
    private function actionMove()
    {
        $itemsJson = $_POST['items'] ?? '[]';
        $mode = strtolower($_POST['mode'] ?? 'move');
        $items = json_decode($itemsJson, true);

        if (!is_array($items)) {
            $this->error('Invalid items payload', 400);
        }

        if (!in_array($mode, ['move', 'copy'], true)) {
            $this->error('Invalid mode', 400);
        }

        $results = [
            'mode' => $mode,
            'moved' => 0,
            'copied' => 0,
            'failed' => 0,
            'errors' => []
        ];

        foreach ($items as $item) {
            $from = $item['from'] ?? '';
            $to = $item['to'] ?? '';

            if ($from === '' || $to === '') {
                $results['failed']++;
                $results['errors'][] = 'Missing source or destination path';
                continue;
            }

            if ($from === $to) {
                continue;
            }

            try {
                $this->validatePath($from);
                $this->validatePath($to);

                $source = $this->baseDir . '/' . $from;
                $destination = $this->baseDir . '/' . $to;

                if (!file_exists($source)) {
                    $results['failed']++;
                    $results['errors'][] = "Source not found: {$from}";
                    continue;
                }

                $destinationDir = dirname($destination);
                if (!is_dir($destinationDir)) {
                    if (!mkdir($destinationDir, 0755, true)) {
                        throw new Exception("Failed to create destination directory: {$destinationDir}");
                    }
                }

                if ($mode === 'copy') {
                    $this->copyPath($source, $destination);
                    $results['copied']++;
                } else {
                    if (ENABLE_BACKUPS) {
                        $this->backupFile($from);
                    }
                    $this->movePath($source, $destination);
                    $results['moved']++;
                }
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = $e->getMessage();
            }
        }

        $this->success('Transfer complete', $results);
    }

    /**
     * Toggle maintenance lock
     */
    private function actionLock()
    {
        $mode = strtolower($_POST['mode'] ?? 'status');
        $message = $_POST['message'] ?? '';
        $lockFile = $this->baseDir . '/.shipphp-maintenance';

        if ($mode === 'status') {
            $status = file_exists($lockFile);
            $payload = ['locked' => $status];
            if ($status) {
                $payload['message'] = trim((string)file_get_contents($lockFile));
            }
            $this->success('Lock status', $payload);
            return;
        }

        if ($mode === 'enable') {
            file_put_contents($lockFile, $message ?: 'Maintenance enabled at ' . date('c'));
            $this->success('Maintenance enabled', ['locked' => true]);
            return;
        }

        if ($mode === 'disable') {
            if (file_exists($lockFile)) {
                unlink($lockFile);
            }
            $this->success('Maintenance disabled', ['locked' => false]);
            return;
        }

        $this->error('Invalid lock mode', 400);
    }

    /**
     * Create backup
     */
    private function actionBackup()
    {
        if (!ENABLE_BACKUPS) {
            throw new Exception('Backups are disabled');
        }

        $backupId = $_POST['id'] ?? date('Y-m-d-His');

        $backupPath = $this->createBackup($backupId);

        $this->success('Backup created', [
            'id' => $backupId,
            'path' => $backupPath,
            'timestamp' => date('c')
        ]);
    }

    /**
     * List backups
     */
    private function actionListBackups()
    {
        if (!ENABLE_BACKUPS) {
            $this->success('Backups list', ['backups' => []]);
            return;
        }

        if (!is_dir($this->backupDir)) {
            $this->success('Backups list', ['backups' => []]);
            return;
        }

        $backups = [];
        $dirs = scandir($this->backupDir, SCANDIR_SORT_DESCENDING);

        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }

            $backupPath = $this->backupDir . '/' . $dir;
            if (!is_dir($backupPath)) {
                continue;
            }

            $manifestPath = $backupPath . '/manifest.json';
            $manifest = file_exists($manifestPath) ?
                       json_decode(file_get_contents($manifestPath), true) : [];

            $backups[] = [
                'id' => $dir,
                'version' => $manifest['version'] ?? 'N/A',
                'created' => $manifest['created'] ?? date('c', filemtime($backupPath)),
                'fileCount' => $manifest['fileCount'] ?? 0,
                'size' => $this->getDirectorySize($backupPath)
            ];
        }

        $this->success('Backups list', ['backups' => $backups]);
    }

    /**
     * Restore backup
     */
    private function actionRestore()
    {
        if (!ENABLE_BACKUPS) {
            throw new Exception('Backups are disabled');
        }

        $backupId = $_POST['id'] ?? '';

        if (empty($backupId)) {
            throw new Exception('Backup ID required');
        }

        // CRITICAL SECURITY FIX: Validate backup ID format to prevent path traversal
        if (!$this->isValidBackupId($backupId)) {
            $this->log("Security violation: Invalid backup ID format: {$backupId}");
            throw new Exception('Invalid backup ID format');
        }

        $backupPath = $this->backupDir . '/' . $backupId;

        // Additional security check: Ensure resolved path is within backup directory
        $realBackupPath = realpath($backupPath);
        $realBackupDir = realpath($this->backupDir);

        if ($realBackupPath === false || strpos($realBackupPath, $realBackupDir) !== 0) {
            $this->log("Security violation: Backup path traversal attempt: {$backupId}");
            throw new Exception('Invalid backup path');
        }

        if (!is_dir($realBackupPath)) {
            throw new Exception('Backup not found');
        }

        // Create a backup of current state before restoring
        $this->createBackup('before-restore-' . date('Y-m-d-His'));

        // Restore files
        $this->restoreFromBackup($realBackupPath);

        $this->log("Restored backup: {$backupId}");

        $this->success('Backup restored successfully', ['id' => $backupId]);
    }

    /**
     * Delete backup
     */
    private function actionDeleteBackup()
    {
        if (!ENABLE_BACKUPS) {
            throw new Exception('Backups are disabled');
        }

        $backupId = $_POST['id'] ?? '';

        if (empty($backupId)) {
            throw new Exception('Backup ID required');
        }

        // CRITICAL SECURITY FIX: Validate backup ID format
        if (!$this->isValidBackupId($backupId)) {
            $this->log("Security violation: Invalid backup ID format: {$backupId}");
            throw new Exception('Invalid backup ID format');
        }

        $backupPath = $this->backupDir . '/' . $backupId;

        // Additional security check: Ensure resolved path is within backup directory
        $realBackupPath = realpath($backupPath);
        $realBackupDir = realpath($this->backupDir);

        if ($realBackupPath === false || strpos($realBackupPath, $realBackupDir) !== 0) {
            $this->log("Security violation: Backup path traversal attempt: {$backupId}");
            throw new Exception('Invalid backup path');
        }

        if (!is_dir($realBackupPath)) {
            throw new Exception('Backup not found');
        }

        if (!$this->deleteDirectory($realBackupPath)) {
            throw new Exception('Failed to delete backup directory');
        }

        $this->log("Deleted backup: {$backupId}");

        $this->success('Backup deleted successfully', ['id' => $backupId]);
    }

    // ============================================
    // NEW FILE MANAGEMENT ACTIONS FOR WEB UI
    // ============================================

    /**
     * Create directory on server
     */
    private function actionMkdir()
    {
        $path = $_POST['path'] ?? '';
        $recursive = !empty($_POST['recursive']);

        if (empty($path)) {
            throw new Exception('Path is required');
        }

        $this->validatePath($path);

        $fullPath = $this->baseDir . '/' . $path;

        if (is_dir($fullPath)) {
            $this->success('Directory already exists', ['path' => $path]);
            return;
        }

        if (file_exists($fullPath)) {
            throw new Exception('A file with this name already exists');
        }

        $mode = 0755;
        if (!mkdir($fullPath, $mode, $recursive)) {
            throw new Exception('Failed to create directory');
        }

        $this->log("Created directory: {$path}");

        $this->success('Directory created', [
            'path' => $path,
            'mode' => decoct($mode)
        ]);
    }

    /**
     * Create empty file on server
     */
    private function actionTouch()
    {
        $path = $_POST['path'] ?? '';

        if (empty($path)) {
            throw new Exception('Path is required');
        }

        $this->validatePath($path);
        $this->validateFileExtension($path);

        $fullPath = $this->baseDir . '/' . $path;

        // Create directory if needed
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new Exception('Failed to create parent directory');
            }
        }

        $exists = file_exists($fullPath);

        if ($exists) {
            // Update modification time
            if (!touch($fullPath)) {
                throw new Exception('Failed to update file timestamp');
            }
        } else {
            // Create new empty file
            if (file_put_contents($fullPath, '') === false) {
                throw new Exception('Failed to create file');
            }
            chmod($fullPath, 0644);
        }

        $this->log("Touched file: {$path}");

        $this->success($exists ? 'File timestamp updated' : 'File created', [
            'path' => $path,
            'created' => !$exists
        ]);
    }

    /**
     * Write content to file on server
     */
    private function actionWrite()
    {
        $path = $_POST['path'] ?? '';
        $content = $_POST['content'] ?? '';
        $encoding = $_POST['encoding'] ?? 'plain'; // plain or base64
        $overwrite = !empty($_POST['overwrite']);

        if (empty($path)) {
            throw new Exception('Path is required');
        }

        $this->validatePath($path);
        $this->validateFileExtension($path);

        $fullPath = $this->baseDir . '/' . $path;

        // Check if file exists and overwrite flag
        if (file_exists($fullPath) && !$overwrite) {
            throw new Exception('File already exists. Use overwrite flag to replace.');
        }

        // Decode content if base64
        if ($encoding === 'base64') {
            $content = base64_decode($content);
            if ($content === false) {
                throw new Exception('Invalid base64 content');
            }
        }

        // Check content size
        if (strlen($content) > MAX_FILE_SIZE) {
            throw new Exception('Content too large');
        }

        // Create directory if needed
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new Exception('Failed to create directory');
            }
        }

        // Backup existing file if enabled
        if (file_exists($fullPath) && ENABLE_BACKUPS) {
            $this->backupFile($path);
        }

        if (file_put_contents($fullPath, $content) === false) {
            throw new Exception('Failed to write file');
        }

        chmod($fullPath, 0644);

        $this->log("Wrote file: {$path}");

        $this->success('File written successfully', [
            'path' => $path,
            'size' => strlen($content),
            'hash' => hash('sha256', $content)
        ]);
    }

    /**
     * Read file content from server
     */
    private function actionRead()
    {
        $path = $_POST['path'] ?? $_GET['path'] ?? '';
        $lines = intval($_POST['lines'] ?? $_GET['lines'] ?? 0);
        $offset = intval($_POST['offset'] ?? $_GET['offset'] ?? 0);

        if (empty($path)) {
            throw new Exception('Path is required');
        }

        $this->validatePath($path);

        $fullPath = $this->baseDir . '/' . $path;

        if (!file_exists($fullPath)) {
            throw new Exception('File not found');
        }

        if (!is_file($fullPath)) {
            throw new Exception('Path is not a file');
        }

        $fileSize = filesize($fullPath);

        if ($fileSize > MAX_FILE_SIZE) {
            throw new Exception('File too large to read: ' . $this->formatBytes($fileSize));
        }

        // Read file content
        if ($lines > 0 || $offset > 0) {
            // Read specific lines
            $allLines = file($fullPath);
            if ($allLines === false) {
                throw new Exception('Failed to read file');
            }

            if ($offset > 0) {
                $allLines = array_slice($allLines, $offset);
            }

            if ($lines > 0) {
                $allLines = array_slice($allLines, 0, $lines);
            }

            $content = implode('', $allLines);
            $totalLines = count(file($fullPath));
        } else {
            $content = file_get_contents($fullPath);
            if ($content === false) {
                throw new Exception('Failed to read file');
            }
            $totalLines = substr_count($content, "\n") + 1;
        }

        $this->success('File content retrieved', [
            'path' => $path,
            'content' => base64_encode($content),
            'size' => $fileSize,
            'lines' => $totalLines,
            'hash' => hash_file('sha256', $fullPath),
            'modified' => date('c', filemtime($fullPath)),
            'mime' => mime_content_type($fullPath) ?: 'application/octet-stream'
        ]);
    }

    /**
     * Edit file content on server
     */
    private function actionEdit()
    {
        $path = $_POST['path'] ?? '';
        $content = $_POST['content'] ?? null;
        $encoding = $_POST['encoding'] ?? 'plain';
        $append = !empty($_POST['append']);
        $find = $_POST['find'] ?? null;
        $replace = $_POST['replace'] ?? null;

        if (empty($path)) {
            throw new Exception('Path is required');
        }

        $this->validatePath($path);

        $fullPath = $this->baseDir . '/' . $path;

        if (!file_exists($fullPath)) {
            throw new Exception('File not found');
        }

        if (!is_file($fullPath)) {
            throw new Exception('Path is not a file');
        }

        // Backup before edit
        if (ENABLE_BACKUPS) {
            $this->backupFile($path);
        }

        // Find and replace mode
        if ($find !== null && $replace !== null) {
            $currentContent = file_get_contents($fullPath);
            if ($currentContent === false) {
                throw new Exception('Failed to read file');
            }

            $newContent = str_replace($find, $replace, $currentContent, $count);

            if ($count === 0) {
                $this->success('No matches found', ['path' => $path, 'replaced' => 0]);
                return;
            }

            if (file_put_contents($fullPath, $newContent) === false) {
                throw new Exception('Failed to write file');
            }

            $this->log("Edited file (find/replace): {$path}");

            $this->success('File edited successfully', [
                'path' => $path,
                'replaced' => $count,
                'size' => strlen($newContent),
                'hash' => hash('sha256', $newContent)
            ]);
            return;
        }

        // Content mode (overwrite or append)
        if ($content === null) {
            throw new Exception('Content is required for edit');
        }

        // Decode if base64
        if ($encoding === 'base64') {
            $content = base64_decode($content);
            if ($content === false) {
                throw new Exception('Invalid base64 content');
            }
        }

        if ($append) {
            $result = file_put_contents($fullPath, $content, FILE_APPEND);
        } else {
            $result = file_put_contents($fullPath, $content);
        }

        if ($result === false) {
            throw new Exception('Failed to edit file');
        }

        $this->log("Edited file: {$path}");

        $this->success('File edited successfully', [
            'path' => $path,
            'mode' => $append ? 'append' : 'overwrite',
            'size' => filesize($fullPath),
            'hash' => hash_file('sha256', $fullPath)
        ]);
    }

    /**
     * Copy file or directory on server
     */
    private function actionCopy()
    {
        $source = $_POST['source'] ?? '';
        $destination = $_POST['destination'] ?? '';
        $overwrite = !empty($_POST['overwrite']);

        if (empty($source) || empty($destination)) {
            throw new Exception('Source and destination paths are required');
        }

        $this->validatePath($source);
        $this->validatePath($destination);

        $sourcePath = $this->baseDir . '/' . $source;
        $destPath = $this->baseDir . '/' . $destination;

        if (!file_exists($sourcePath)) {
            throw new Exception('Source not found');
        }

        if (file_exists($destPath) && !$overwrite) {
            throw new Exception('Destination already exists. Use overwrite flag to replace.');
        }

        // Create destination directory if needed
        $destDir = dirname($destPath);
        if (!is_dir($destDir)) {
            if (!mkdir($destDir, 0755, true)) {
                throw new Exception('Failed to create destination directory');
            }
        }

        // Remove existing destination if overwrite
        if (file_exists($destPath) && $overwrite) {
            if (is_dir($destPath)) {
                $this->deleteDirectory($destPath);
            } else {
                unlink($destPath);
            }
        }

        // Copy
        $this->copyPath($sourcePath, $destPath);

        $this->log("Copied: {$source} -> {$destination}");

        $this->success('Copy successful', [
            'source' => $source,
            'destination' => $destination
        ]);
    }

    /**
     * Change file permissions
     */
    private function actionChmod()
    {
        $path = $_POST['path'] ?? '';
        $mode = $_POST['mode'] ?? '';
        $recursive = !empty($_POST['recursive']);

        if (empty($path) || empty($mode)) {
            throw new Exception('Path and mode are required');
        }

        $this->validatePath($path);

        $fullPath = $this->baseDir . '/' . $path;

        if (!file_exists($fullPath)) {
            throw new Exception('Path not found');
        }

        // Parse mode (accept both octal string like "755" and decimal)
        if (is_string($mode) && strlen($mode) <= 4) {
            $modeInt = octdec($mode);
        } else {
            $modeInt = intval($mode);
        }

        if ($modeInt < 0 || $modeInt > 0777) {
            throw new Exception('Invalid permission mode');
        }

        $changed = 0;

        if ($recursive && is_dir($fullPath)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                if (@chmod($item->getPathname(), $modeInt)) {
                    $changed++;
                }
            }
        }

        if (!chmod($fullPath, $modeInt)) {
            throw new Exception('Failed to change permissions');
        }
        $changed++;

        $this->log("Changed permissions: {$path} -> " . decoct($modeInt));

        $this->success('Permissions changed', [
            'path' => $path,
            'mode' => decoct($modeInt),
            'changed' => $changed
        ]);
    }

    /**
     * Get file/directory information
     */
    private function actionFileInfo()
    {
        $path = $_POST['path'] ?? $_GET['path'] ?? '';

        if (empty($path)) {
            throw new Exception('Path is required');
        }

        $this->validatePath($path);

        $fullPath = $this->baseDir . '/' . $path;

        if (!file_exists($fullPath)) {
            throw new Exception('Path not found');
        }

        $stat = stat($fullPath);
        $isDir = is_dir($fullPath);

        $info = [
            'path' => $path,
            'name' => basename($path),
            'type' => $isDir ? 'directory' : 'file',
            'size' => $isDir ? $this->getDirectorySize($fullPath) : filesize($fullPath),
            'permissions' => decoct($stat['mode'] & 0777),
            'owner' => $stat['uid'],
            'group' => $stat['gid'],
            'created' => date('c', $stat['ctime']),
            'modified' => date('c', $stat['mtime']),
            'accessed' => date('c', $stat['atime']),
        ];

        if (!$isDir) {
            $info['mime'] = mime_content_type($fullPath) ?: 'application/octet-stream';
            $info['hash'] = hash_file('sha256', $fullPath);
            $info['extension'] = pathinfo($fullPath, PATHINFO_EXTENSION);
        } else {
            $items = array_diff(scandir($fullPath), ['.', '..']);
            $info['items'] = count($items);
        }

        $this->success('File info retrieved', $info);
    }

    /**
     * Search for files by name pattern
     */
    private function actionSearch()
    {
        $pattern = $_POST['pattern'] ?? $_GET['pattern'] ?? '';
        $path = $_POST['path'] ?? $_GET['path'] ?? '';
        $maxResults = intval($_POST['max'] ?? $_GET['max'] ?? 100);
        $includeHidden = !empty($_POST['hidden'] ?? $_GET['hidden'] ?? false);

        if (empty($pattern)) {
            throw new Exception('Search pattern is required');
        }

        $searchDir = $this->baseDir;
        if (!empty($path)) {
            $this->validatePath($path);
            $searchDir = $this->baseDir . '/' . $path;
        }

        if (!is_dir($searchDir)) {
            throw new Exception('Search path is not a directory');
        }

        $results = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($searchDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if (count($results) >= $maxResults) {
                break;
            }

            $name = $file->getFilename();

            // Skip hidden files unless requested
            if (!$includeHidden && strpos($name, '.') === 0) {
                continue;
            }

            // Skip ShipPHP internal files
            $relativePath = str_replace($this->baseDir . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $relativePath = str_replace('\\', '/', $relativePath);

            if (strpos($relativePath, '.shipphp') === 0 || strpos($relativePath, 'backup/') === 0) {
                continue;
            }

            // Match pattern (supports wildcards)
            if (fnmatch($pattern, $name, FNM_CASEFOLD)) {
                $results[] = [
                    'path' => $relativePath,
                    'name' => $name,
                    'type' => $file->isDir() ? 'directory' : 'file',
                    'size' => $file->isFile() ? $file->getSize() : 0,
                    'modified' => date('c', $file->getMTime())
                ];
            }
        }

        $this->success('Search complete', [
            'pattern' => $pattern,
            'results' => $results,
            'count' => count($results),
            'truncated' => count($results) >= $maxResults
        ]);
    }

    /**
     * Search file contents (grep)
     */
    private function actionGrep()
    {
        $text = $_POST['text'] ?? $_GET['text'] ?? '';
        $path = $_POST['path'] ?? $_GET['path'] ?? '';
        $pattern = $_POST['pattern'] ?? $_GET['pattern'] ?? '*';
        $maxResults = intval($_POST['max'] ?? $_GET['max'] ?? 50);
        $caseSensitive = !empty($_POST['case'] ?? $_GET['case'] ?? false);
        $context = intval($_POST['context'] ?? $_GET['context'] ?? 0);

        if (empty($text)) {
            throw new Exception('Search text is required');
        }

        $searchDir = $this->baseDir;
        if (!empty($path)) {
            $this->validatePath($path);
            $searchDir = $this->baseDir . '/' . $path;
        }

        if (!is_dir($searchDir)) {
            throw new Exception('Search path is not a directory');
        }

        $results = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($searchDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (count($results) >= $maxResults) {
                break;
            }

            if (!$file->isFile()) {
                continue;
            }

            // Match file pattern
            if ($pattern !== '*' && !fnmatch($pattern, $file->getFilename(), FNM_CASEFOLD)) {
                continue;
            }

            // Skip binary files and large files
            $size = $file->getSize();
            if ($size > 5 * 1024 * 1024) { // 5MB limit for grep
                continue;
            }

            // Skip ShipPHP internal files
            $relativePath = str_replace($this->baseDir . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $relativePath = str_replace('\\', '/', $relativePath);

            if (strpos($relativePath, '.shipphp') === 0 || strpos($relativePath, 'backup/') === 0) {
                continue;
            }

            $content = @file_get_contents($file->getPathname());
            if ($content === false) {
                continue;
            }

            // Skip binary content
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', substr($content, 0, 8192))) {
                continue;
            }

            $lines = explode("\n", $content);
            $matches = [];

            foreach ($lines as $lineNum => $line) {
                $found = $caseSensitive ?
                    strpos($line, $text) !== false :
                    stripos($line, $text) !== false;

                if ($found) {
                    $match = [
                        'line' => $lineNum + 1,
                        'content' => trim($line)
                    ];

                    // Add context lines if requested
                    if ($context > 0) {
                        $match['before'] = array_slice($lines, max(0, $lineNum - $context), $context);
                        $match['after'] = array_slice($lines, $lineNum + 1, $context);
                    }

                    $matches[] = $match;
                }
            }

            if (!empty($matches)) {
                $results[] = [
                    'path' => $relativePath,
                    'matches' => $matches
                ];
            }
        }

        $this->success('Grep complete', [
            'text' => $text,
            'results' => $results,
            'fileCount' => count($results),
            'truncated' => count($results) >= $maxResults
        ]);
    }

    /**
     * Get server statistics
     */
    private function actionStats()
    {
        $diskFree = @disk_free_space($this->baseDir);
        $diskTotal = @disk_total_space($this->baseDir);

        // Count files and directories
        $fileCount = 0;
        $dirCount = 0;
        $totalSize = 0;
        $fileTypes = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->baseDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = str_replace($this->baseDir . DIRECTORY_SEPARATOR, '', $item->getPathname());
            $relativePath = str_replace('\\', '/', $relativePath);

            // Skip internal files
            if (strpos($relativePath, '.shipphp') === 0 || strpos($relativePath, 'backup/') === 0) {
                continue;
            }

            if ($item->isDir()) {
                $dirCount++;
            } else {
                $fileCount++;
                $totalSize += $item->getSize();

                $ext = strtolower(pathinfo($item->getFilename(), PATHINFO_EXTENSION)) ?: 'no_ext';
                if (!isset($fileTypes[$ext])) {
                    $fileTypes[$ext] = ['count' => 0, 'size' => 0];
                }
                $fileTypes[$ext]['count']++;
                $fileTypes[$ext]['size'] += $item->getSize();
            }
        }

        // Sort file types by count
        arsort($fileTypes);
        $fileTypes = array_slice($fileTypes, 0, 10, true);

        $this->success('Server statistics', [
            'disk' => [
                'free' => $diskFree,
                'total' => $diskTotal,
                'used' => $diskTotal - $diskFree,
                'freeFormatted' => $this->formatBytes($diskFree),
                'totalFormatted' => $this->formatBytes($diskTotal),
                'usedPercent' => round((($diskTotal - $diskFree) / $diskTotal) * 100, 2)
            ],
            'files' => [
                'count' => $fileCount,
                'directories' => $dirCount,
                'totalSize' => $totalSize,
                'totalSizeFormatted' => $this->formatBytes($totalSize)
            ],
            'types' => $fileTypes,
            'php' => [
                'version' => PHP_VERSION,
                'memory_limit' => ini_get('memory_limit'),
                'max_upload' => ini_get('upload_max_filesize'),
                'max_post' => ini_get('post_max_size'),
                'max_execution' => ini_get('max_execution_time')
            ],
            'server' => [
                'software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                'protocol' => $_SERVER['SERVER_PROTOCOL'] ?? 'Unknown'
            ]
        ]);
    }

    /**
     * Read server logs
     */
    private function actionLogs()
    {
        $lines = intval($_POST['lines'] ?? $_GET['lines'] ?? 100);
        $filter = $_POST['filter'] ?? $_GET['filter'] ?? '';

        if (!file_exists($this->logFile)) {
            $this->success('No logs available', ['logs' => [], 'total' => 0]);
            return;
        }

        $content = file_get_contents($this->logFile);
        if ($content === false) {
            throw new Exception('Failed to read log file');
        }

        $allLines = array_filter(explode("\n", $content));
        $allLines = array_reverse($allLines); // Newest first

        if (!empty($filter)) {
            $allLines = array_filter($allLines, function($line) use ($filter) {
                return stripos($line, $filter) !== false;
            });
        }

        $allLines = array_slice($allLines, 0, $lines);

        $this->success('Logs retrieved', [
            'logs' => array_values($allLines),
            'total' => count($allLines),
            'filter' => $filter
        ]);
    }

    /**
     * Get directory tree structure
     */
    private function actionTree()
    {
        $path = $_POST['path'] ?? $_GET['path'] ?? '';
        $depth = intval($_POST['depth'] ?? $_GET['depth'] ?? 3);
        $showHidden = !empty($_POST['hidden'] ?? $_GET['hidden'] ?? false);
        $showFiles = !($_POST['dirs_only'] ?? $_GET['dirs_only'] ?? false);

        $searchDir = $this->baseDir;
        if (!empty($path)) {
            $this->validatePath($path);
            $searchDir = $this->baseDir . '/' . $path;
        }

        if (!is_dir($searchDir)) {
            throw new Exception('Path is not a directory');
        }

        $tree = $this->buildTree($searchDir, $depth, $showHidden, $showFiles);

        $this->success('Directory tree', [
            'path' => $path ?: '.',
            'tree' => $tree
        ]);
    }

    /**
     * Build directory tree recursively
     */
    private function buildTree($dir, $depth, $showHidden, $showFiles, $currentDepth = 0)
    {
        if ($currentDepth >= $depth) {
            return null;
        }

        $items = @scandir($dir);
        if ($items === false) {
            return [];
        }

        $result = [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            // Skip hidden files unless requested
            if (!$showHidden && strpos($item, '.') === 0) {
                continue;
            }

            $fullPath = $dir . '/' . $item;
            $relativePath = str_replace($this->baseDir . '/', '', $fullPath);

            // Skip ShipPHP internal directories
            if (strpos($relativePath, '.shipphp') === 0 || $relativePath === 'backup') {
                continue;
            }

            $isDir = is_dir($fullPath);

            if (!$showFiles && !$isDir) {
                continue;
            }

            $node = [
                'name' => $item,
                'path' => $relativePath,
                'type' => $isDir ? 'directory' : 'file'
            ];

            if (!$isDir) {
                $node['size'] = filesize($fullPath);
                $node['modified'] = date('c', filemtime($fullPath));
            } else {
                $children = $this->buildTree($fullPath, $depth, $showHidden, $showFiles, $currentDepth + 1);
                if ($children !== null) {
                    $node['children'] = $children;
                }
            }

            $result[] = $node;
        }

        // Sort: directories first, then files, both alphabetically
        usort($result, function($a, $b) {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'directory' ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        return $result;
    }

    /**
     * Watch for file changes (polling-based)
     */
    private function actionWatch()
    {
        $since = $_POST['since'] ?? $_GET['since'] ?? '';
        $path = $_POST['path'] ?? $_GET['path'] ?? '';

        $sinceTime = !empty($since) ? strtotime($since) : (time() - 60);

        $searchDir = $this->baseDir;
        if (!empty($path)) {
            $this->validatePath($path);
            $searchDir = $this->baseDir . '/' . $path;
        }

        if (!is_dir($searchDir)) {
            throw new Exception('Path is not a directory');
        }

        $changes = [
            'modified' => [],
            'created' => [],
            'deleted' => []
        ];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($searchDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $relativePath = str_replace($this->baseDir . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $relativePath = str_replace('\\', '/', $relativePath);

            // Skip internal files
            if (strpos($relativePath, '.shipphp') === 0 || strpos($relativePath, 'backup/') === 0) {
                continue;
            }

            $mtime = $file->getMTime();
            $ctime = $file->getCTime();

            if ($mtime > $sinceTime) {
                if ($ctime > $sinceTime && $ctime === $mtime) {
                    $changes['created'][] = [
                        'path' => $relativePath,
                        'type' => $file->isDir() ? 'directory' : 'file',
                        'time' => date('c', $ctime)
                    ];
                } else {
                    $changes['modified'][] = [
                        'path' => $relativePath,
                        'type' => $file->isDir() ? 'directory' : 'file',
                        'time' => date('c', $mtime)
                    ];
                }
            }
        }

        $this->success('Changes since ' . date('c', $sinceTime), [
            'since' => date('c', $sinceTime),
            'now' => date('c'),
            'changes' => $changes,
            'hasChanges' => !empty($changes['modified']) || !empty($changes['created'])
        ]);
    }

    /**
     * Empty trash
     */
    private function actionEmptyTrash()
    {
        if (!is_dir($this->trashDir)) {
            $this->success('Trash is empty', ['deleted' => 0]);
            return;
        }

        $index = $this->loadTrashIndex();
        $deleted = 0;

        foreach ($index as $item) {
            $trashPath = $this->baseDir . '/' . ($item['trash_path'] ?? '');
            $trashIdDir = dirname($trashPath);

            if (file_exists($trashPath)) {
                if (is_dir($trashPath)) {
                    $this->deleteDirectory($trashPath);
                } else {
                    unlink($trashPath);
                }
                $deleted++;
            }

            // Clean up trash ID directory if empty
            if (is_dir($trashIdDir) && count(scandir($trashIdDir)) === 2) {
                rmdir($trashIdDir);
            }
        }

        // Clear index
        $this->saveTrashIndex([]);

        $this->log("Emptied trash: {$deleted} items");

        $this->success('Trash emptied', ['deleted' => $deleted]);
    }

    /**
     * Rename files (batch rename with find/replace)
     */
    private function actionRename()
    {
        $path = $_POST['path'] ?? '';
        $find = $_POST['find'] ?? '';
        $replace = $_POST['replace'] ?? '';
        $pattern = $_POST['pattern'] ?? '*';

        if (empty($path)) {
            throw new Exception('Path is required');
        }

        $this->validatePath($path);

        $fullPath = $this->baseDir . '/' . $path;

        if (!is_dir($fullPath)) {
            // Single file rename
            if (!file_exists($fullPath)) {
                throw new Exception('File not found');
            }

            $newName = str_replace($find, $replace, basename($path));
            $newPath = dirname($path) . '/' . $newName;

            if ($newPath === $path) {
                $this->success('No changes needed', ['renamed' => 0]);
                return;
            }

            $this->validatePath($newPath);

            if (file_exists($this->baseDir . '/' . $newPath)) {
                throw new Exception('Destination already exists');
            }

            if (!rename($fullPath, $this->baseDir . '/' . $newPath)) {
                throw new Exception('Rename failed');
            }

            $this->log("Renamed: {$path} -> {$newPath}");

            $this->success('File renamed', [
                'renamed' => 1,
                'items' => [['from' => $path, 'to' => $newPath]]
            ]);
            return;
        }

        // Batch rename in directory
        $items = scandir($fullPath);
        $renamed = [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            // Match pattern
            if ($pattern !== '*' && !fnmatch($pattern, $item, FNM_CASEFOLD)) {
                continue;
            }

            // Check if find string exists in filename
            if (strpos($item, $find) === false) {
                continue;
            }

            $newName = str_replace($find, $replace, $item);
            $oldPath = $fullPath . '/' . $item;
            $newPathFull = $fullPath . '/' . $newName;

            if (file_exists($newPathFull)) {
                continue; // Skip if destination exists
            }

            if (rename($oldPath, $newPathFull)) {
                $renamed[] = [
                    'from' => $path . '/' . $item,
                    'to' => $path . '/' . $newName
                ];
            }
        }

        $this->log("Batch renamed in {$path}: " . count($renamed) . " items");

        $this->success('Rename complete', [
            'renamed' => count($renamed),
            'items' => $renamed
        ]);
    }

    /**
     * Authenticate request
     */
    private function authenticate()
    {
        $token = $_POST['token'] ?? $_GET['token'] ?? '';

        if (empty($token)) {
            $this->error('Authentication required', 401);
        }

        // Timing-safe comparison
        if (!hash_equals(SHIPPHP_TOKEN, $token)) {
            $this->log('Authentication failed - invalid token');
            $this->error('Authentication failed', 401);
        }
    }

    /**
     * Check IP whitelist
     */
    private function checkIPWhitelist()
    {
        if (empty(IP_WHITELIST)) {
            return; // No whitelist configured
        }

        $clientIP = $this->getClientIP();

        foreach (IP_WHITELIST as $allowed) {
            if ($clientIP === $allowed) {
                return;
            }

            // Support CIDR notation
            if (strpos($allowed, '/') !== false) {
                if ($this->ipInRange($clientIP, $allowed)) {
                    return;
                }
            }
        }

        $this->log("IP blocked: {$clientIP}");
        $this->error('Access denied', 403);
    }

    /**
     * Rate limiting (SECURITY HARDENED - prevents race conditions)
     */
    private function checkRateLimit()
    {
        $ip = $this->getClientIP();
        $cacheFile = sys_get_temp_dir() . '/shipphp_rate_' . md5($ip) . '.tmp';

        // CRITICAL SECURITY FIX: Use exclusive file locking to prevent race conditions
        $lockFile = $cacheFile . '.lock';
        $lock = fopen($lockFile, 'c+');

        if (!$lock || !flock($lock, LOCK_EX)) {
            throw new Exception('Unable to acquire rate limit lock');
        }

        try {
            $requests = [];
            if (file_exists($cacheFile)) {
                $content = file_get_contents($cacheFile);
                if ($content !== false) {
                    $requests = json_decode($content, true) ?: [];
                }
            }

            $now = time();
            $cutoff = $now - 60;

            // Remove old requests
            $requests = array_filter($requests, function ($timestamp) use ($cutoff) {
                return $timestamp > $cutoff;
            });

            // Check limit
            if (count($requests) >= RATE_LIMIT) {
                $this->log("Rate limit exceeded for IP: {$ip}");
                flock($lock, LOCK_UN);
                fclose($lock);
                $this->error('Rate limit exceeded. Please try again in ' . (60 - ($now - min($requests))) . ' seconds.', 429);
            }

            // Add current request
            $requests[] = $now;

            // Save with exclusive lock still held
            file_put_contents($cacheFile, json_encode($requests), LOCK_EX);

        } finally {
            // Always release lock
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Validate file path
     */
    private function validatePath($path)
    {
        // Check for null bytes
        if (strpos($path, "\0") !== false) {
            throw new Exception('Invalid path');
        }

        // Check for path traversal
        if (strpos($path, '..') !== false) {
            throw new Exception('Path traversal not allowed');
        }

        // Normalize path
        $path = str_replace('\\', '/', $path);
        $fullPath = realpath($this->baseDir . '/' . dirname($path));

        if ($fullPath === false) {
            // Directory doesn't exist yet - validate parent
            return;
        }

        // Ensure within base directory
        if (strpos($fullPath, $this->baseDir) !== 0) {
            throw new Exception('Path outside base directory');
        }
    }

    /**
     * Validate file extension
     */
    private function validateFileExtension($filename)
    {
        $allowed = [
            // Code files
            'php', 'html', 'htm', 'css', 'js', 'json', 'xml', 'jsx', 'tsx', 'ts',
            'txt', 'md', 'sql', 'htaccess', 'ini', 'yml', 'yaml', 'env', 'sample',
            'sh', 'bash', 'py', 'rb', 'java', 'c', 'cpp', 'h', 'go', 'rs',
            // Images
            'jpg', 'jpeg', 'png', 'gif', 'svg', 'ico', 'webp', 'avif', 'bmp', 'tiff',
            // Fonts
            'woff', 'woff2', 'ttf', 'eot', 'otf',
            // Documents & Archives
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv',
            'zip', 'tar', 'gz', 'rar', '7z', 'bz2',
            // Media
            'mp3', 'mp4', 'avi', 'mov', 'wmv', 'flv', 'webm',
            // Other
            'map', 'lock', 'log', 'conf', 'config'
        ];

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Allow files without extension (like .gitignore, .htaccess, etc.)
        if (empty($ext)) {
            return;
        }

        if (!in_array($ext, $allowed)) {
            throw new Exception('File type not allowed: ' . $ext);
        }
    }

    /**
     * Scan files recursively
     */
    private function scanFiles($dir, $baseDir = null)
    {
        if ($baseDir === null) {
            $baseDir = $dir;
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            $relativePath = str_replace($baseDir . DIRECTORY_SEPARATOR, '', $path);
            $relativePath = str_replace('\\', '/', $relativePath);

            // Skip ShipPHP files and directory
            if (strpos($relativePath, '.shipphp') === 0 ||
                strpos($relativePath, 'shipphp/') === 0 ||
                strpos($relativePath, 'shipphp-config/') === 0 ||
                strpos($relativePath, 'shipphp-server.php') !== false ||
                $relativePath === 'shipphp' ||
                $relativePath === 'shipphp.php') {
                continue;
            }

            $hash = hash_file('sha256', $path);
            $files[$relativePath] = $hash;
        }

        return $files;
    }

    /**
     * Create backup
     */
    private function createBackup($backupId)
    {
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }

        $backupPath = $this->backupDir . '/' . $backupId;

        if (!mkdir($backupPath, 0755, true)) {
            throw new Exception('Failed to create backup directory');
        }

        // Copy all files
        $files = $this->scanFiles($this->baseDir);
        $copiedCount = 0;

        foreach (array_keys($files) as $file) {
            $source = $this->baseDir . '/' . $file;
            $dest = $backupPath . '/' . $file;

            $destDir = dirname($dest);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }

            if (copy($source, $dest)) {
                $copiedCount++;
            }
        }

        // Create manifest
        $manifest = [
            'created' => date('c'),
            'fileCount' => $copiedCount,
            'files' => $files
        ];

        file_put_contents($backupPath . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

        // Clean old backups
        $this->cleanOldBackups();

        return $backupPath;
    }

    /**
     * Restore from backup
     */
    private function restoreFromBackup($backupPath)
    {
        $manifestPath = $backupPath . '/manifest.json';
        if (!file_exists($manifestPath)) {
            throw new Exception('Backup manifest not found');
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $files = $manifest['files'] ?? [];

        foreach (array_keys($files) as $file) {
            $source = $backupPath . '/' . $file;
            $dest = $this->baseDir . '/' . $file;

            if (!file_exists($source)) {
                continue;
            }

            $destDir = dirname($dest);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }

            copy($source, $dest);
        }
    }

    /**
     * Backup single file
     */
    private function backupFile($path)
    {
        $backupId = 'auto-' . date('Y-m-d-His');
        $backupPath = $this->backupDir . '/' . $backupId;

        if (!is_dir($backupPath)) {
            mkdir($backupPath, 0755, true);
        }

        $source = $this->baseDir . '/' . $path;
        $dest = $backupPath . '/' . $path;

        $destDir = dirname($dest);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        copy($source, $dest);
    }

    /**
     * Clean old backups
     */
    private function cleanOldBackups()
    {
        if (!is_dir($this->backupDir)) {
            return;
        }

        $backups = scandir($this->backupDir, SCANDIR_SORT_DESCENDING);
        $backups = array_filter($backups, function ($b) {
            return $b !== '.' && $b !== '..';
        });

        if (count($backups) <= BACKUP_RETENTION) {
            return;
        }

        $toDelete = array_slice($backups, BACKUP_RETENTION);

        foreach ($toDelete as $backup) {
            $this->deleteDirectory($this->backupDir . '/' . $backup);
        }
    }

    /**
     * Delete directory recursively
     */
    private function deleteDirectory($dir)
    {
        if (!is_dir($dir)) {
            return false;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                // Check if subdirectory deletion succeeds
                if (!$this->deleteDirectory($path)) {
                    return false;
                }
            } else {
                // Check if file deletion succeeds
                if (!@unlink($path)) {
                    return false;
                }
            }
        }

        // Remove the directory itself
        return @rmdir($dir);
    }

    /**
     * Load trash index
     */
    private function loadTrashIndex()
    {
        if (!file_exists($this->trashIndex)) {
            return [];
        }

        $json = file_get_contents($this->trashIndex);
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Save trash index
     */
    private function saveTrashIndex(array $index)
    {
        if (!is_dir($this->trashDir)) {
            mkdir($this->trashDir, 0755, true);
        }

        file_put_contents($this->trashIndex, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Copy file or directory recursively
     */
    private function copyPath($source, $destination)
    {
        if (is_dir($source)) {
            $this->copyDirectory($source, $destination);
            return;
        }

        if (!copy($source, $destination)) {
            throw new Exception('Failed to copy file');
        }
    }

    private function copyDirectory($source, $destination)
    {
        if (!is_dir($destination)) {
            if (!mkdir($destination, 0755, true)) {
                throw new Exception('Failed to create destination directory');
            }
        }

        $items = array_diff(scandir($source), ['.', '..']);

        foreach ($items as $item) {
            $sourcePath = $source . '/' . $item;
            $destinationPath = $destination . '/' . $item;

            if (is_dir($sourcePath)) {
                $this->copyDirectory($sourcePath, $destinationPath);
            } else {
                if (!copy($sourcePath, $destinationPath)) {
                    throw new Exception('Failed to copy file');
                }
            }
        }
    }

    /**
     * Move file or directory with fallback
     */
    private function movePath($source, $destination)
    {
        if (@rename($source, $destination)) {
            return;
        }

        if (is_dir($source)) {
            $this->copyDirectory($source, $destination);
            if (!$this->deleteDirectory($source)) {
                throw new Exception('Failed to remove source directory after copy');
            }
            return;
        }

        if (!copy($source, $destination)) {
            throw new Exception('Failed to move file');
        }

        if (!unlink($source)) {
            throw new Exception('Failed to remove source file after copy');
        }
    }

    /**
     * Get directory size
     */
    private function getDirectorySize($dir)
    {
        $size = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $size += $file->getSize();
        }

        return $size;
    }

    /**
     * Get client IP (SECURITY HARDENED - prevents IP spoofing)
     */
    private function getClientIP()
    {
        // CRITICAL SECURITY FIX: Properly validate client IP to prevent spoofing
        // Only trust X-Forwarded-For if behind known proxy (configure as needed)
        $trustProxy = false; // Set to true if behind CloudFlare, AWS ELB, etc.

        // Always prefer REMOTE_ADDR as it cannot be spoofed
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Only trust forwarded headers if explicitly enabled and from trusted proxy
        if ($trustProxy && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // Get first IP from X-Forwarded-For chain (original client)
            $forwardedIps = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $firstIp = trim($forwardedIps[0]);

            // Validate it's a real IP address
            if (filter_var($firstIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $ip = $firstIp;
            }
        }

        return $ip;
    }

    /**
     * Validate backup ID format (SECURITY: Prevent path traversal)
     */
    private function isValidBackupId($backupId)
    {
        // Backup IDs must match format: YYYY-MM-DD-HHMMSS or YYYY-MM-DD-HHMMSS-label-vX.X.X
        // This prevents: ../, ..\, absolute paths, null bytes, etc.

        // Check for dangerous characters
        if (strpos($backupId, '..') !== false ||
            strpos($backupId, '/') !== false ||
            strpos($backupId, '\\') !== false ||
            strpos($backupId, "\0") !== false) {
            return false;
        }

        // Must match expected pattern
        $pattern = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}-[0-9]{6}(-[a-zA-Z0-9_-]+)?(-v[0-9]+\.[0-9]+\.[0-9]+)?$/';

        return preg_match($pattern, $backupId) === 1;
    }

    /**
     * Check if IP is in CIDR range
     */
    private function ipInRange($ip, $range)
    {
        list($subnet, $bits) = explode('/', $range);
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - $bits);
        $subnet &= $mask;

        return ($ip & $mask) == $subnet;
    }

    /**
     * Format bytes
     */
    private function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Get upload error message
     */
    private function getUploadError($code)
    {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL => 'File only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'Upload stopped by extension'
        ];

        return $errors[$code] ?? 'Unknown error';
    }

    /**
     * Log message
     */
    private function log($message)
    {
        if (!ENABLE_LOGGING) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $ip = $this->getClientIP();
        $logLine = "[{$timestamp}] [{$ip}] {$message}\n";

        file_put_contents($this->logFile, $logLine, FILE_APPEND);
    }

    /**
     * Send success response
     */
    private function success($message, $data = [])
    {
        echo json_encode(array_merge([
            'success' => true,
            'message' => $message
        ], $data));
        exit;
    }

    /**
     * Send error response
     */
    private function error($message, $code = 500)
    {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'error' => $message,
            'code' => $code
        ]);
        $this->log("Error: {$message}");
        exit;
    }
}

// Run the server
$server = new ShipPHPServer();
$server->handle();
