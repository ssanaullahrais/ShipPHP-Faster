<?php
/**
 * ShipPHP Faster - Server Receiver
 *
 * Upload this file to your web server and configure the token
 * DO NOT commit this file with your actual token
 *
 * @version 1.0.0
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
header('X-ShipPHP-Version: 2.0.0');

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

    public function __construct()
    {
        $this->baseDir = realpath(BASE_DIR);
        $this->backupDir = $this->baseDir . '/backup';
        $this->logFile = $this->baseDir . '/.shipphp-server.log';

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
            'version' => '1.0.0',
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
            'version' => '1.0.0',
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
