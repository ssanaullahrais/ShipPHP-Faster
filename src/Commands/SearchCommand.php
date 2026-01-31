<?php

namespace ShipPHP\Commands;

/**
 * Search Command
 * Search for files by name on server
 */
class SearchCommand extends BaseCommand
{
    public function execute($options)
    {
        $this->initApi();

        $pattern = $this->getArg($options, 0);
        $path = $this->getParam($options, 'path', '');
        $max = intval($this->getParam($options, 'max', 100));
        $includeHidden = $this->hasFlag($options, 'hidden');

        if (empty($pattern)) {
            $this->output->error("Usage: " . $this->cmd('search') . " <pattern>");
            $this->output->writeln();
            $this->output->writeln("Examples:");
            $this->output->writeln("  " . $this->cmd('search') . " \"*.php\"           Search all PHP files");
            $this->output->writeln("  " . $this->cmd('search') . " \"config*\"         Search files starting with 'config'");
            $this->output->writeln("  " . $this->cmd('search') . " \"*.js\" --path=src Search in specific directory");
            $this->output->writeln();
            $this->output->writeln("Options:");
            $this->output->writeln("  --path=dir     Search in specific directory");
            $this->output->writeln("  --max=N        Maximum results (default: 100)");
            $this->output->writeln("  --hidden       Include hidden files");
            return;
        }

        $this->header("Search Files");

        $this->output->writeln("Pattern: " . $this->output->colorize($pattern, 'cyan'));
        if (!empty($path)) {
            $this->output->writeln("Path: " . $this->output->colorize($path, 'cyan'));
        }
        $this->output->writeln();

        try {
            $response = $this->api->search($pattern, $path, $max, $includeHidden);
            $results = $response['results'] ?? [];

            if (empty($results)) {
                $this->output->warning("No files found matching '{$pattern}'");
                $this->output->writeln();
                return;
            }

            $this->output->success("Found " . count($results) . " file(s)");
            if ($response['truncated'] ?? false) {
                $this->output->writeln($this->output->colorize("  (results truncated, use --max to show more)", 'yellow'));
            }
            $this->output->writeln();

            // Display results
            $headers = ['Name', 'Path', 'Type', 'Size', 'Modified'];
            $rows = [];

            foreach ($results as $file) {
                $rows[] = [
                    $file['name'],
                    $file['path'],
                    $file['type'],
                    $file['type'] === 'file' ? $this->formatBytes($file['size'] ?? 0) : '-',
                    isset($file['modified']) ? date('Y-m-d H:i', strtotime($file['modified'])) : '-'
                ];
            }

            $this->output->table($headers, $rows);
        } catch (\Exception $e) {
            $this->output->error($e->getMessage());
        }

        $this->output->writeln();
    }

    private function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
