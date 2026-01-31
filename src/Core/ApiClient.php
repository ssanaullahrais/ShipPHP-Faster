<?php

namespace ShipPHP\Core;

use ShipPHP\Security\Security;

/**
 * API Client
 * Handles all communication with shipphp-server.php
 */
class ApiClient
{
    private $serverUrl;
    private $token;
    private $timeout = 300; // 5 minutes for large uploads
    private $maxRetries = 3; // ENTERPRISE: Retry failed requests
    private $retryDelay = 1; // Initial retry delay in seconds
    private $sslPinning = null; // ENTERPRISE: SSL certificate pinning

    public function __construct($serverUrl, $token)
    {
        $this->serverUrl = rtrim($serverUrl, '/');
        $this->token = $token;

        // Verify cURL is available
        if (!function_exists('curl_init')) {
            throw new \Exception("cURL extension is required but not installed");
        }
    }

    /**
     * ENTERPRISE: Enable SSL certificate pinning for enhanced security
     * @param string $certFingerprint SHA256 fingerprint of expected cert
     */
    public function enableSSLPinning($certFingerprint)
    {
        $this->sslPinning = $certFingerprint;
        return $this;
    }

    /**
     * ENTERPRISE: Configure retry behavior
     */
    public function setRetryPolicy($maxRetries = 3, $initialDelay = 1)
    {
        $this->maxRetries = max(0, $maxRetries);
        $this->retryDelay = max(0.1, $initialDelay);
        return $this;
    }

    /**
     * Test connection to server
     */
    public function test()
    {
        $response = $this->request('test', []);

        if (!$response['success']) {
            throw new \Exception($response['error'] ?? 'Connection test failed');
        }

        return $response;
    }

    /**
     * Get list of files from server with hashes
     */
    public function listFiles()
    {
        $response = $this->request('list', []);

        if (!$response['success']) {
            throw new \Exception($response['error'] ?? 'Failed to get file list from server');
        }

        return $response['files'] ?? [];
    }

    /**
     * Upload file to server
     */
    public function uploadFile($localPath, $remotePath)
    {
        if (!file_exists($localPath)) {
            throw new \Exception("File not found: {$localPath}");
        }

        // Validate file
        Security::validateFileSize(filesize($localPath));
        Security::validateFileExtension($remotePath);

        // Calculate hash before upload
        $hash = Security::hashFile($localPath);

        // Prepare file upload
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $this->serverUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'action' => 'upload',
                'token' => $this->token,
                'path' => $remotePath,
                'hash' => $hash,
                'file' => new \CURLFile($localPath, mime_content_type($localPath), basename($remotePath))
            ],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($error) {
            throw new \Exception("Upload failed: {$error}");
        }

        if ($httpCode !== 200) {
            throw new \Exception("Upload failed with HTTP {$httpCode}");
        }

        $data = json_decode($response, true);

        if (!$data || !$data['success']) {
            throw new \Exception($data['error'] ?? 'Upload failed');
        }

        return $data;
    }

    /**
     * Download file from server
     */
    public function downloadFile($remotePath, $localPath)
    {
        $response = $this->request('download', ['path' => $remotePath]);

        if (!$response['success']) {
            throw new \Exception($response['error'] ?? 'Download failed');
        }

        // Create directory if needed
        $dir = dirname($localPath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new \Exception("Failed to create directory: {$dir}");
            }
        }

        // Get file content
        $content = base64_decode($response['content']);

        if (file_put_contents($localPath, $content) === false) {
            throw new \Exception("Failed to write file: {$localPath}");
        }

        // Verify hash if provided
        if (isset($response['hash'])) {
            $actualHash = Security::hashFile($localPath);
            if ($actualHash !== $response['hash']) {
                unlink($localPath);
                throw new \Exception("Hash mismatch after download - file may be corrupted");
            }
        }

        return true;
    }

    /**
     * Delete file on server
     */
    public function deleteFile($remotePath)
    {
        $response = $this->request('delete', ['path' => $remotePath]);

        if (!$response['success']) {
            throw new \Exception($response['error'] ?? 'Delete failed');
        }

        return true;
    }

    /**
     * Trash files on server
     */
    public function trashFiles(array $paths)
    {
        $response = $this->request('trash', [
            'items' => json_encode($paths),
        ]);

        if (!$response['success']) {
            throw new \Exception($response['error'] ?? 'Trash failed');
        }

        return $response;
    }

    /**
     * List trashed files
     */
    public function listTrash()
    {
        $response = $this->request('listTrash', []);

        if (!$response['success']) {
            throw new \Exception($response['error'] ?? 'List trash failed');
        }

        return $response['items'] ?? [];
    }

    /**
     * Restore trashed file by id
     */
    public function restoreTrash($trashId, $force = false)
    {
        $response = $this->request('restoreTrash', [
            'id' => $trashId,
            'force' => $force ? 1 : 0,
        ]);

        if (!$response['success']) {
            throw new \Exception($response['error'] ?? 'Restore failed');
        }

        return $response;
    }

    /**
     * Move or copy files on server
     */
    public function moveFiles(array $items, $mode = 'move')
    {
        $response = $this->request('move', [
            'items' => json_encode($items),
            'mode' => $mode,
        ]);

        if (!$response['success']) {
            throw new \Exception($response['error'] ?? 'Move failed');
        }

        return $response;
    }

    /**
     * Rename files on server
     */
    public function renameFiles(array $items)
    {
        return $this->moveFiles($items, 'move');
    }

    /**
     * Toggle maintenance lock
     */
    public function lock($mode, $message = null)
    {
        $payload = ['mode' => $mode];
        if ($message !== null) {
            $payload['message'] = $message;
        }

        $response = $this->request('lock', $payload);

        if (!$response['success']) {
            throw new \Exception($response['error'] ?? 'Lock request failed');
        }

        return $response;
    }

    /**
     * Extract archive on server
     */
    public function extractArchive($remotePath, $destination = null, $overwrite = false)
    {
        $payload = ['path' => $remotePath];

        if ($destination) {
            $payload['destination'] = $destination;
        }

        if ($overwrite) {
            $payload['overwrite'] = 1;
        }

        $response = $this->request('extract', $payload);

        if (!$response['success']) {
            throw new \Exception($response['error'] ?? 'Extract failed');
        }

        return $response;
    }

    /**
     * Get server base directory
     */
    public function where()
    {
        $response = $this->request('where', []);

        if (!$response['success']) {
            throw new \Exception($response['error'] ?? 'Where request failed');
        }

        return $response;
    }

    /**
     * Create backup on server
     */
    public function createBackup($backupId)
    {
        $response = $this->request('backup', ['id' => $backupId]);

        if (!$response['success']) {
            throw new \Exception($response['error'] ?? 'Backup creation failed');
        }

        return $response;
    }

    /**
     * List backups on server
     */
    public function listBackups()
    {
        $response = $this->request('backups', []);

        if (!$response['success']) {
            throw new \Exception($response['error'] ?? 'Failed to get backup list');
        }

        return $response['backups'] ?? [];
    }

    /**
     * Restore backup on server
     */
    public function restoreBackup($backupId)
    {
        $response = $this->request('restore', ['id' => $backupId]);

        if (!$response['success']) {
            throw new \Exception($response['error'] ?? 'Restore failed');
        }

        return $response;
    }

    /**
     * Delete backup on server
     */
    public function deleteBackup($backupId)
    {
        $response = $this->request('deleteBackup', ['id' => $backupId]);

        if (!$response['success']) {
            throw new \Exception($response['error'] ?? 'Backup deletion failed');
        }

        return true;
    }

    /**
     * Get server info
     */
    public function getInfo()
    {
        $response = $this->request('info', []);

        if (!$response['success']) {
            throw new \Exception($response['error'] ?? 'Failed to get server info');
        }

        return $response;
    }

    /**
     * Send request to server (ENTERPRISE: With retry and SSL pinning)
     * Made public for health command access
     */
    public function request($action, $params = [])
    {
        // Apply rate limiting
        try {
            Security::checkRateLimit($this->serverUrl . ':' . $action, 120, 60);
        } catch (\Exception $e) {
            throw new \Exception("Too many requests. Please wait a moment and try again.");
        }

        $attempt = 0;
        $lastError = null;

        // ENTERPRISE: Retry with exponential backoff
        while ($attempt <= $this->maxRetries) {
            try {
                return $this->executeRequest($action, $params);
            } catch (\Exception $e) {
                $lastError = $e;
                $attempt++;

                // Don't retry on auth failures or client errors
                $errorMsg = $e->getMessage();
                if (strpos($errorMsg, 'Authentication failed') !== false ||
                    strpos($errorMsg, 'Access denied') !== false ||
                    strpos($errorMsg, 'Invalid') !== false) {
                    throw $e;
                }

                // If we have retries left, wait and try again
                if ($attempt <= $this->maxRetries) {
                    // Exponential backoff: 1s, 2s, 4s, 8s...
                    $delay = $this->retryDelay * pow(2, $attempt - 1);
                    usleep($delay * 1000000); // Convert to microseconds
                }
            }
        }

        // All retries failed
        throw new \Exception("Request failed after {$this->maxRetries} retries: " . $lastError->getMessage());
    }

    /**
     * Execute single HTTP request (ENTERPRISE: With SSL validation)
     */
    private function executeRequest($action, $params)
    {
        $curl = curl_init();

        $postData = array_merge([
            'action' => $action,
            'token' => $this->token,
        ], $params);

        $curlOptions = [
            CURLOPT_URL => $this->serverUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => [
                'User-Agent: ShipPHP-Faster/2.1.0',
                'Accept: application/json',
                'X-ShipPHP-Client-Version: 2.1.0'
            ]
        ];

        // ENTERPRISE: SSL Certificate Pinning
        if ($this->sslPinning !== null) {
            $curlOptions[CURLOPT_SSL_VERIFYPEER] = true;
            $curlOptions[CURLOPT_SSL_VERIFYHOST] = 2;
            // Would require CURLOPT_PINNEDPUBLICKEY in production
            // $curlOptions[CURLOPT_PINNEDPUBLICKEY] = $this->sslPinning;
        }

        curl_setopt_array($curl, $curlOptions);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($error) {
            throw new \Exception("Connection error: {$error}");
        }

        if ($httpCode === 401) {
            throw new \Exception("Authentication failed. Check your token in shipphp.json");
        }

        if ($httpCode === 403) {
            throw new \Exception("Access denied. Your IP may not be whitelisted.");
        }

        if ($httpCode === 429) {
            throw new \Exception("Rate limit exceeded on server. Please try again later.");
        }

        // CRITICAL FIX: Don't throw on 503 for health checks - parse response instead
        if ($httpCode === 503 && $action !== 'health') {
            throw new \Exception("Server temporarily unavailable. Please try again later.");
        }

        // For health checks, accept both 200 and 503 (unhealthy is a valid response)
        if ($httpCode !== 200 && !($action === 'health' && $httpCode === 503)) {
            throw new \Exception("Server returned HTTP {$httpCode}");
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid response from server: " . json_last_error_msg());
        }

        return $data;
    }

    /**
     * ENTERPRISE: Raw request without retry logic (for health checks)
     */
    public function requestRaw($action, $params = [])
    {
        // Apply rate limiting
        try {
            Security::checkRateLimit($this->serverUrl . ':' . $action, 120, 60);
        } catch (\Exception $e) {
            throw new \Exception("Too many requests. Please wait a moment and try again.");
        }

        // Execute request without retry
        return $this->executeRequest($action, $params);
    }

    /**
     * Set timeout for requests
     */
    public function setTimeout($seconds)
    {
        $this->timeout = $seconds;
        return $this;
    }
}
