// File: app/Core/Scheduler/Monitoring/TaskMonitor.php
<?php

namespace App\Core\Scheduler\Monitoring;

class TaskMonitor
{
    protected MetricsCollector $metrics;
    protected HealthChecker $healthChecker;
    protected AlertManager $alertManager;

    public function monitor(Task $task, Execution $execution): void
    {
        // Collect metrics
        $metrics = $this->collectMetrics($task, $execution);
        
        // Check health
        $health = $this->checkHealth($metrics);
        
        // Handle alerts
        if (!$health->isHealthy()) {
            $this->handleAlerts($task, $health);
        }
        
        // Update statistics
        $this->updateStatistics($task, $metrics);
    }

    protected function collectMetrics(Task $task, Execution $execution): array
    {
        return [
            'execution_time' => $execution->getDuration(),
            'memory_usage' => $execution->getMemoryUsage(),
            'status' => $execution->getStatus(),
            'attempt' => $execution->getAttempt()
        ];
    }

    protected function handleAlerts(Task $task, HealthStatus $health): void
    {
        foreach ($health->getIssues() as $issue) {
            $this->alertManager->dispatch(
                new TaskHealthAlert($task, $issue)
            );
        }
    }
}
