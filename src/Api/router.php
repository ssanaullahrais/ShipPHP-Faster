<?php
/**
 * ShipPHP API Router
 * Routes requests to appropriate handlers for the Web UI
 *
 * This file is used by PHP's built-in server:
 * php -S localhost:8080 router.php
 */

// Get the request URI
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Web UI directory (src/Web)
$webDir = __DIR__ . '/../Web';

// Serve static files from src/Web directory
if ($uri !== '/') {
    $staticFile = $webDir . $uri;
    if (file_exists($staticFile) && is_file($staticFile)) {
        // Set correct content type
        $ext = pathinfo($staticFile, PATHINFO_EXTENSION);
        $mimeTypes = [
            'html' => 'text/html',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
        ];
        $contentType = $mimeTypes[$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $contentType);
        readfile($staticFile);
        exit;
    }
}

// API endpoints
if (strpos($uri, '/api/') === 0) {
    // Set working directory to project root
    $projectRoot = dirname(dirname(dirname(__FILE__)));

    // Load autoloader
    require_once $projectRoot . '/src/Api/ApiServer.php';

    // Handle API request
    $server = new \ShipPHP\Api\ApiServer();
    $server->handle();
    exit;
}

// Serve index.html for root and SPA routes
$indexFile = $webDir . '/index.html';

if (file_exists($indexFile)) {
    header('Content-Type: text/html');
    readfile($indexFile);
} else {
    // Fallback response
    header('Content-Type: text/html');
    echo '<!DOCTYPE html><html><head><title>ShipPHP</title></head>';
    echo '<body style="background:#0f172a;color:#fff;font-family:system-ui;display:flex;justify-content:center;align-items:center;height:100vh;margin:0">';
    echo '<div style="text-align:center"><h1>ShipPHP Web UI</h1>';
    echo '<p style="color:#94a3b8">Web UI files not found at src/Web/</p>';
    echo '<p>API available at <a href="/api/health" style="color:#22d3ee">/api/health</a></p></div>';
    echo '</body></html>';
}
