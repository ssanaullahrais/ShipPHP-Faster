<?php

namespace ShipPHP\Commands;

/**
 * Web Command
 * Launch the local ShipPHP web UI
 */
class WebCommand extends BaseCommand
{
    public function execute($options)
    {
        $host = $this->getParam($options, 'host', '127.0.0.1');
        $port = (int)$this->getParam($options, 'port', 8787);
        $docRoot = SHIPPHP_ROOT . '/docs';
        $router = $docRoot . '/web-ui-router.php';

        if (!file_exists($router)) {
            $this->output->error("Web UI router not found: {$router}");
            return;
        }

        $this->header("ShipPHP Web UI");
        $this->output->writeln("Serving: " . $this->output->colorize("http://{$host}:{$port}/web-ui.html", 'green'));
        $this->output->writeln("Doc root: " . $this->output->colorize($docRoot, 'cyan'));
        $this->output->writeln("Press Ctrl+C to stop.\n");

        $command = sprintf(
            'php -S %s:%d -t %s %s',
            escapeshellarg($host),
            $port,
            escapeshellarg($docRoot),
            escapeshellarg($router)
        );

        passthru($command);
    }
}
