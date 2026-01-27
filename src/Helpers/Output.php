<?php

namespace ShipPHP\Helpers;

/**
 * Output Helper
 * Handles all console output with colors and formatting
 */
class Output
{
    private $colors = [
        'reset' => "\033[0m",
        'bold' => "\033[1m",
        'dim' => "\033[2m",
        'underline' => "\033[4m",
        'blink' => "\033[5m",
        'reverse' => "\033[7m",
        'hidden' => "\033[8m",

        // Foreground colors
        'black' => "\033[30m",
        'red' => "\033[31m",
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'magenta' => "\033[35m",
        'cyan' => "\033[36m",
        'white' => "\033[37m",

        // Background colors
        'bg_black' => "\033[40m",
        'bg_red' => "\033[41m",
        'bg_green' => "\033[42m",
        'bg_yellow' => "\033[43m",
        'bg_blue' => "\033[44m",
        'bg_magenta' => "\033[45m",
        'bg_cyan' => "\033[46m",
        'bg_white' => "\033[47m",
    ];

    private $supportsColor;

    public function __construct()
    {
        // Detect color support
        $this->supportsColor = $this->hasColorSupport();
    }

    /**
     * Check if terminal supports colors
     */
    private function hasColorSupport()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            // Windows 10+ supports ANSI colors
            return getenv('ANSICON') !== false ||
                   getenv('ConEmuANSI') === 'ON' ||
                   getenv('TERM') === 'xterm' ||
                   (function_exists('sapi_windows_vt100_support') && sapi_windows_vt100_support(STDOUT));
        }

        return function_exists('posix_isatty') && @posix_isatty(STDOUT);
    }

    /**
     * Write text with optional color
     */
    public function write($text, $color = null)
    {
        if ($color && $this->supportsColor) {
            echo $this->colorize($text, $color);
        } else {
            echo $text;
        }
    }

    /**
     * Write line with optional color
     */
    public function writeln($text = '', $color = null)
    {
        $this->write($text . PHP_EOL, $color);
    }

    /**
     * Colorize text
     */
    public function colorize($text, $color)
    {
        if (!$this->supportsColor || !isset($this->colors[$color])) {
            return $text;
        }

        return $this->colors[$color] . $text . $this->colors['reset'];
    }

    /**
     * Success message (green)
     */
    public function success($message)
    {
        $this->writeln("✓ " . $message, 'green');
    }

    /**
     * Error message (red)
     */
    public function error($message)
    {
        $this->writeln("✗ " . $message, 'red');
    }

    /**
     * Warning message (yellow)
     */
    public function warning($message)
    {
        $this->writeln("⚠ " . $message, 'yellow');
    }

    /**
     * Info message (cyan)
     */
    public function info($message)
    {
        $this->writeln("ℹ " . $message, 'cyan');
    }

    /**
     * Show progress bar (BUG FIX: Prevent division by zero)
     */
    public function progressBar($current, $total, $width = 50)
    {
        // CRITICAL BUG FIX: Prevent division by zero
        if ($total <= 0) {
            $total = 1; // Prevent division by zero
        }

        $percent = ($current / $total) * 100;
        $filled = floor(($current / $total) * $width);
        $empty = max(0, $width - $filled); // Ensure non-negative

        $bar = '[' . str_repeat('█', (int)$filled) . str_repeat('░', (int)$empty) . ']';
        $percentStr = sprintf('%3d%%', min(100, $percent)); // Cap at 100%

        echo "\r" . $this->colorize($bar, 'green') . " {$percentStr} ({$current}/{$total})";

        if ($current >= $total) {
            echo PHP_EOL;
        }
    }

    /**
     * Show spinner animation
     */
    public function spinner($message = 'Processing')
    {
        static $frames = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
        static $index = 0;

        echo "\r" . $frames[$index] . " {$message}";
        $index = ($index + 1) % count($frames);
    }

    /**
     * Clear current line
     */
    public function clearLine()
    {
        echo "\r\033[K";
    }

    /**
     * Ask user for confirmation
     */
    public function confirm($question, $default = false)
    {
        $defaultText = $default ? 'Y/n' : 'y/N';
        $this->write("{$question} [{$defaultText}]: ", 'yellow');

        $handle = fopen('php://stdin', 'r');
        $line = fgets($handle);
        fclose($handle);

        $answer = trim(strtolower($line));

        if ($answer === '') {
            return $default;
        }

        return in_array($answer, ['y', 'yes']);
    }

    /**
     * Ask user for input
     */
    public function ask($question, $default = null)
    {
        $defaultText = $default ? " [{$default}]" : '';
        $this->write("{$question}{$defaultText}: ", 'cyan');

        $handle = fopen('php://stdin', 'r');
        $line = fgets($handle);
        fclose($handle);

        $answer = trim($line);

        return $answer === '' ? $default : $answer;
    }

    /**
     * Show a table
     */
    public function table($headers, $rows)
    {
        if (empty($rows)) {
            return;
        }

        // Calculate column widths
        $widths = array_map('strlen', $headers);

        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i], strlen($cell));
            }
        }

        // Header
        $this->writeln();
        $headerRow = '│ ';
        foreach ($headers as $i => $header) {
            $headerRow .= str_pad($header, $widths[$i]) . ' │ ';
        }
        $this->writeln(rtrim($headerRow), 'cyan');

        // Separator
        $separator = '├';
        foreach ($widths as $width) {
            $separator .= str_repeat('─', $width + 2) . '┼';
        }
        $this->writeln(rtrim($separator, '┼') . '┤', 'cyan');

        // Rows
        foreach ($rows as $row) {
            $rowStr = '│ ';
            foreach ($row as $i => $cell) {
                $rowStr .= str_pad($cell, $widths[$i]) . ' │ ';
            }
            $this->writeln(rtrim($rowStr));
        }

        $this->writeln();
    }

    /**
     * Show a box with message
     */
    public function box($message, $color = 'white')
    {
        $lines = explode("\n", $message);
        $maxLen = max(array_map('strlen', $lines));

        $this->writeln();
        $this->writeln('┌' . str_repeat('─', $maxLen + 2) . '┐', $color);

        foreach ($lines as $line) {
            $this->writeln('│ ' . str_pad($line, $maxLen) . ' │', $color);
        }

        $this->writeln('└' . str_repeat('─', $maxLen + 2) . '┘', $color);
        $this->writeln();
    }

    /**
     * Show section header
     */
    public function section($title)
    {
        $this->writeln();
        $this->writeln(str_repeat('═', strlen($title)), 'cyan');
        $this->writeln($title, 'cyan');
        $this->writeln(str_repeat('═', strlen($title)), 'cyan');
        $this->writeln();
    }
}
