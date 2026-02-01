<?php

namespace ShipPHP\Commands;

/**
 * Watch Command
 * Watch for file changes on server
 */
class WatchCommand extends BaseCommand
{
    public function execute($options)
    {
        $this->initApi();

        $path = $this->getArg($options, 0) ?: '';
        $interval = intval($this->getParam($options, 'interval', 3));
        $once = $this->hasFlag($options, 'once');

        if ($interval < 1) {
            $interval = 1;
        }

        $this->header("Watch for Changes");

        if (!empty($path)) {
            $this->output->writeln("Watching: " . $this->output->colorize($path, 'cyan'));
        } else {
            $this->output->writeln("Watching: " . $this->output->colorize("entire project", 'cyan'));
        }
        $this->output->writeln("Interval: " . $this->output->colorize("{$interval}s", 'cyan'));
        $this->output->writeln();

        if ($once) {
            $this->checkOnce($path);
        } else {
            $this->watchLoop($path, $interval);
        }
    }

    private function checkOnce($path)
    {
        $since = date('c', time() - 60); // Last minute

        try {
            $response = $this->api->watch($since, $path);
            $changes = $response['changes'] ?? [];

            $hasChanges = !empty($changes['modified']) || !empty($changes['created']);

            if (!$hasChanges) {
                $this->output->writeln($this->output->colorize("No changes detected", 'green'));
                return;
            }

            $this->displayChanges($changes);
        } catch (\Exception $e) {
            $this->output->error($e->getMessage());
        }

        $this->output->writeln();
    }

    private function watchLoop($path, $interval)
    {
        $this->output->writeln($this->output->colorize("Watching for changes (Ctrl+C to stop)...", 'yellow'));
        $this->output->writeln(str_repeat('-', 60));

        $lastCheck = date('c');
        $seenChanges = [];

        while (true) {
            try {
                $response = $this->api->watch($lastCheck, $path);
                $changes = $response['changes'] ?? [];
                $lastCheck = $response['now'] ?? date('c');

                $hasChanges = !empty($changes['modified']) || !empty($changes['created']);

                if ($hasChanges) {
                    $this->displayChanges($changes, $seenChanges);

                    // Track seen changes to avoid duplicates
                    foreach (['modified', 'created'] as $type) {
                        foreach ($changes[$type] ?? [] as $change) {
                            $key = $type . ':' . $change['path'];
                            $seenChanges[$key] = $change['time'] ?? '';
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->output->writeln($this->output->colorize("[Error] " . $e->getMessage(), 'red'));
            }

            sleep($interval);
        }
    }

    private function displayChanges($changes, &$seenChanges = [])
    {
        $timestamp = date('H:i:s');

        // Created files
        foreach ($changes['created'] ?? [] as $item) {
            $key = 'created:' . $item['path'];
            if (isset($seenChanges[$key])) {
                continue;
            }

            $icon = $item['type'] === 'directory' ? '+' : '+';
            $color = 'green';
            $this->output->writeln(
                "[{$timestamp}] " .
                $this->output->colorize($icon, $color) . " " .
                $this->output->colorize("Created: ", $color) .
                $item['path']
            );
        }

        // Modified files
        foreach ($changes['modified'] ?? [] as $item) {
            $key = 'modified:' . $item['path'];
            if (isset($seenChanges[$key])) {
                continue;
            }

            $icon = '*';
            $color = 'yellow';
            $this->output->writeln(
                "[{$timestamp}] " .
                $this->output->colorize($icon, $color) . " " .
                $this->output->colorize("Modified: ", $color) .
                $item['path']
            );
        }
    }
}
