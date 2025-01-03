<?php

namespace App\Core\Health;

class HealthResult 
{
    public function __construct(
        public readonly HealthStatus $status,
        public readonly string $message,
        public readonly array $metrics = []
    ) {}
}

class HealthReport
{
    public readonly HealthStatus $overall;
    public readonly array $results;
    public readonly \DateTime $timestamp;

    public function __construct(array $results)
    {
        $this->results = $results;
        $this->timestamp = new \DateTime();
        $this->overall = $this->calculateOverallStatus();
    }

    private function calculateOverallStatus(): HealthStatus
    {
        if (empty($this->results)) {
            return HealthStatus::Unknown;
        }

        foreach ($this->results as $result) {
            if ($result->status === HealthStatus::Critical) {
                return HealthStatus::Critical;
            }
        }

        foreach ($this->results as $result) {
            if ($result->status === HealthStatus::Warning) {
                return HealthStatus::Warning;
            }
        }

        return HealthStatus::Healthy;
    }
}

enum HealthStatus: string
{
    case Healthy = 'healthy';
    case Warning = 'warning';
    case Critical = 'critical';
    case Unknown = 'unknown';
}

class QueueHealthCheck implements HealthCheckInterface
{
    private Queue $queue;

    public function check(): HealthResult
    {
        try {
            $queueSize = $this->queue->size();
            $oldestJob = $this->queue->oldestJob();
            $failedCount = $this->queue->getFailedCount();

            if ($oldestJob && $oldestJob->age() > 3600) {
                return new HealthResult(
                    HealthStatus::Critical,
                    "Jobs not being processed",
                    [
                        'queue_size' => $queueSize,
                        'oldest_job_age' => $oldestJob->age(),
                        'failed_count' => $failedCount
                    ]
                );
            }

            if ($queueSize > 1000 || $failedCount > 100) {
                return new HealthResult(
                    HealthStatus::Warning,
                    "Queue backlog detected",
                    [
                        'queue_size' => $queueSize,
                        'failed_count' => $failedCount
                    ]
                );
            }

            return new HealthResult(
                HealthStatus::Healthy,
                "Queue system operational",
                [
                    'queue_size' => $queueSize,
                    'failed_count' => $failedCount
                ]
            );
        } catch (\Throwable $e) {
            return new HealthResult(
                HealthStatus::Critical,
                "Queue check failed: {$e->getMessage()}"
            );
        }
    }
}

class StorageHealthCheck implements HealthCheckInterface
{
    private Storage $storage;
    private int $warningThreshold;
    private int $criticalThreshold;

    public function check(): HealthResult
    {
        try {
            $usage = $this->storage->getDiskUsage();
            $available = $this->storage->getAvailableSpace();
            $total = $this->storage->getTotalSpace();
            $percentage = ($usage / $total) * 100;

            if ($percentage >= $this->criticalThreshold) {
                return new HealthResult(
                    HealthStatus::Critical,
                    "Critical storage space",
                    [
                        'usage_percentage' => $percentage,
                        'available_space' => $available
                    ]
                );
            }

            if ($percentage >= $this->warningThreshold) {
                return new HealthResult(
                    HealthStatus::Warning,
                    "Low storage space",
                    [
                        'usage_percentage' => $percentage,
                        'available_space' => $available
                    ]
                );
            }

            return new HealthResult(
                HealthStatus::Healthy,
                "Storage system operational",
                [
                    'usage_percentage' => $percentage,
                    'available_space' => $available
                ]
            );
        } catch (\Throwable $e) {
            return new HealthResult(
                HealthStatus::Critical,
                "Storage check failed: {$e->getMessage()}"
            );
        }
    }
}

class MemoryHealthCheck implements HealthCheckInterface
{
    public function check(): HealthResult
    {
        $memoryLimit = ini_get('memory_limit');
        $memoryUsage = memory_get_usage(true);
        $peakUsage = memory_get_peak_usage(true);
        
        $usagePercentage = ($memoryUsage / $memoryLimit) * 100;
        $peakPercentage = ($peakUsage / $memoryLimit) * 100;

        if ($usagePercentage > 90 || $peakPercentage > 95) {
            return new HealthResult(
                HealthStatus::Critical,
                "Memory usage critical",
                [
                    'current_usage' => $usagePercentage,
                    'peak_usage' => $peakPercentage
                ]
            );
        }

        if ($usagePercentage > 75 || $peakPercentage > 80) {
            return new HealthResult(
                HealthStatus::Warning,
                "High memory usage",
                [
                    'current_usage' => $usagePercentage,
                    'peak_usage' => $peakPercentage
                ]
            );
        }

        return new HealthResult(
            HealthStatus::Healthy,
            "Memory usage normal",
            [
                'current_usage' => $usagePercentage,
                'peak_usage' => $peakPercentage
            ]
        );
    }
}
