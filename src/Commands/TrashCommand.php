<?php

namespace ShipPHP\Commands;

/**
 * Trash Command
 * List and restore trashed files on the server
 */
class TrashCommand extends BaseCommand
{
    public function execute($options)
    {
        $this->initConfig();
        $this->initState();
        $this->initApi();

        $action = $this->getArg($options, 0) ?? 'list';
        $force = $this->hasFlag($options, 'force');

        $this->header("ShipPHP Trash");

        if ($action === 'list') {
            try {
                $items = $this->api->listTrash();
            } catch (\Exception $e) {
                $this->output->error("Failed to list trash");
                $this->output->writeln($e->getMessage());
                return;
            }

            if (empty($items)) {
                $this->output->warning("Trash is empty.");
                return;
            }

            $this->output->writeln($this->output->colorize("Trashed items:", 'cyan'));
            foreach ($items as $item) {
                $id = $item['id'] ?? 'unknown';
                $path = $item['path'] ?? 'unknown';
                $time = $item['trashed_at'] ?? '';
                $this->output->writeln("  {$id}  {$path}  {$time}");
            }

            $this->output->writeln();
            return;
        }

        if ($action === 'restore') {
            $id = $this->getArg($options, 1);
            if (!$id) {
                $this->output->error("Please specify a trash id to restore.");
                $this->output->writeln("Usage: " . $this->cmd('trash restore <id>'));
                return;
            }

            try {
                $this->api->restoreTrash($id, $force);
                $this->output->success("Trash item restored.");
            } catch (\Exception $e) {
                $this->output->error("Restore failed");
                $this->output->writeln($e->getMessage());
            }

            return;
        }

        $this->output->error("Unknown trash action: {$action}");
        $this->output->writeln("Usage: " . $this->cmd('trash [list|restore <id>]'));
    }
}
