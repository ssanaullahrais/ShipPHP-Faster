<?php

namespace ShipPHP\Commands;

use ShipPHP\Core\ProjectPaths;

/**
 * Web Command
 * Launch web UI server
 */
class WebCommand extends BaseCommand
{
    public function execute($options)
    {
        $port = intval($this->getParam($options, 'port', 8080));
        $host = $this->getParam($options, 'host', 'localhost');
        $open = $this->hasFlag($options, 'open');

        // Validate port
        if ($port < 1024 || $port > 65535) {
            $this->output->error("Invalid port. Use a port between 1024 and 65535.");
            return;
        }

        $this->header("ShipPHP Web UI");

        // Check if initialized
        if (!file_exists(ProjectPaths::configFile())) {
            $this->output->warning("No ShipPHP configuration found in this directory.");
            $this->output->writeln("The web UI will still work but some features require initialization.");
            $this->output->writeln("Run '" . $this->cmd('init') . "' first if you need deployment features.");
            $this->output->writeln();
        }

        // Check for API router
        $apiPath = dirname(__DIR__) . '/Api/router.php';
        if (!file_exists($apiPath)) {
            $this->output->error("API router not found. Please ensure the API layer is installed.");
            return;
        }

        // Check for web UI
        $webDir = dirname(dirname(__DIR__)) . '/web';
        $docsDir = dirname(dirname(__DIR__)) . '/docs';

        $publicDir = is_dir($webDir) ? $webDir : $docsDir;

        if (!is_dir($publicDir)) {
            $this->output->warning("Web UI directory not found. Creating minimal setup...");
            $publicDir = $this->createMinimalWebDir();
        }

        $url = "http://{$host}:{$port}";

        $this->output->writeln($this->output->colorize("Starting ShipPHP Web Server...", 'green'));
        $this->output->writeln();
        $this->output->writeln("  URL:     " . $this->output->colorize($url, 'cyan'));
        $this->output->writeln("  API:     " . $this->output->colorize("{$url}/api/", 'cyan'));
        $this->output->writeln("  Dir:     " . $this->output->colorize($publicDir, 'dim'));
        $this->output->writeln();
        $this->output->writeln($this->output->colorize("Press Ctrl+C to stop the server", 'yellow'));
        $this->output->writeln(str_repeat('-', 60));
        $this->output->writeln();

        // Open browser if requested
        if ($open) {
            $this->openBrowser($url);
        }

        // Start PHP built-in server
        $command = sprintf(
            'php -S %s:%d -t %s %s',
            escapeshellarg($host),
            $port,
            escapeshellarg($publicDir),
            escapeshellarg($apiPath)
        );

        // Pass through to shell
        passthru($command);
    }

    private function createMinimalWebDir()
    {
        $webDir = dirname(dirname(__DIR__)) . '/web';

        if (!mkdir($webDir, 0755, true)) {
            throw new \Exception("Failed to create web directory");
        }

        // Create minimal index.html
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShipPHP Web UI</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-950 text-white min-h-screen">
    <div class="container mx-auto px-4 py-8">
        <h1 class="text-3xl font-bold text-center mb-8">ShipPHP Web UI</h1>
        <div class="max-w-md mx-auto bg-slate-900 rounded-lg p-6">
            <p class="text-slate-400 mb-4">Web UI is starting...</p>
            <p class="text-sm text-slate-500">Check the API at <a href="/api/health" class="text-cyan-400">/api/health</a></p>
        </div>
    </div>
</body>
</html>
HTML;

        file_put_contents($webDir . '/index.html', $html);

        return $webDir;
    }

    private function openBrowser($url)
    {
        $command = null;

        if (PHP_OS_FAMILY === 'Windows') {
            $command = "start {$url}";
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $command = "open {$url}";
        } elseif (PHP_OS_FAMILY === 'Linux') {
            $command = "xdg-open {$url}";
        }

        if ($command) {
            exec($command . ' > /dev/null 2>&1 &');
        }
    }
}
