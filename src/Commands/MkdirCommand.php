<?php

namespace ShipPHP\Commands;

/**
 * Mkdir Command
 * Create directory on server
 */
class MkdirCommand extends BaseCommand
{
    public function execute($options)
    {
        $this->initApi();

        $path = $this->getArg($options, 0);

        if (empty($path)) {
            $this->output->error("Usage: " . $this->cmd('mkdir') . " <path>");
            $this->output->writeln("Example: " . $this->cmd('mkdir') . " uploads/images");
            return;
        }

        $path = $this->normalizeRelativePath($path);

        $this->header("Create Directory");

        $this->output->writeln("Creating directory: " . $this->output->colorize($path, 'cyan'));
        $this->output->writeln();

        try {
            $response = $this->api->mkdir($path);

            $this->output->success($response['message'] ?? 'Directory created');

            if (isset($response['mode'])) {
                $this->output->writeln("  Mode: " . $response['mode']);
            }
        } catch (\Exception $e) {
            $this->output->error($e->getMessage());
        }

        $this->output->writeln();
    }
}
