<?php

namespace ShipPHP\Commands;

/**
 * Touch Command
 * Create empty file on server
 */
class TouchCommand extends BaseCommand
{
    public function execute($options)
    {
        $this->initApi();

        $path = $this->getArg($options, 0);

        if (empty($path)) {
            $this->output->error("Usage: " . $this->cmd('touch') . " <path>");
            $this->output->writeln("Example: " . $this->cmd('touch') . " public/newfile.txt");
            return;
        }

        $path = $this->normalizeRelativePath($path);

        $this->header("Create File");

        $this->output->writeln("Creating file: " . $this->output->colorize($path, 'cyan'));
        $this->output->writeln();

        try {
            $response = $this->api->touch($path);

            if ($response['created'] ?? false) {
                $this->output->success("File created: {$path}");
            } else {
                $this->output->success("File timestamp updated: {$path}");
            }
        } catch (\Exception $e) {
            $this->output->error($e->getMessage());
        }

        $this->output->writeln();
    }
}
