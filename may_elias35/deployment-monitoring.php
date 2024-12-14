// File: app/Core/Deployment/Monitoring/DeploymentMonitor.php
<?php

namespace App\Core\Deployment\Monitoring;

class DeploymentMonitor
{
    protected MetricsCollector $metrics;
    protected HealthChecker $healthChecker;
    protected AlertManager $alertManager;
    protected DeploymentConfig $config;

    public function monitor(Release $release): MonitoringResult
    {
        // Collect metrics
        $metrics = $this->metrics->collect($release);
        
        // Check health
        $health = $this->healthChecker->check($release);
        
        // Check thresholds
        $this->checkThresholds($metrics);
        
        return new MonitoringResult([
            'metrics' => $metrics,
            'health' => $health,
            'status' => $this->determineStatus($metrics, $health)
        ]);
    }

    protected function checkThresholds(array $metrics): void
    {
        foreach ($metrics as $metric => $value) {
            if ($this->isThresholdExceeded($metric, $value)) {
                $this->alertManager->notify(new ThresholdAlert($metric, $value));
            }
        }
    }

    protected function determineStatus(array $metrics, HealthStatus $health): string
    {
        if (!$health->isHealthy()) {
            return DeploymentStatus::UNHEALTHY;
        }

        if ($this->hasWarnings($metrics)) {
            return DeploymentStatus::WARNING;
        }

        return DeploymentStatus::HEALTHY;
    }
}
