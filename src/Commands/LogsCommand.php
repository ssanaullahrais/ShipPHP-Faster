<?php

namespace ShipPHP\Commands;

/**
 * Logs Command
 * View server logs
 */
class LogsCommand extends BaseCommand
{
    public function execute($options)
    {
        $this->initApi();

        $lines = intval($this->getParam($options, 'lines', 50));
        $filter = $this->getParam($options, 'filter', '');
        $follow = $this->hasFlag($options, 'follow') || $this->hasFlag($options, 'f');
        $raw = $this->hasFlag($options, 'raw');

        if (!$raw) {
            $this->header("Server Logs");

            if (!empty($filter)) {
                $this->output->writeln("Filter: " . $this->output->colorize($filter, 'cyan'));
            }
            $this->output->writeln("Lines:  " . $this->output->colorize((string)$lines, 'cyan'));
            $this->output->writeln();
        }

        try {
            if ($follow) {
                $this->followLogs($lines, $filter);
            } else {
                $this->showLogs($lines, $filter, $raw);
            }
        } catch (\Exception $e) {
            $this->output->error($e->getMessage());
        }

        if (!$raw) {
            $this->output->writeln();
        }
    }

    private function showLogs($lines, $filter, $raw)
    {
        $response = $this->api->getLogs($lines, $filter);
        $logs = $response['logs'] ?? [];

        if (empty($logs)) {
            if (!$raw) {
                $this->output->warning("No log entries found");
            }
            return;
        }

        if (!$raw) {
            $this->output->writeln($this->output->colorize("Log Entries (" . count($logs) . "):", 'yellow'));
            $this->output->writeln(str_repeat('-', 80));
        }

        foreach ($logs as $line) {
            if ($raw) {
                echo $line . "\n";
            } else {
                // Colorize log entries
                if (stripos($line, 'error') !== false) {
                    $this->output->writeln($this->output->colorize($line, 'red'));
                } elseif (stripos($line, 'warning') !== false) {
                    $this->output->writeln($this->output->colorize($line, 'yellow'));
                } elseif (stripos($line, 'success') !== false || stripos($line, 'uploaded') !== false) {
                    $this->output->writeln($this->output->colorize($line, 'green'));
                } else {
                    $this->output->writeln($line);
                }
            }
        }

        if (!$raw) {
            $this->output->writeln(str_repeat('-', 80));
        }
    }

    private function followLogs($lines, $filter)
    {
        $this->output->writeln($this->output->colorize("Following logs (Ctrl+C to stop)...", 'yellow'));
        $this->output->writeln(str_repeat('-', 80));

        $lastLogs = [];
        $firstRun = true;

        while (true) {
            $response = $this->api->getLogs($lines, $filter);
            $logs = $response['logs'] ?? [];

            if ($firstRun) {
                // Show initial logs
                foreach ($logs as $line) {
                    $this->output->writeln($line);
                }
                $lastLogs = $logs;
                $firstRun = false;
            } else {
                // Show only new logs
                foreach ($logs as $line) {
                    if (!in_array($line, $lastLogs)) {
                        $this->output->writeln($line);
                    }
                }
                $lastLogs = $logs;
            }

            sleep(2); // Poll every 2 seconds
        }
    }
}
