<?php

namespace ShipPHP\Commands;

/**
 * Move Command
 * Move or copy files on the server
 */
class MoveCommand extends BaseCommand
{
    public function execute($options)
    {
        $this->initConfig();
        $this->initState();
        $this->initApi();

        $this->header("ShipPHP Move/Copy");

        $mode = $this->resolveMode($options);
        $destination = $this->getParam($options, 'to') ?? $this->getParam($options, 'dest');
        $selectAll = $this->hasFlag($options, 'select-all');
        $dryRun = $this->hasFlag($options, 'dry-run');
        $force = $this->hasFlag($options, 'force');
        $yes = $this->hasFlag($options, 'yes') || $this->hasFlag($options, 'y');
        $plan = $this->hasFlag($options, 'plan');

        $paths = $options['args'] ?? [];
        $selectParam = $this->getParam($options, 'select');
        $fromParam = $this->getParam($options, 'from');

        if ($selectParam) {
            $extra = array_filter(array_map('trim', explode(',', $selectParam)));
            $paths = array_merge($paths, $extra);
        }

        if (!$destination) {
            $this->output->error("Please specify a destination using --to=path.");
            $this->output->writeln("Usage: " . $this->cmd('move <path> --to=destination [--copy|--cut]'));
            return;
        }

        $dangerInputs = array_filter(array_merge([$destination], $paths, [$fromParam]));
        if (!$this->requireForceForDangerous($dangerInputs, $force, 'move files')) {
            return;
        }

        if ($selectAll && empty($paths) && !$fromParam) {
            $this->output->error("Please provide a source path for --select-all.");
            $this->output->writeln("Usage: " . $this->cmd('move <path> --select-all --to=destination'));
            return;
        }

        if (!$selectAll && empty($paths)) {
            $this->output->error("Please specify at least one file or directory to move.");
            $this->output->writeln("Usage: " . $this->cmd('move <path> [path2 ...] --to=destination'));
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

        $allServerPaths = array_keys($serverFiles);
        $selectedFiles = [];
        $missing = [];

        if ($selectAll) {
            $basePath = $fromParam ?? $paths[0];
            $selectedFiles = $this->filterPaths($allServerPaths, $basePath);
            if (empty($selectedFiles)) {
                $this->output->warning("No matching files found for '{$basePath}'.");
                return;
            }
        } else {
            foreach ($paths as $path) {
                $path = trim($path);
                if ($path === '') {
                    continue;
                }

                $matches = $this->filterPaths($allServerPaths, $path);
                if (empty($matches)) {
                    $missing[] = $path;
                    continue;
                }

                $selectedFiles = array_merge($selectedFiles, $matches);
            }
        }

        if (!empty($missing)) {
            $this->output->warning("Some paths were not found on the server:");
            foreach ($missing as $missingPath) {
                $this->output->writeln("  - {$missingPath}");
            }
            $this->output->writeln();
        }

        $selectedFiles = array_values(array_unique($selectedFiles));

        if (empty($selectedFiles)) {
            $this->output->warning("No matching files found on the server.");
            return;
        }

        $destinationRoot = trim($destination, '/');
        $baseDir = $selectAll ? trim($fromParam ?? $paths[0], '/') : $this->commonBase($selectedFiles);

        $mappings = [];
        $destinationPaths = [];

        foreach ($selectedFiles as $file) {
            $relative = $this->relativePath($file, $baseDir);
            $target = $destinationRoot === '' ? $relative : $destinationRoot . '/' . $relative;

            if ($file === $target) {
                continue;
            }

            $mappings[] = [
                'from' => $file,
                'to' => $target
            ];
            $destinationPaths[] = $target;
        }

        if (empty($mappings)) {
            $this->output->warning("All selected files already match the destination path.");
            return;
        }

        $this->output->writeln();
        $this->output->writeln("Mode: " . $this->output->colorize(strtoupper($mode), $mode === 'copy' ? 'cyan' : 'yellow'));
        $this->output->writeln("Destination: " . $this->output->colorize($destinationRoot === '' ? '/' : $destinationRoot, 'green'));
        $this->output->writeln("Files: " . $this->output->colorize((string)count($mappings), 'cyan'));

        $this->output->writeln();
        $this->output->writeln($this->output->colorize("Source selection:", 'cyan'));
        $sourcePaths = array_map(function ($path) use ($baseDir) {
            return $this->relativePath($path, $baseDir);
        }, $selectedFiles);
        $this->renderTreePreview($sourcePaths, $baseDir === '' ? '/' : $baseDir);

        $this->output->writeln();
        $this->output->writeln($this->output->colorize("Destination preview:", 'cyan'));
        $destinationRelative = array_map(function ($path) use ($destinationRoot) {
            return $this->relativePath($path, $destinationRoot);
        }, $destinationPaths);
        $this->renderTreePreview($destinationRelative, $destinationRoot === '' ? '/' : $destinationRoot);

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
                'type' => 'move',
                'mode' => $mode,
                'items' => $mappings
            ]);
            $planManager->save();
            $this->output->success("Added move operation to plan.");
            return;
        }

        if (!$force && !$yes) {
            $actionLabel = $mode === 'copy' ? 'copy' : 'move';
            if (!$this->output->confirm("Proceed to {$actionLabel} " . count($mappings) . " file(s)?", false)) {
                $this->output->writeln("Operation cancelled.\n");
                return;
            }
        }

        try {
            $response = $this->api->moveFiles($mappings, $mode);
        } catch (\Exception $e) {
            $this->output->error("Operation failed");
            $this->output->writeln($e->getMessage());
            return;
        }

        $moved = $response['moved'] ?? 0;
        $copied = $response['copied'] ?? 0;
        $failed = $response['failed'] ?? 0;

        if ($mode === 'copy') {
            $this->output->success("Copied {$copied} file(s).");
        } else {
            $this->output->success("Moved {$moved} file(s).");
        }

        if (!empty($response['errors'])) {
            $this->output->warning("Some items could not be processed:");
            foreach ($response['errors'] as $error) {
                $this->output->writeln("  - {$error}");
            }
        }

        if ($failed > 0) {
            $this->output->warning("{$failed} item(s) failed to process.");
        }

        try {
            $serverFiles = $this->api->listFiles();
            $this->state->updateServerFiles($serverFiles);
            $this->state->save();
        } catch (\Exception $e) {
            $this->output->warning("Operation complete, but failed to refresh server file list: " . $e->getMessage());
        }

        $this->output->writeln();
    }

    private function resolveMode($options)
    {
        $copy = $this->hasFlag($options, 'copy');
        $cut = $this->hasFlag($options, 'cut') || $this->hasFlag($options, 'move');

        if ($copy && $cut) {
            throw new \Exception("Please choose only one of --copy or --cut.");
        }

        return $copy ? 'copy' : 'move';
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

    private function commonBase(array $paths)
    {
        if (empty($paths)) {
            return '';
        }

        $segments = array_values(array_filter(explode('/', trim($paths[0], '/'))));

        foreach ($paths as $path) {
            $pathSegments = array_values(array_filter(explode('/', trim($path, '/'))));
            $max = min(count($segments), count($pathSegments));
            $newSegments = [];

            for ($i = 0; $i < $max; $i++) {
                if ($segments[$i] !== $pathSegments[$i]) {
                    break;
                }
                $newSegments[] = $segments[$i];
            }

            $segments = $newSegments;
            if (empty($segments)) {
                break;
            }
        }

        return implode('/', $segments);
    }

    private function relativePath($path, $baseDir)
    {
        $path = trim($path, '/');
        $baseDir = trim($baseDir, '/');

        if ($baseDir === '' || strpos($path, $baseDir . '/') !== 0) {
            return $path;
        }

        $relative = ltrim(substr($path, strlen($baseDir)), '/');
        if ($relative === '') {
            return basename($path);
        }

        return $relative;
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
