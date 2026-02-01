<?php

namespace ShipPHP\Commands;

/**
 * Info Command
 * Get file/directory information from server
 */
class InfoCommand extends BaseCommand
{
    public function execute($options)
    {
        $this->initApi();

        $path = $this->getArg($options, 0);

        if (empty($path)) {
            $this->output->error("Usage: " . $this->cmd('info') . " <path>");
            $this->output->writeln("Example: " . $this->cmd('info') . " public/index.php");
            return;
        }

        $path = $this->normalizeRelativePath($path);

        $this->header("File Information");

        $this->output->writeln("Path: " . $this->output->colorize($path, 'cyan'));
        $this->output->writeln();

        try {
            $response = $this->api->getFileInfo($path);

            $isDir = ($response['type'] ?? '') === 'directory';

            $this->output->writeln($this->output->colorize("Details:", 'yellow'));
            $this->output->writeln("  Name:        " . ($response['name'] ?? '-'));
            $this->output->writeln("  Type:        " . ($response['type'] ?? '-'));
            $this->output->writeln("  Size:        " . $this->formatBytes($response['size'] ?? 0));
            $this->output->writeln("  Permissions: " . ($response['permissions'] ?? '-'));
            $this->output->writeln("  Owner:       " . ($response['owner'] ?? '-'));
            $this->output->writeln("  Group:       " . ($response['group'] ?? '-'));
            $this->output->writeln();
            $this->output->writeln($this->output->colorize("Timestamps:", 'yellow'));
            $this->output->writeln("  Created:     " . $this->formatDate($response['created'] ?? ''));
            $this->output->writeln("  Modified:    " . $this->formatDate($response['modified'] ?? ''));
            $this->output->writeln("  Accessed:    " . $this->formatDate($response['accessed'] ?? ''));

            if (!$isDir) {
                $this->output->writeln();
                $this->output->writeln($this->output->colorize("File Details:", 'yellow'));
                $this->output->writeln("  MIME Type:   " . ($response['mime'] ?? '-'));
                $this->output->writeln("  Extension:   " . ($response['extension'] ?? '-'));
                $this->output->writeln("  Hash:        " . substr($response['hash'] ?? '', 0, 32) . "...");
            } else {
                $this->output->writeln();
                $this->output->writeln($this->output->colorize("Directory Details:", 'yellow'));
                $this->output->writeln("  Items:       " . ($response['items'] ?? 0) . " files/directories");
            }
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

    private function formatDate($date)
    {
        if (empty($date)) {
            return '-';
        }
        return date('Y-m-d H:i:s', strtotime($date));
    }
}
