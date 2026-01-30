<?php

namespace ShipPHP\Commands;

/**
 * Tree Command
 * Display server file tree
 */
class TreeCommand extends BaseCommand
{
    public function execute($options)
    {
        $this->initConfig();
        $this->initApi();

        $path = $this->getArg($options, 0);
        $depth = $this->getParam($options, 'depth');
        $maxDepth = $depth !== null ? max(1, (int)$depth) : null;

        $this->header("ShipPHP Server Tree");

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

        if ($path) {
            $this->output->writeln($this->output->colorize("Filtering for: {$path}", 'cyan'));
            $paths = array_values(array_filter($paths, function ($file) use ($path) {
                return $this->pathMatches($file, $path);
            }));
        }

        if (empty($paths)) {
            $this->output->warning("No matching files found on the server.");
            return;
        }

        sort($paths);
        $tree = $this->buildTree($paths);

        $this->output->writeln();
        $rootLabel = $path ? rtrim($path, '/') : '/';
        $this->output->writeln($this->output->colorize($rootLabel, 'cyan'));
        $this->renderTree($tree, '', 0, $maxDepth);
        $this->output->writeln();
    }

    /**
     * Build a tree structure from file paths.
     */
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

    /**
     * Render the tree structure.
     */
    private function renderTree(array $tree, $prefix, $depth, $maxDepth)
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
                if ($maxDepth !== null && ($depth + 1) >= $maxDepth) {
                    $this->output->writeln($childPrefix . '└── …');
                } else {
                    $this->renderTree($tree[$name], $childPrefix, $depth + 1, $maxDepth);
                }
            }
        }
    }

    /**
     * Check if a file path matches the specified path/pattern.
     */
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
}
