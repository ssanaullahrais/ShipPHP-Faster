<?php

namespace ShipPHP\Commands;

/**
 * WriteFile Command
 * Write content to file on server
 */
class WriteFileCommand extends BaseCommand
{
    public function execute($options)
    {
        $this->initApi();

        $path = $this->getArg($options, 0);
        $content = $this->getParam($options, 'content');
        $fromFile = $this->getParam($options, 'from');
        $overwrite = $this->hasFlag($options, 'force') || $this->hasFlag($options, 'overwrite');

        if (empty($path)) {
            $this->output->error("Usage: " . $this->cmd('write') . " <path> --content=\"...\"");
            $this->output->writeln("       " . $this->cmd('write') . " <path> --from=local-file.txt");
            $this->output->writeln();
            $this->output->writeln("Options:");
            $this->output->writeln("  --content=\"text\"   Content to write");
            $this->output->writeln("  --from=file        Read content from local file");
            $this->output->writeln("  --force            Overwrite existing file");
            return;
        }

        $path = $this->normalizeRelativePath($path);

        // Get content from file if specified
        if (!empty($fromFile)) {
            if (!file_exists($fromFile)) {
                $this->output->error("Local file not found: {$fromFile}");
                return;
            }
            $content = file_get_contents($fromFile);
            if ($content === false) {
                $this->output->error("Failed to read local file: {$fromFile}");
                return;
            }
        }

        if ($content === null) {
            $this->output->error("Content is required. Use --content=\"...\" or --from=file");
            return;
        }

        $this->header("Write File");

        $this->output->writeln("Writing to: " . $this->output->colorize($path, 'cyan'));
        $this->output->writeln("Content size: " . $this->formatBytes(strlen($content)));
        if ($overwrite) {
            $this->output->writeln($this->output->colorize("  (overwrite mode)", 'yellow'));
        }
        $this->output->writeln();

        try {
            $response = $this->api->writeFile($path, $content, $overwrite);

            $this->output->success($response['message'] ?? 'File written successfully');
            $this->output->writeln("  Size: " . $this->formatBytes($response['size'] ?? 0));
            $this->output->writeln("  Hash: " . substr($response['hash'] ?? '', 0, 16) . "...");
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
