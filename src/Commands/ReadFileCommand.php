<?php

namespace ShipPHP\Commands;

/**
 * ReadFile Command
 * Read file content from server
 */
class ReadFileCommand extends BaseCommand
{
    public function execute($options)
    {
        $this->initApi();

        $path = $this->getArg($options, 0);
        $lines = intval($this->getParam($options, 'lines', 0));
        $offset = intval($this->getParam($options, 'offset', 0));
        $saveTo = $this->getParam($options, 'save');
        $raw = $this->hasFlag($options, 'raw');

        if (empty($path)) {
            $this->output->error("Usage: " . $this->cmd('read') . " <path>");
            $this->output->writeln();
            $this->output->writeln("Options:");
            $this->output->writeln("  --lines=N     Show only N lines");
            $this->output->writeln("  --offset=N    Skip first N lines");
            $this->output->writeln("  --save=file   Save to local file");
            $this->output->writeln("  --raw         Output raw content only");
            return;
        }

        $path = $this->normalizeRelativePath($path);

        if (!$raw) {
            $this->header("Read File");
            $this->output->writeln("Reading: " . $this->output->colorize($path, 'cyan'));
            $this->output->writeln();
        }

        try {
            $response = $this->api->readFile($path, $lines, $offset);
            $content = $response['content'] ?? '';

            // Save to local file if requested
            if (!empty($saveTo)) {
                $dir = dirname($saveTo);
                if (!is_dir($dir) && $dir !== '.') {
                    mkdir($dir, 0755, true);
                }

                if (file_put_contents($saveTo, $content) !== false) {
                    $this->output->success("Saved to: {$saveTo}");
                } else {
                    $this->output->error("Failed to save file");
                }
                $this->output->writeln();
                return;
            }

            // Raw output mode
            if ($raw) {
                echo $content;
                return;
            }

            // Show file info
            $this->output->writeln($this->output->colorize("File Info:", 'yellow'));
            $this->output->writeln("  Size: " . $this->formatBytes($response['size'] ?? 0));
            $this->output->writeln("  Lines: " . ($response['lines'] ?? 'unknown'));
            $this->output->writeln("  MIME: " . ($response['mime'] ?? 'unknown'));
            $this->output->writeln("  Modified: " . ($response['modified'] ?? 'unknown'));
            $this->output->writeln("  Hash: " . substr($response['hash'] ?? '', 0, 16) . "...");
            $this->output->writeln();

            // Show content
            $this->output->writeln($this->output->colorize("Content:", 'yellow'));
            $this->output->writeln(str_repeat('-', 60));

            // Limit display for very long content
            if (strlen($content) > 10000) {
                $this->output->writeln(substr($content, 0, 10000));
                $this->output->writeln();
                $this->output->writeln($this->output->colorize("... (truncated, use --save to get full content)", 'yellow'));
            } else {
                $this->output->writeln($content);
            }

            $this->output->writeln(str_repeat('-', 60));
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
