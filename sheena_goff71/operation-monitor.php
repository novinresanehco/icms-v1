<?php

namespace App\Core\Monitoring;

class OperationMonitor
{
    private MetricsCollector $metrics;
    private AlertSystem $alerts;
    private ComplianceChecker $compliance;

    public function monitorOperation(Operation $operation): void
    {
        try {
            $this->startMonitoring($operation);
            $this->validatePerformance();
            $this->checkCompliance();
            $this->enforceThresholds();
        } catch (MonitoringException $e) {
            $this->handleMonitoringFailure($e);
            throw $e;
        }
    }

    private function startMonitoring(Operation $operation): void
    {
        $this->metrics->startCollection($operation);
        $this->alerts->activate();
    }

    private function validatePerformance(): void
    {
        $metrics = $this->metrics->getCurrentMetrics();
        if (!$this->isPerformanceValid($metrics)) {
            throw new PerformanceException();
        }
    }

    private function checkCompliance(): void
    {
        if (!$this->compliance->verify()) {
            throw new ComplianceException();
        }
    }
}
