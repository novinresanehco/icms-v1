<?php
namespace App\Core\Monitoring;

class MonitoringService implements MonitoringInterface
{
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private SecurityCore $security;
    
    public function monitor(): void
    {
        $metrics = $this->metrics->collect();

        foreach ($metrics as $metric) {
            $this->analyzeMetric($metric);
        }
    }

    private function analyzeMetric(Metric $metric): void
    {
        if ($metric->isCritical()) {
            $this->handleCriticalMetric($metric);
        }
    }

    private function handleCriticalMetric(Metric $metric): void
    {
        $this->alerts->sendCritical($metric);
        $this->security->logSecurityEvent('critical_metric', $metric);
    }
}

class DeploymentManager implements DeploymentInterface
{
    private SecurityCore $security;
    private ValidationService $validator;
    private BackupService $backup;

    public function deploy(): DeploymentResult
    {
        return $this->security->executeCriticalOperation(
            new DeploymentOperation(
                $this->validator,
                $this->backup
            )
        );
    }

    public function rollback(): void
    {
        $this->security->executeCriticalOperation(
            new RollbackOperation($this->backup)
        );
    }
}
