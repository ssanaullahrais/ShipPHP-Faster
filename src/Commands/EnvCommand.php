<?php

namespace ShipPHP\Commands;

/**
 * Env Command
 * Switch between environments
 */
class EnvCommand extends BaseCommand
{
    public function execute($options)
    {
        $this->initConfig();

        $this->header("ShipPHP Environment");

        $envName = $this->getArg($options, 0);

        // If no environment specified, show current
        if (!$envName) {
            return $this->showEnvironments();
        }

        // Switch environment
        try {
            $this->config->switchEnv($envName);
            $this->config->save();

            $this->output->success("Switched to environment: {$envName}");
            $this->output->writeln();
        } catch (\Exception $e) {
            $this->output->error($e->getMessage());
            $this->output->writeln();
        }
    }

    /**
     * Show all environments
     */
    private function showEnvironments()
    {
        $current = $this->config->get('currentEnv');
        $environments = $this->config->get('environments', []);

        $this->output->writeln($this->output->colorize("Available environments:", 'cyan'));
        $this->output->writeln();

        foreach ($environments as $name => $env) {
            $marker = ($name === $current) ? '●' : '○';
            $color = ($name === $current) ? 'green' : 'white';

            $this->output->writeln(
                sprintf(
                    "  %s %s",
                    $this->output->colorize($marker, $color),
                    $name
                ),
                $color
            );
            $this->output->writeln("    Server: " . ($env['serverUrl'] ?? 'not configured'), 'dim');
        }

        $this->output->writeln();
        $this->output->writeln($this->output->colorize("Usage:", 'cyan'));
        $this->output->writeln("  " . $this->cmd('env <name>') . "  - Switch to environment");
        $this->output->writeln();
    }
}
