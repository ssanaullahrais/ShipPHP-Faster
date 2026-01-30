<?php

namespace ShipPHP\Commands;

/**
 * Extract Command
 * Extract a zip archive on the server
 */
class ExtractCommand extends BaseCommand
{
    public function execute($options)
    {
        $this->initConfig();
        $this->initApi();

        $archive = $this->getArg($options, 0);
        $destination = $this->getParam($options, 'to') ?? $this->getParam($options, 'dest');
        $force = $this->hasFlag($options, 'force');
        $dryRun = $this->hasFlag($options, 'dry-run');

        $this->header("ShipPHP Extract");

        if (!$archive) {
            $this->output->error("Please specify the archive to extract.");
            $this->output->writeln("Usage: " . $this->cmd('extract <archive.zip> [--to=path]'));
            return;
        }

        $this->output->writeln("Archive: " . $this->output->colorize($archive, 'cyan'));
        if ($destination) {
            $this->output->writeln("Destination: " . $this->output->colorize($destination, 'cyan'));
        }
        $this->output->writeln();

        if ($dryRun) {
            $this->output->info("Dry run: no changes will be made.");
        }

        if (!$force && !$dryRun) {
            if (!$this->output->confirm("Extract '{$archive}' on the server?", false)) {
                $this->output->writeln("Extract cancelled.\n");
                return;
            }
        }

        if ($dryRun) {
            $this->output->success("Dry run complete.");
            return;
        }

        try {
            $result = $this->api->extractArchive($archive, $destination, $force);
            $count = $result['extracted'] ?? 0;
            $targetPath = $result['destination'] ?? ($destination ?: dirname($archive));
            $this->output->success("Extracted {$count} files to {$targetPath}");
        } catch (\Exception $e) {
            $this->output->error("Extract failed");
            $this->output->writeln($e->getMessage());
            return;
        }

        $this->output->writeln();
    }
}
