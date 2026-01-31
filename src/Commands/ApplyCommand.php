<?php

namespace ShipPHP\Commands;

/**
 * Apply Command
 * Execute queued operations from plan.json
 */
class ApplyCommand extends BaseCommand
{
    public function execute($options)
    {
        $this->initConfig();
        $this->initState();
        $this->initApi();

        $planManager = $this->initPlan();
        $operations = $planManager->getOperations();
        $force = $this->hasFlag($options, 'force');

        $this->header("ShipPHP Apply Plan");

        if (empty($operations)) {
            $this->output->warning("Plan is empty.");
            return;
        }

        $this->output->writeln("Operations: " . $this->output->colorize((string)count($operations), 'cyan'));
        if (!$force) {
            if (!$this->output->confirm("Apply queued operations?", false)) {
                $this->output->writeln("Apply cancelled.\n");
                return;
            }
        }

        foreach ($operations as $operation) {
            $type = $operation['type'] ?? '';
            try {
                if ($type === 'delete') {
                    $paths = $operation['paths'] ?? [];
                    $trash = $operation['trash'] ?? true;
                    if ($trash) {
                        $this->api->trashFiles($paths);
                    } else {
                        foreach ($paths as $path) {
                            $this->api->deleteFile($path);
                        }
                    }
                    $this->output->success("Applied delete operation.");
                } elseif ($type === 'move') {
                    $items = $operation['items'] ?? [];
                    $mode = $operation['mode'] ?? 'move';
                    $this->api->moveFiles($items, $mode);
                    $this->output->success("Applied move operation.");
                } elseif ($type === 'rename') {
                    $items = $operation['items'] ?? [];
                    $this->api->renameFiles($items);
                    $this->output->success("Applied rename operation.");
                } else {
                    $this->output->warning("Skipping unknown operation type: {$type}");
                }
            } catch (\Exception $e) {
                $this->output->error("Operation failed: {$type}");
                $this->output->writeln($e->getMessage());
                return;
            }
        }

        try {
            $serverFiles = $this->api->listFiles();
            $this->state->updateServerFiles($serverFiles);
            $this->state->save();
        } catch (\Exception $e) {
            $this->output->warning("Applied plan, but failed to refresh server file list: " . $e->getMessage());
        }

        $planManager->clear();
        $this->output->success("Plan applied and cleared.");
        $this->output->writeln();
    }
}
