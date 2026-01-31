<?php

namespace ShipPHP\Core;

/**
 * Plan Manager
 * Stores queued operations for later execution
 */
class PlanManager
{
    private $planPath;
    private $plan;

    public function __construct($workingDir = null)
    {
        $workingDir = $workingDir ?: WORKING_DIR;
        $stateDir = ProjectPaths::stateDir($workingDir);
        if (!is_dir($stateDir)) {
            mkdir($stateDir, 0755, true);
        }
        $this->planPath = $stateDir . '/plan.json';
        $this->load();
    }

    public function load()
    {
        if (!file_exists($this->planPath)) {
            $this->plan = [
                'created' => date('c'),
                'operations' => []
            ];
            return;
        }

        $json = file_get_contents($this->planPath);
        $data = json_decode($json, true);
        $this->plan = is_array($data) ? $data : ['created' => date('c'), 'operations' => []];
    }

    public function addOperation(array $operation)
    {
        $operation['queued_at'] = date('c');
        $this->plan['operations'][] = $operation;
        return $this;
    }

    public function getOperations()
    {
        return $this->plan['operations'] ?? [];
    }

    public function save()
    {
        $json = json_encode($this->plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($this->planPath, $json) === false) {
            throw new \Exception("Failed to write plan.json");
        }
    }

    public function clear()
    {
        $this->plan = [
            'created' => date('c'),
            'operations' => []
        ];
        if (file_exists($this->planPath)) {
            unlink($this->planPath);
        }
    }
}
