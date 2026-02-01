<?php

namespace ShipPHP\Commands;

/**
 * Grep Command
 * Search file contents on server
 */
class GrepCommand extends BaseCommand
{
    public function execute($options)
    {
        $this->initApi();

        $text = $this->getArg($options, 0);
        $path = $this->getParam($options, 'path', '');
        $pattern = $this->getParam($options, 'pattern', '*');
        $max = intval($this->getParam($options, 'max', 50));
        $caseSensitive = $this->hasFlag($options, 'case') || $this->hasFlag($options, 'i');
        $context = intval($this->getParam($options, 'context', 0));

        if (empty($text)) {
            $this->output->error("Usage: " . $this->cmd('grep') . " <search-text>");
            $this->output->writeln();
            $this->output->writeln("Examples:");
            $this->output->writeln("  " . $this->cmd('grep') . " \"function\"              Search in all files");
            $this->output->writeln("  " . $this->cmd('grep') . " \"TODO\" --pattern=\"*.php\" Search in PHP files");
            $this->output->writeln("  " . $this->cmd('grep') . " \"error\" --path=src       Search in specific directory");
            $this->output->writeln();
            $this->output->writeln("Options:");
            $this->output->writeln("  --path=dir        Search in specific directory");
            $this->output->writeln("  --pattern=*.php   File pattern filter");
            $this->output->writeln("  --max=N           Maximum file results (default: 50)");
            $this->output->writeln("  --case, -i        Case-sensitive search");
            $this->output->writeln("  --context=N       Show N lines before/after match");
            return;
        }

        $this->header("Search Content (Grep)");

        $this->output->writeln("Searching for: " . $this->output->colorize("\"$text\"", 'cyan'));
        if (!empty($path)) {
            $this->output->writeln("In path: " . $this->output->colorize($path, 'cyan'));
        }
        if ($pattern !== '*') {
            $this->output->writeln("File pattern: " . $this->output->colorize($pattern, 'cyan'));
        }
        $this->output->writeln();

        try {
            $response = $this->api->grep($text, $path, $pattern, $max, $caseSensitive, $context);
            $results = $response['results'] ?? [];

            if (empty($results)) {
                $this->output->warning("No matches found for '{$text}'");
                $this->output->writeln();
                return;
            }

            $totalMatches = 0;
            foreach ($results as $file) {
                $totalMatches += count($file['matches'] ?? []);
            }

            $this->output->success("Found {$totalMatches} match(es) in " . count($results) . " file(s)");
            if ($response['truncated'] ?? false) {
                $this->output->writeln($this->output->colorize("  (results truncated, use --max to show more)", 'yellow'));
            }
            $this->output->writeln();

            // Display results
            foreach ($results as $file) {
                $this->output->writeln($this->output->colorize($file['path'], 'green') . ":");

                foreach ($file['matches'] as $match) {
                    // Show context before if available
                    if (!empty($match['before'])) {
                        foreach ($match['before'] as $idx => $line) {
                            $lineNum = $match['line'] - count($match['before']) + $idx;
                            $this->output->writeln($this->output->colorize("  {$lineNum}: ", 'dim') . trim($line));
                        }
                    }

                    // Highlight matching line
                    $lineContent = $match['content'];
                    $highlighted = str_ireplace(
                        $text,
                        $this->output->colorize($text, 'yellow'),
                        $lineContent
                    );
                    $this->output->writeln($this->output->colorize("  {$match['line']}: ", 'cyan') . $highlighted);

                    // Show context after if available
                    if (!empty($match['after'])) {
                        foreach ($match['after'] as $idx => $line) {
                            $lineNum = $match['line'] + $idx + 1;
                            $this->output->writeln($this->output->colorize("  {$lineNum}: ", 'dim') . trim($line));
                        }
                    }
                }

                $this->output->writeln();
            }
        } catch (\Exception $e) {
            $this->output->error($e->getMessage());
        }

        $this->output->writeln();
    }
}
