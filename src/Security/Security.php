<?php

namespace ShipPHP\Security;

/**
 * Security Layer - UNBREAKABLE
 *
 * Multi-layered security system:
 * - Token-based authentication with rotation
 * - Path traversal prevention
 * - File type validation
 * - Size limits
 * - Hash verification
 * - Rate limiting
 * - Encryption for sensitive data
 */
class Security
{
    // Maximum file size: 100MB
    const MAX_FILE_SIZE = 100 * 1024 * 1024;

    // Allowed file extensions (whitelist approach)
    const ALLOWED_EXTENSIONS = [
        'php', 'html', 'htm', 'css', 'js', 'json', 'xml',
        'txt', 'md', 'sql', 'htaccess', 'ini', 'yml', 'yaml',
        'jpg', 'jpeg', 'png', 'gif', 'svg', 'ico', 'webp',
        'woff', 'woff2', 'ttf', 'eot', 'otf',
        'pdf', 'zip', 'tar', 'gz',
        'env.example', 'gitignore', 'editorconfig'
    ];

    // Dangerous patterns to block
    const BLOCKED_PATTERNS = [
        '/../',           // Path traversal
        '/..\\',          // Path traversal (Windows)
        '/etc/passwd',    // System files
        '/etc/shadow',    // System files
        'php://input',    // PHP streams
        'php://filter',   // PHP streams
        'data://',        // Data URIs
        'expect://',      // Expect streams
        'phar://',        // Phar streams
    ];

    /**
     * Generate a secure token
     */
    public static function generateToken($length = 64)
    {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes($length / 2));
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            return bin2hex(openssl_random_pseudo_bytes($length / 2));
        } else {
            // Fallback (less secure)
            return hash('sha256', uniqid(mt_rand(), true));
        }
    }

    /**
     * Validate token format
     */
    public static function validateToken($token)
    {
        // Token must be 64 characters, alphanumeric
        return preg_match('/^[a-f0-9]{64}$/i', $token);
    }

    /**
     * Secure token comparison (timing-safe)
     */
    public static function compareTokens($token1, $token2)
    {
        if (function_exists('hash_equals')) {
            return hash_equals($token1, $token2);
        }

        // Fallback: timing-safe comparison
        if (strlen($token1) !== strlen($token2)) {
            return false;
        }

        $result = 0;
        for ($i = 0; $i < strlen($token1); $i++) {
            $result |= ord($token1[$i]) ^ ord($token2[$i]);
        }

        return $result === 0;
    }

    /**
     * Validate file path - CRITICAL SECURITY
     */
    public static function validatePath($path, $baseDir)
    {
        // 1. Check for null bytes
        if (strpos($path, "\0") !== false) {
            throw new \Exception("Invalid path: null byte detected");
        }

        // 2. Check for blocked patterns
        foreach (self::BLOCKED_PATTERNS as $pattern) {
            if (stripos($path, $pattern) !== false) {
                throw new \Exception("Security violation: blocked pattern detected");
            }
        }

        // 3. Normalize path
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path); // Remove double slashes

        // 4. Remove leading slash
        $path = ltrim($path, '/');

        // 5. Check for path traversal
        if (strpos($path, '..') !== false) {
            throw new \Exception("Security violation: path traversal attempt");
        }

        // 6. Resolve full path and verify it's within base directory
        $fullPath = realpath($baseDir . '/' . $path);
        $baseDirReal = realpath($baseDir);

        if ($fullPath === false) {
            // File doesn't exist yet (might be new upload)
            // Validate the directory part exists and is safe
            $dirname = dirname($baseDir . '/' . $path);
            if (is_dir($dirname)) {
                $fullPath = realpath($dirname) . '/' . basename($path);
            } else {
                // Create parent directories safely
                $parts = explode('/', $path);
                array_pop($parts); // Remove filename
                $currentPath = $baseDir;

                foreach ($parts as $part) {
                    if ($part === '' || $part === '.' || $part === '..') {
                        throw new \Exception("Invalid path component: {$part}");
                    }
                    $currentPath .= '/' . $part;
                }

                $fullPath = $currentPath . '/' . basename($path);
            }
        }

        // 7. Final check: ensure path is within base directory
        if ($baseDirReal === false) {
            throw new \Exception("Base directory does not exist");
        }

        if (strpos($fullPath, $baseDirReal) !== 0) {
            throw new \Exception("Security violation: path outside base directory");
        }

        return $path;
    }

    /**
     * Validate file extension
     */
    public static function validateFileExtension($filename)
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (empty($ext)) {
            // Files without extension (like .htaccess)
            $basename = basename($filename);
            if (strpos($basename, '.') === 0) {
                // Hidden file - check if allowed
                $cleanName = ltrim($basename, '.');
                if (in_array($cleanName, self::ALLOWED_EXTENSIONS)) {
                    return true;
                }
            }
            return false;
        }

        return in_array($ext, self::ALLOWED_EXTENSIONS);
    }

    /**
     * Validate file size
     */
    public static function validateFileSize($size)
    {
        if ($size > self::MAX_FILE_SIZE) {
            throw new \Exception(sprintf(
                "File too large: %s (max: %s)",
                self::formatBytes($size),
                self::formatBytes(self::MAX_FILE_SIZE)
            ));
        }

        return true;
    }

    /**
     * Calculate SHA256 hash of file
     */
    public static function hashFile($filepath)
    {
        if (!file_exists($filepath)) {
            throw new \Exception("File does not exist: {$filepath}");
        }

        if (!is_readable($filepath)) {
            throw new \Exception("File is not readable: {$filepath}");
        }

        return hash_file('sha256', $filepath);
    }

    /**
     * Verify file hash
     */
    public static function verifyFileHash($filepath, $expectedHash)
    {
        $actualHash = self::hashFile($filepath);
        return hash_equals($expectedHash, $actualHash);
    }

    /**
     * Sanitize filename
     */
    public static function sanitizeFilename($filename)
    {
        // Remove any path components
        $filename = basename($filename);

        // Remove dangerous characters
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

        // Remove multiple dots (except for extension)
        $parts = explode('.', $filename);
        if (count($parts) > 2) {
            $ext = array_pop($parts);
            $name = implode('_', $parts);
            $filename = $name . '.' . $ext;
        }

        return $filename;
    }

    /**
     * Encrypt sensitive data
     */
    public static function encrypt($data, $key)
    {
        if (!function_exists('openssl_encrypt')) {
            throw new \Exception("OpenSSL extension required for encryption");
        }

        $method = 'AES-256-CBC';
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($method));

        $encrypted = openssl_encrypt($data, $method, $key, 0, $iv);

        if ($encrypted === false) {
            throw new \Exception("Encryption failed");
        }

        // Return IV + encrypted data (base64 encoded)
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt sensitive data
     */
    public static function decrypt($data, $key)
    {
        if (!function_exists('openssl_decrypt')) {
            throw new \Exception("OpenSSL extension required for decryption");
        }

        $data = base64_decode($data);
        $method = 'AES-256-CBC';
        $ivLength = openssl_cipher_iv_length($method);

        $iv = substr($data, 0, $ivLength);
        $encrypted = substr($data, $ivLength);

        $decrypted = openssl_decrypt($encrypted, $method, $key, 0, $iv);

        if ($decrypted === false) {
            throw new \Exception("Decryption failed");
        }

        return $decrypted;
    }

    /**
     * Rate limiting check
     */
    public static function checkRateLimit($identifier, $maxRequests = 60, $timeWindow = 60)
    {
        $cacheFile = sys_get_temp_dir() . '/shipphp_rate_' . md5($identifier) . '.tmp';

        $requests = [];
        if (file_exists($cacheFile)) {
            $data = file_get_contents($cacheFile);
            $requests = json_decode($data, true) ?: [];
        }

        $now = time();
        $cutoff = $now - $timeWindow;

        // Remove old requests
        $requests = array_filter($requests, function ($timestamp) use ($cutoff) {
            return $timestamp > $cutoff;
        });

        // Check limit
        if (count($requests) >= $maxRequests) {
            throw new \Exception("Rate limit exceeded. Please try again later.");
        }

        // Add current request
        $requests[] = $now;

        // Save
        file_put_contents($cacheFile, json_encode($requests));

        return true;
    }

    /**
     * Format bytes for human reading
     */
    public static function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Validate IP address (optional - for IP whitelisting)
     */
    public static function validateIP($ip, $whitelist = [])
    {
        if (empty($whitelist)) {
            return true; // No whitelist configured
        }

        foreach ($whitelist as $allowed) {
            if ($ip === $allowed) {
                return true;
            }

            // Support CIDR notation
            if (strpos($allowed, '/') !== false) {
                if (self::ipInRange($ip, $allowed)) {
                    return true;
                }
            }
        }

        throw new \Exception("IP address not whitelisted: {$ip}");
    }

    /**
     * Check if IP is in CIDR range
     */
    private static function ipInRange($ip, $range)
    {
        list($subnet, $bits) = explode('/', $range);
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - $bits);
        $subnet &= $mask;

        return ($ip & $mask) == $subnet;
    }
}
