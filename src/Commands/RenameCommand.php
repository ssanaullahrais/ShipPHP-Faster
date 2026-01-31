<?php

namespace ShipPHP\Commands;

/**
 * Rename Command
 * Batch rename files on the server using find/replace
 */
class RenameCommand extends BaseCommand
{
    public function execute($options)
    {
        $this->initConfig();
        $this->initState();
        $this->initApi();

        $this->header("ShipPHP Rename");

        $find = $this->getParam($options, 'find');
        $replace = $this->getParam($options, 'replace');
        $path = $this->getArg($options, 0);
        $pattern = $this->getParam($options, 'pattern');
        $exclude = $this->getParam($options, 'exclude');
        $dryRun = $this->hasFlag($options, 'dry-run');
        $force = $this->hasFlag($options, 'force');
        $plan = $this->hasFlag($options, 'plan');

        if ($find === null || $replace === null) {
            $this->output->error("Please provide --find and --replace values.");
            $this->output->writeln("Usage: " . $this->cmd('rename <path> --find=old --replace=new'));
            return;
        }

        if (!$path && !$pattern) {
            $this->output->error("Please specify a path or pattern to rename.");
            $this->output->writeln("Usage: " . $this->cmd('rename <path> --find=old --replace=new'));
            return;
        }

        if (!$this->requireForceForDangerous(array_filter([$path, $pattern]), $force, 'rename')) {
            return;
        }

        $this->output->write("Fetching server file list... ");
        try {
            $serverFiles = $this->api->listFiles();
            $this->output->success("Done");
        } catch (\Exception $e) {
            $this->output->error("Failed");
            $this->output->writeln($e->getMessage());
            return;
        }

        $paths = array_keys($serverFiles);
        $patternToUse = $pattern ?: $path;
        $matches = $this->filterPaths($paths, $patternToUse);
        $matches = $this->applyExcludes($matches, $exclude);

        if (empty($matches)) {
            $this->output->warning("No matching files found on the server.");
            return;
        }

        $mappings = [];
        $targets = [];
        $existing = array_flip($paths);

        foreach ($matches as $file) {
            $newPath = str_replace($find, $replace, $file);
            if ($newPath === $file) {
                continue;
            }
            if (isset($existing[$newPath])) {
                $this->output->warning("Skipping '{$file}' because destination exists: {$newPath}");
                continue;
            }
            $mappings[] = ['from' => $file, 'to' => $newPath];
            $targets[] = $newPath;
        }

        if (empty($mappings)) {
            $this->output->warning("No files would be renamed with the given pattern.");
            return;
        }

        $this->output->writeln("Find: " . $this->output->colorize($find, 'cyan'));
        $this->output->writeln("Replace: " . $this->output->colorize($replace, 'cyan'));
        $this->output->writeln("Files: " . $this->output->colorize((string)count($mappings), 'cyan'));

        $this->output->writeln();
        $this->output->writeln($this->output->colorize("Mapping preview:", 'cyan'));
        foreach ($mappings as $mapping) {
            $this->output->writeln("  {$mapping['from']} → {$mapping['to']}");
        }

        if ($dryRun) {
            $this->output->writeln();
            $this->output->info("Dry run: no changes will be made.");
            return;
        }

        if ($plan) {
            $planManager = $this->initPlan();
            $planManager->addOperation([
                'type' => 'rename',
                'items' => $mappings
            ]);
            $planManager->save();
            $this->output->success("Added rename operation to plan.");
            return;
        }

        if (!$force) {
            if (!$this->output->confirm("Proceed to rename " . count($mappings) . " file(s)?", false)) {
                $this->output->writeln("Rename cancelled.\n");
                return;
            }
        }

        try {
            $response = $this->api->renameFiles($mappings);
            $moved = $response['moved'] ?? 0;
            $this->output->success("Renamed {$moved} file(s).");
        } catch (\Exception $e) {
            $this->output->error("Rename failed");
            $this->output->writeln($e->getMessage());
            return;
        }

        try {
            $serverFiles = $this->api->listFiles();
            $this->state->updateServerFiles($serverFiles);
            $this->state->save();
        } catch (\Exception $e) {
            $this->output->warning("Rename complete, but failed to refresh server file list: " . $e->getMessage());
        }

        $this->output->writeln();
    }

    private function filterPaths(array $paths, $pattern)
    {
        $pattern = trim((string)$pattern);
        if ($pattern === '') {
            return [];
        }

        return array_values(array_filter($paths, function ($file) use ($pattern) {
            return $this->pathMatches($file, $pattern);
        }));
    }

    private function pathMatches($file, $pattern)
    {
        $file = str_replace('\\', '/', $file);
        $pattern = str_replace('\\', '/', $pattern);

        if ($file === $pattern) {
            return true;
        }

        if (strpos($file, rtrim($pattern, '/') . '/') === 0) {
            return true;
        }

        if (strpos($pattern, '*') !== false) {
            $regex = '#^' . str_replace(['*', '?'], ['.*', '.'], preg_quote($pattern, '#')) . '$#';
            return preg_match($regex, $file);
        }

        return false;
    }

    private function applyExcludes(array $paths, $exclude)
    {
        if (!$exclude) {
            return $paths;
        }

        $excludes = array_filter(array_map('trim', explode(',', $exclude)));

        if (empty($excludes)) {
            return $paths;
        }

        return array_values(array_filter($paths, function ($file) use ($excludes) {
            foreach ($excludes as $pattern) {
                if ($this->pathMatches($file, $pattern)) {
                    return false;
                }
            }
            return true;
        }));
    }
}
