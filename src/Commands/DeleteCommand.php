<?php

namespace ShipPHP\Commands;

/**
 * Delete Command
 * Delete a file or directory on the server
 */
class DeleteCommand extends BaseCommand
{
    public function execute($options)
    {
        $this->initConfig();
        $this->initState();
        $this->initApi();

        $path = $this->getArg($options, 0);
        $force = $this->hasFlag($options, 'force');
        $dryRun = $this->hasFlag($options, 'dry-run');

        $this->header("ShipPHP Delete");

        if (!$path) {
            $this->output->error("Please specify a file or directory to delete.");
            $this->output->writeln("Usage: " . $this->cmd('delete <path>'));
            return;
        }

        $this->output->writeln("Target: " . $this->output->colorize($path, 'cyan'));
        $this->output->writeln();

        if ($dryRun) {
            $this->output->info("Dry run: no changes will be made.");
        }

        if (!$force && !$dryRun) {
            if (!$this->output->confirm("Delete '{$path}' from the server?", false)) {
                $this->output->writeln("Delete cancelled.\n");
                return;
            }
        }

        if ($dryRun) {
            $this->output->success("Dry run complete.");
            return;
        }

        try {
            $this->api->deleteFile($path);
            $this->output->success("Deleted '{$path}' from server.");
        } catch (\Exception $e) {
            $this->output->error("Delete failed");
            $this->output->writeln($e->getMessage());
            return;
        }

        try {
            $serverFiles = $this->api->listFiles();
            $this->state->updateServerFiles($serverFiles);
            $this->state->save();
        } catch (\Exception $e) {
            $this->output->warning("Deleted file, but failed to refresh server file list: " . $e->getMessage());
        }

        $this->output->writeln();
    }
}
