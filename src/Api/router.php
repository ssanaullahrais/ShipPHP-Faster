<?php
/**
 * ShipPHP API Router
 * Routes requests to appropriate handlers for the Web UI
 *
 * This file is used by PHP's built-in server:
 * php -S localhost:8080 -t web router.php
 */

// Get the request URI
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve static files directly
if ($uri !== '/' && file_exists(__DIR__ . '/../../web' . $uri)) {
    return false; // Let PHP serve static files
}

if ($uri !== '/' && file_exists(__DIR__ . '/../../docs' . $uri)) {
    return false; // Let PHP serve static files from docs
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
$webIndex = __DIR__ . '/../../web/index.html';
$docsIndex = __DIR__ . '/../../docs/web-ui.html';

if (file_exists($webIndex)) {
    readfile($webIndex);
} elseif (file_exists($docsIndex)) {
    readfile($docsIndex);
} else {
    // Fallback response
    header('Content-Type: text/html');
    echo '<!DOCTYPE html><html><head><title>ShipPHP</title></head>';
    echo '<body style="background:#0f172a;color:#fff;font-family:system-ui;display:flex;justify-content:center;align-items:center;height:100vh;margin:0">';
    echo '<div style="text-align:center"><h1>ShipPHP Web UI</h1><p>API available at <a href="/api/health" style="color:#22d3ee">/api/health</a></p></div>';
    echo '</body></html>';
}
