<?php

namespace ShipPHP\Commands;

/**
 * Plan Command
 * Show or clear queued operations
 */
class PlanCommand extends BaseCommand
{
    public function execute($options)
    {
        $this->initConfig();
        $planManager = $this->initPlan();

        $action = $this->getArg($options, 0) ?? 'list';
        $this->header("ShipPHP Plan");

        if ($action === 'clear') {
            $planManager->clear();
            $this->output->success("Plan cleared.");
            return;
        }

        $operations = $planManager->getOperations();
        if (empty($operations)) {
            $this->output->warning("Plan is empty.");
            return;
        }

        $this->output->writeln($this->output->colorize("Queued operations:", 'cyan'));
        foreach ($operations as $index => $operation) {
            $type = $operation['type'] ?? 'unknown';
            $this->output->writeln("  " . ($index + 1) . ". {$type}");
            $this->renderOperationDetails($operation);
        }

        $this->output->writeln();
    }

    private function renderOperationDetails(array $operation)
    {
        $type = $operation['type'] ?? '';

        if ($type === 'delete') {
            $trash = $operation['trash'] ?? true;
            $paths = $operation['paths'] ?? [];
            $this->output->writeln("     mode: " . ($trash ? 'trash' : 'permanent'));
            foreach ($paths as $path) {
                $this->output->writeln("     - {$path}");
            }
            return;
        }

        if ($type === 'move') {
            $mode = $operation['mode'] ?? 'move';
            $items = $operation['items'] ?? [];
            $this->output->writeln("     mode: {$mode}");
            foreach ($items as $item) {
                $from = $item['from'] ?? '';
                $to = $item['to'] ?? '';
                $this->output->writeln("     - {$from} → {$to}");
            }
            return;
        }

        if ($type === 'rename') {
            $items = $operation['items'] ?? [];
            foreach ($items as $item) {
                $from = $item['from'] ?? '';
                $to = $item['to'] ?? '';
                $this->output->writeln("     - {$from} → {$to}");
            }
        }
    }
}
