// File: app/Core/Queue/Monitoring/QueueMonitor.php
<?php

namespace App\Core\Queue\Monitoring;

class QueueMonitor
{
    protected MetricsCollector $metrics;
    protected HealthChecker $healthChecker;
    protected AlertManager $alertManager;
    protected MonitorConfig $config;

    public function recordDispatch(DispatchResult $result): void
    {
        $this->metrics->recordDispatch($result);
        $this->checkThresholds();
    }

    public function recordProcessing(Job $job, ProcessResult $result): void
    {
        $this->metrics->recordProcessing($job, $result);
        
        if (!$result->isSuccessful()) {
            $this->alertManager->notifyFailure($job, $result);
        }
    }

    public function isHealthy(): bool
    {
        return $this->healthChecker->check([
            'memory_usage' => $this->metrics->getMemoryUsage(),
            'job_failure_rate' => $this->metrics->getFailureRate(),
            'queue_size' => $this->metrics->getQueueSize()
        ]);
    }

    protected function checkThresholds(): void
    {
        if ($this->metrics->exceedsThresholds()) {
            $this->alertManager->notifyThresholdExceeded(
                $this->metrics->getCurrentMetrics()
            );
        }
    }
}

// File: app/Core/Queue/Monitoring/MetricsCollector.php
<?php

namespace App\Core\Queue\Monitoring;

class MetricsCollector
{
    protected MetricsStorage $storage;
    protected array $currentMetrics = [];
    protected ThresholdConfig $thresholds;

    public function recordDispatch(DispatchResult $result): void
    {
        $metrics = [
            'timestamp' => now(),
            'queue' => $result->getQueue(),
            'job_type' => $result->getJobType(),
            'status' => $result->getStatus()
        ];

        $this->storage->store($metrics);
        $this->updateCurrentMetrics($metrics);
    }

    public function recordProcessing(Job $job, ProcessResult $result): void
    {
        $metrics = [
            'timestamp' => now(),
            'job_id' => $job->getId(),
            'processing_time' => $result->getProcessingTime(),
            'memory_usage' => $result->getMemoryUsage(),
            'status' => $result->getStatus()
        ];

        $this->storage->store($metrics);
        $this->updateCurrentMetrics($metrics);
    }

    public function exceedsThresholds(): bool
    {
        return $this->currentMetrics['failure_rate'] > $this->thresholds->maxFailureRate ||
               $this->currentMetrics['queue_size'] > $this->thresholds->maxQueueSize ||
               $this->currentMetrics['memory_usage'] > $this->thresholds->maxMemoryUsage;
    }
}
