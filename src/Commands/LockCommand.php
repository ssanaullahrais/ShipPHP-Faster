<?php

namespace ShipPHP\Commands;

/**
 * Lock Command
 * Toggle maintenance mode on the server
 */
class LockCommand extends BaseCommand
{
    public function execute($options)
    {
        $this->initConfig();
        $this->initApi();

        $action = $this->getArg($options, 0) ?? 'status';
        $message = $this->getParam($options, 'message');

        $this->header("ShipPHP Maintenance Lock");

        $mode = 'status';
        if (in_array($action, ['on', 'enable'], true)) {
            $mode = 'enable';
        } elseif (in_array($action, ['off', 'disable'], true)) {
            $mode = 'disable';
        }

        try {
            $response = $this->api->lock($mode, $message);
        } catch (\Exception $e) {
            $this->output->error("Lock request failed");
            $this->output->writeln($e->getMessage());
            return;
        }

        if ($mode === 'status') {
            $locked = $response['locked'] ?? false;
            $this->output->writeln("Status: " . $this->output->colorize($locked ? 'LOCKED' : 'UNLOCKED', $locked ? 'red' : 'green'));
            if (!empty($response['message'])) {
                $this->output->writeln("Message: {$response['message']}");
            }
        } elseif ($mode === 'enable') {
            $this->output->success("Maintenance mode enabled.");
        } else {
            $this->output->success("Maintenance mode disabled.");
        }

        $this->output->writeln();
    }
}
