<?php

namespace ShipPHP\Commands;

/**
 * Health Command
 * Check server health status with detailed diagnostics
 */
class HealthCommand extends BaseCommand
{
    public function execute($options)
    {
        $this->initConfig();
        $this->initApi();

        $detailed = $this->hasFlag($options, 'detailed') || $this->hasFlag($options, 'd');

        $this->header("ShipPHP Server Health Check");

        // Test connection first
        $this->output->write("Connecting to server... ");
        try {
            // CRITICAL FIX: Health endpoint may return 503 (unhealthy status) - that's valid!
            // We need to disable retries for health checks since 503 is an expected response
            $this->api->setRetryPolicy(0, 0); // No retries for health checks
            $response = $this->api->requestRaw('health', []);
            $this->output->success("Connected");
        } catch (\Exception $e) {
            $this->output->error("Connection failed");
            $this->output->writeln();
            $this->output->error($e->getMessage());
            $this->output->writeln();
            $this->output->writeln("💡 Troubleshooting:");
            $this->output->writeln("  1. Check your internet connection");
            $this->output->writeln("  2. Verify serverUrl in shipphp.json");
            $this->output->writeln("  3. Ensure shipphp-server.php is uploaded");
            $this->output->writeln("  4. Run: " . $this->cmd('status') . " to test basic connectivity");
            $this->output->writeln();
            return;
        }

        $this->output->writeln();

        // Check if response is valid
        if (!isset($response['success']) || !$response['success']) {
            $this->output->error("Health check failed");
            $this->output->writeln();
            if (isset($response['error'])) {
                $this->output->writeln("Error: " . $response['error']);
            }
            $this->output->writeln();
            return;
        }

        $data = $response;
        $status = $data['status'] ?? 'unknown';
        $checks = $data['checks'] ?? [];

        // Overall status
        $this->output->writeln($this->output->colorize("═══════════════════════════════════════════════════", 'cyan'));
        $this->output->writeln();

        $statusIcon = '✓';
        $statusColor = 'green';
        $statusText = 'HEALTHY';

        if ($status === 'degraded') {
            $statusIcon = '⚠';
            $statusColor = 'yellow';
            $statusText = 'DEGRADED';
        } elseif ($status === 'unhealthy') {
            $statusIcon = '✗';
            $statusColor = 'red';
            $statusText = 'UNHEALTHY';
        }

        $this->output->writeln("  Overall Status: " . $this->output->colorize("{$statusIcon} {$statusText}", $statusColor));
        $this->output->writeln("  Timestamp: " . ($data['timestamp'] ?? date('c')));
        $this->output->writeln();
        $this->output->writeln($this->output->colorize("═══════════════════════════════════════════════════", 'cyan'));
        $this->output->writeln();

        // Disk Space Check
        if (isset($checks['disk'])) {
            $disk = $checks['disk'];
            $diskStatus = $disk['status'] ?? 'unknown';

            $icon = $diskStatus === 'ok' ? '✓' : ($diskStatus === 'warning' ? '⚠' : '✗');
            $color = $diskStatus === 'ok' ? 'green' : ($diskStatus === 'warning' ? 'yellow' : 'red');

            $this->output->writeln($this->output->colorize("{$icon} Disk Space", $color));

            if (isset($disk['free']) && isset($disk['total'])) {
                $this->output->writeln("  Free: " . $disk['free']);
                $this->output->writeln("  Total: " . $disk['total']);
                $this->output->writeln("  Used: " . ($disk['used_percent'] ?? 0) . "%");

                if ($diskStatus === 'warning') {
                    $this->output->warning("  ⚠ Warning: Disk usage above 90%");
                }
            } else {
                $this->output->writeln("  Status: " . ($diskStatus === 'unknown' ? 'Unable to check' : $diskStatus));
            }
            $this->output->writeln();
        }

        // Write Permission Check
        if (isset($checks['write_permission'])) {
            $perm = $checks['write_permission'];
            $permStatus = $perm['status'] ?? 'unknown';

            $icon = $permStatus === 'ok' ? '✓' : '✗';
            $color = $permStatus === 'ok' ? 'green' : 'red';

            $this->output->writeln($this->output->colorize("{$icon} Write Permission", $color));
            $this->output->writeln("  Status: " . ucfirst($permStatus));

            if ($permStatus !== 'ok') {
                $this->output->error("  ✗ Error: Server cannot write files");
                $this->output->writeln("  Fix: Check directory permissions (should be 755)");
            }
            $this->output->writeln();
        }

        // Backups Check
        if (isset($checks['backups'])) {
            $backup = $checks['backups'];
            $backupStatus = $backup['status'] ?? 'unknown';

            $icon = $backupStatus === 'ok' ? '✓' : ($backupStatus === 'warning' ? '⚠' : '✗');
            $color = $backupStatus === 'ok' ? 'green' : ($backupStatus === 'warning' ? 'yellow' : 'red');

            $this->output->writeln($this->output->colorize("{$icon} Backups", $color));

            if (isset($backup['enabled'])) {
                $this->output->writeln("  Enabled: " . ($backup['enabled'] ? 'Yes' : 'No'));
            }

            if (isset($backup['dir_exists'])) {
                $this->output->writeln("  Directory Exists: " . ($backup['dir_exists'] ? 'Yes' : 'No'));
            }

            if (isset($backup['dir_writable'])) {
                $this->output->writeln("  Directory Writable: " . ($backup['dir_writable'] ? 'Yes' : 'No'));
            }

            if ($backupStatus === 'error') {
                $this->output->error("  ✗ Error: Backup directory not accessible");
            } elseif ($backupStatus === 'warning') {
                $this->output->warning("  ⚠ Warning: Backup directory exists but not writable");
            }
            $this->output->writeln();
        }

        // PHP Check
        if (isset($checks['php'])) {
            $php = $checks['php'];

            $this->output->writeln($this->output->colorize("✓ PHP Environment", 'green'));

            if (isset($php['version'])) {
                $this->output->writeln("  Version: PHP " . $php['version']);
            }

            if (isset($php['extensions']) && $detailed) {
                $this->output->writeln("  Extensions:");
                foreach ($php['extensions'] as $ext => $extStatus) {
                    $extIcon = $extStatus === 'ok' ? '✓' : '✗';
                    $extColor = $extStatus === 'ok' ? 'green' : 'red';
                    $this->output->writeln("    " . $this->output->colorize("{$extIcon} {$ext}", $extColor));
                }
            } elseif (isset($php['extensions'])) {
                $allOk = true;
                foreach ($php['extensions'] as $extStatus) {
                    if ($extStatus !== 'ok') {
                        $allOk = false;
                        break;
                    }
                }
                $this->output->writeln("  Extensions: " . ($allOk ? 'All OK' : 'Some missing (use --detailed)'));
            }
            $this->output->writeln();
        }

        // Summary and recommendations
        $this->output->writeln($this->output->colorize("═══════════════════════════════════════════════════", 'cyan'));
        $this->output->writeln();

        if ($status === 'healthy') {
            $this->output->success("🎉 Server is healthy and ready for deployment!");
            $this->output->writeln();
            $this->output->writeln("Next steps:");
            $this->output->writeln("  • Run: " . $this->cmd('status') . " - Check file changes");
            $this->output->writeln("  • Run: " . $this->cmd('push') . " - Deploy your changes");
            $this->output->writeln("  • Run: " . $this->cmd('backup create') . " - Create a backup");
        } elseif ($status === 'degraded') {
            $this->output->warning("⚠ Server is operational but has warnings");
            $this->output->writeln();
            $this->output->writeln("Recommended actions:");
            $this->output->writeln("  • Review warnings above");
            $this->output->writeln("  • Free up disk space if needed");
            $this->output->writeln("  • Check directory permissions");
            $this->output->writeln("  • Run: " . $this->cmd('health --detailed') . " - For more info");
        } else {
            $this->output->error("✗ Server has critical issues");
            $this->output->writeln();
            $this->output->writeln("Required actions:");
            $this->output->writeln("  • Fix critical errors listed above");
            $this->output->writeln("  • Verify server configuration");
            $this->output->writeln("  • Check file permissions (755 for dirs, 644 for files)");
            $this->output->writeln("  • Contact your hosting provider if needed");
        }

        $this->output->writeln();

        // Show tip about detailed mode
        if (!$detailed) {
            $this->output->writeln($this->output->colorize("💡 Tip:", 'cyan') . " Run " . $this->cmd('health --detailed') . " for more information");
            $this->output->writeln();
        }
    }
}
