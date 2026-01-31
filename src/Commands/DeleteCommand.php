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
        $yes = $this->hasFlag($options, 'yes') || $this->hasFlag($options, 'y');
        $pattern = $this->getParam($options, 'pattern');
        $exclude = $this->getParam($options, 'exclude');
        $permanent = $this->hasFlag($options, 'permanent');
        $trash = !$permanent;
        $plan = $this->hasFlag($options, 'plan');

        $this->header("ShipPHP Delete");

        if (!$path && !$pattern) {
            $this->output->error("Please specify a file, directory, or pattern to delete.");
            $this->output->writeln("Usage: " . $this->cmd('delete <path> [--pattern=glob]'));
            return;
        }

        $inputs = array_filter([$path, $pattern]);
        if (!$this->requireForceForDangerous($inputs, $force, 'delete')) {
            return;
        }

        $targets = [];
        $useList = $pattern || $exclude || ($path && strpos($path, '*') !== false);

        if ($useList) {
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
            $targets = $this->filterPaths($paths, $patternToUse);
            $targets = $this->applyExcludes($targets, $exclude);
        } else {
            $targets = [$path];
        }

        if (empty($targets)) {
            $this->output->warning("No matching files found on the server.");
            return;
        }

        $this->output->writeln("Mode: " . $this->output->colorize($trash ? 'TRASH' : 'PERMANENT', $trash ? 'cyan' : 'red'));
        $this->output->writeln("Targets: " . $this->output->colorize((string)count($targets), 'cyan'));
        $this->output->writeln();

        if ($useList) {
            $this->output->writeln($this->output->colorize("Delete preview:", 'cyan'));
            $this->renderTreePreview($targets, '/');
            $this->output->writeln();
        } else {
            $this->output->writeln("Target: " . $this->output->colorize($path, 'cyan'));
            $this->output->writeln();
        }

        if ($dryRun) {
            $this->output->info("Dry run: no changes will be made.");
            return;
        }

        if ($plan) {
            $planManager = $this->initPlan();
            $planManager->addOperation([
                'type' => 'delete',
                'trash' => $trash,
                'paths' => $targets
            ]);
            $planManager->save();
            $this->output->success("Added delete operation to plan.");
            return;
        }

        if (!$force && !$yes) {
            if (!$this->output->confirm(($trash ? "Trash" : "Delete") . " " . count($targets) . " item(s) from the server?", false)) {
                $this->output->writeln("Delete cancelled.\n");
                return;
            }
        }

        try {
            if ($trash) {
                $response = $this->api->trashFiles($targets);
                $trashed = $response['trashed'] ?? 0;
                $this->output->success("Trashed {$trashed} item(s).");
                if (!empty($response['errors'])) {
                    $this->output->warning("Some items could not be trashed:");
                    foreach ($response['errors'] as $error) {
                        $this->output->writeln("  - {$error}");
                    }
                }
            } else {
                foreach ($targets as $target) {
                    $this->api->deleteFile($target);
                }
                $this->output->success("Deleted " . count($targets) . " item(s) from server.");
            }
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

    private function renderTreePreview(array $paths, $rootLabel)
    {
        $paths = array_filter(array_map(function ($path) {
            return trim($path, '/');
        }, $paths));

        if (empty($paths)) {
            $this->output->warning("No files to preview.");
            return;
        }

        sort($paths);
        $tree = $this->buildTree($paths);
        $this->output->writeln($this->output->colorize($rootLabel, 'green'));
        $this->renderTree($tree, '');
    }

    private function buildTree(array $paths)
    {
        $tree = [];

        foreach ($paths as $path) {
            $segments = array_values(array_filter(explode('/', trim($path, '/'))));
            $current =& $tree;

            foreach ($segments as $segment) {
                if (!isset($current[$segment])) {
                    $current[$segment] = [];
                }
                $current =& $current[$segment];
            }
        }

        return $tree;
    }

    private function renderTree(array $tree, $prefix)
    {
        $keys = array_keys($tree);
        sort($keys, SORT_NATURAL | SORT_FLAG_CASE);
        $count = count($keys);

        foreach ($keys as $index => $name) {
            $isLast = ($index === $count - 1);
            $connector = $isLast ? '└── ' : '├── ';
            $childPrefix = $prefix . ($isLast ? '    ' : '│   ');
            $hasChildren = !empty($tree[$name]);

            $this->output->writeln($prefix . $connector . $name . ($hasChildren ? '/' : ''));

            if ($hasChildren) {
                $this->renderTree($tree[$name], $childPrefix);
            }
        }
    }
}
