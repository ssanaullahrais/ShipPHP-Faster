<?php

namespace ShipPHP\Commands;

/**
 * Sync Command
 * Combined status + push operation
 */
class SyncCommand extends BaseCommand
{
    public function execute($options)
    {
        $this->initConfig();
        $this->initState();
        $this->initApi();

        $this->header("ShipPHP Sync");

        // Show status first
        $statusCmd = new StatusCommand($this->output);
        $statusCmd->execute($options);

        // Ask for confirmation
        $this->output->writeln(str_repeat("═", 60), 'cyan');
        $this->output->writeln();

        if (!$this->hasFlag($options, 'yes') && !$this->hasFlag($options, 'y')) {
            if (!$this->output->confirm("Proceed with push?", true)) {
                $this->output->writeln("Sync cancelled.\n");
                return;
            }
        }

        $this->output->writeln();

        // Execute push
        $pushCmd = new PushCommand($this->output);
        $pushCmd->execute($options);
    }
}
