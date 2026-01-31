<?php

namespace ShipPHP\Commands;

/**
 * Copy Command
 * Copy files/directories on server
 */
class CopyCommand extends BaseCommand
{
    public function execute($options)
    {
        $this->initApi();

        $source = $this->getArg($options, 0);
        $destination = $this->getParam($options, 'to');
        $overwrite = $this->hasFlag($options, 'force') || $this->hasFlag($options, 'overwrite');

        if (empty($source) || empty($destination)) {
            $this->output->error("Usage: " . $this->cmd('copy') . " <source> --to=<destination>");
            $this->output->writeln();
            $this->output->writeln("Examples:");
            $this->output->writeln("  " . $this->cmd('copy') . " config.php --to=config.bak");
            $this->output->writeln("  " . $this->cmd('copy') . " uploads --to=uploads-backup");
            $this->output->writeln("  " . $this->cmd('copy') . " file.txt --to=backup/file.txt --force");
            $this->output->writeln();
            $this->output->writeln("Options:");
            $this->output->writeln("  --to=path     Destination path (required)");
            $this->output->writeln("  --force       Overwrite if exists");
            return;
        }

        $source = $this->normalizeRelativePath($source);
        $destination = $this->normalizeRelativePath($destination);

        $this->header("Copy");

        $this->output->writeln("Source:      " . $this->output->colorize($source, 'cyan'));
        $this->output->writeln("Destination: " . $this->output->colorize($destination, 'cyan'));
        if ($overwrite) {
            $this->output->writeln($this->output->colorize("  (overwrite mode)", 'yellow'));
        }
        $this->output->writeln();

        try {
            $response = $this->api->copyFile($source, $destination, $overwrite);

            $this->output->success("Copy successful");
            $this->output->writeln("  From: {$source}");
            $this->output->writeln("  To:   {$destination}");
        } catch (\Exception $e) {
            $this->output->error($e->getMessage());
        }

        $this->output->writeln();
    }
}
