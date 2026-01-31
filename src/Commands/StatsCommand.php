<?php

namespace ShipPHP\Commands;

/**
 * Stats Command
 * Show server statistics
 */
class StatsCommand extends BaseCommand
{
    public function execute($options)
    {
        $this->initApi();

        $this->header("Server Statistics");

        try {
            $response = $this->api->getStats();

            // Disk usage
            $disk = $response['disk'] ?? [];
            $this->output->writeln($this->output->colorize("Disk Usage:", 'yellow'));
            $this->output->writeln("  Total:       " . ($disk['totalFormatted'] ?? '-'));
            $this->output->writeln("  Used:        " . $this->formatBytes($disk['used'] ?? 0) . " (" . ($disk['usedPercent'] ?? 0) . "%)");
            $this->output->writeln("  Free:        " . ($disk['freeFormatted'] ?? '-'));

            // Progress bar for disk
            $usedPercent = $disk['usedPercent'] ?? 0;
            $barWidth = 30;
            $filled = (int)round(($usedPercent / 100) * $barWidth);
            $color = $usedPercent > 90 ? 'red' : ($usedPercent > 70 ? 'yellow' : 'green');
            $bar = '[' . $this->output->colorize(str_repeat('=', $filled), $color) . str_repeat('-', $barWidth - $filled) . ']';
            $this->output->writeln("  " . $bar . " " . round($usedPercent, 1) . "%");

            $this->output->writeln();

            // Files
            $files = $response['files'] ?? [];
            $this->output->writeln($this->output->colorize("Project Files:", 'yellow'));
            $this->output->writeln("  Files:       " . ($files['count'] ?? 0));
            $this->output->writeln("  Directories: " . ($files['directories'] ?? 0));
            $this->output->writeln("  Total Size:  " . ($files['totalSizeFormatted'] ?? '-'));

            $this->output->writeln();

            // File types
            $types = $response['types'] ?? [];
            if (!empty($types)) {
                $this->output->writeln($this->output->colorize("Top File Types:", 'yellow'));
                $headers = ['Extension', 'Count', 'Size'];
                $rows = [];
                foreach ($types as $ext => $data) {
                    $rows[] = [
                        $ext === 'no_ext' ? '(no extension)' : ".{$ext}",
                        $data['count'],
                        $this->formatBytes($data['size'])
                    ];
                }
                $this->output->table($headers, $rows);
            }

            // PHP info
            $php = $response['php'] ?? [];
            $this->output->writeln($this->output->colorize("PHP Configuration:", 'yellow'));
            $this->output->writeln("  Version:       " . ($php['version'] ?? '-'));
            $this->output->writeln("  Memory Limit:  " . ($php['memory_limit'] ?? '-'));
            $this->output->writeln("  Upload Max:    " . ($php['max_upload'] ?? '-'));
            $this->output->writeln("  Post Max:      " . ($php['max_post'] ?? '-'));
            $this->output->writeln("  Max Execution: " . ($php['max_execution'] ?? '-') . "s");

            $this->output->writeln();

            // Server info
            $server = $response['server'] ?? [];
            $this->output->writeln($this->output->colorize("Server:", 'yellow'));
            $this->output->writeln("  Software:    " . ($server['software'] ?? '-'));
            $this->output->writeln("  Protocol:    " . ($server['protocol'] ?? '-'));
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
