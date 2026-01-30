<?php

namespace ShipPHP\Commands;

/**
 * Where Command
 * Show server base directory
 */
class WhereCommand extends BaseCommand
{
    public function execute($options)
    {
        $this->initConfig();
        $this->initApi();

        $this->header("ShipPHP Where");

        $this->output->write("Testing connection... ");
        try {
            $this->api->test();
            $this->output->success("Connected");
        } catch (\Exception $e) {
            $this->output->error("Connection failed");
            $this->output->writeln();
            $this->output->error($e->getMessage());
            return;
        }

        try {
            $response = $this->api->where();
            $baseDir = $response['baseDir'] ?? 'unknown';

            $this->output->writeln();
            $this->output->table(
                ['Server Base Directory', 'Note'],
                [[
                    $baseDir,
                    'Paths are relative to this directory'
                ]]
            );
        } catch (\Exception $e) {
            $this->output->error("Failed to fetch server base directory");
            $this->output->writeln($e->getMessage());
        }
    }
}
