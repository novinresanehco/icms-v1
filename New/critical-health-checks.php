<?php

namespace App\Core\Monitoring\Jobs;

class SystemHealthCheck implements ShouldQueue
{
    use Queueable, SerializesModels;

    private array $check;
    private MetricsCollector $metrics;
    private SecurityManager $security;
    private AlertManager $alerts;

    public function handle(): void
    {
        $monitorId = $this->metrics->startOperation('health.check');
        
        try {
            // Execute health check
            $result = $this->executeCheck();
            
            // Process result
            $this->processResult($result);
            
            // Record metrics
            $this->metrics->recordSuccess($monitorId);
            
        } catch (\Exception $e) {
            $this->metrics->recordFailure($monitorId, $e);
            $this->handleCheckFailure($e);
        }
    }

    private function executeCheck(): HealthCheckResult
    {
        return match($this->check['type']) {
            'system' => $this->checkSystem(),
            'security' => $this->checkSecurity(),
            'performance' => $this->checkPerformance(),
            'resource' => $this->checkResources(),
            'service' => $this->checkService(),
            default => throw new \InvalidArgumentException('Invalid check type')
        };
    }

    private function checkSystem(): HealthCheckResult
    {
        return new HealthCheckResult([
            'cpu' => $this->checkCPU(),
            'memory' => $this->checkMemory(),
            'disk' => $this->checkDisk(),
            'load' => $this->checkLoad()
        ]);
    }

    private function checkSecurity(): HealthCheckResult
    {
        return new HealthCheckResult([
            'auth' => $this->checkAuth(),
            'encryption' => $this->checkEncryption(),
            'integrity' => $this->checkIntegrity(),
            'audit' => $this->checkAudit()
        ]);
    }

    private function checkPerformance(): HealthCheckResult
    {
        return new HealthCheckResult([
            'response' => $this->checkResponseTime(),
            'throughput' => $this->checkThroughput(),
            'latency' => $this->checkLatency(),
            'errors' => $this->checkErrors()
        ]);
    }

    private function checkResources(): HealthCheckResult
    {
        return new HealthCheckResult([
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'storage' => $this->checkStorage(),
            'queue' => $this->checkQueue()
        ]);
    }

    private function processResult(HealthCheckResult $result): void
    {
        // Store result
        $this->storeResult($result);
        
        // Check thresholds
        $this->checkThresholds($result);
        
        // Update status
        $this->updateSystemStatus($result);
        
        // Trigger alerts if needed
        if (!$result->isHealthy()) {
            $this->triggerAlerts($result);
        }
    }

    private function storeResult(HealthCheckResult $result): void
    {
        HealthCheckResult::create([
            'type' => $this->check['type'],
            'status' => $result->status,
            'metrics' => $result->metrics,
            'timestamp' => now()
        ]);
    }

    private function checkThresholds(HealthCheckResult $result): void
    {
        foreach ($result->metrics as $metric => $value) {
            if ($threshold = $this->getThreshold($metric)) {
                $this->checkMetricThreshold($metric, $value, $threshold);
            }
        }
    }

    private function checkMetricThreshold(string $metric, $value, array $threshold): void
    {
        if ($this->isThresholdExceeded($value, $threshold)) {
            $this->handleThresholdExceeded($metric, $value, $threshold);
        }
    }

    private function isThresholdExceeded($value, array $threshold): bool
    {
        return match($threshold['operator']) {
            '>' => $value > $threshold['value'],
            '<' => $value < $threshold['value'],
            '>=' => $value >= $threshold['value'],
            '<=' => $value <= $threshold['value'],
            '=' => $value === $threshold['value'],
            default => false
        };
    }

    private function handleThresholdExceeded(string $metric, $value, array $threshold): void
    {
        // Create alert
        $alert = new ThresholdAlert($metric, $value, $threshold);
        
        // Trigger alert
        $this->alerts->trigger($alert);
        
        // Execute threshold protocols
        if ($threshold['critical']) {
            $this->executeThresholdProtocols($metric, $value, $threshold);
        }
    }

    private function executeThresholdProtocols(string $metric, $value, array $threshold): void
    {
        match($threshold['action']) {
            'shutdown' => $this->executeShutdown($metric),
            'restart' => $this->executeRestart($metric),
            'scale' => $this->executeScaling($metric),
            'notify' => $this->executeNotification($metric),
            default => null
        };
    }

    private function handleCheckFailure(\Exception $e): void
    {
        // Log failure
        Log::error('Health check failed', [
            'check' => $this->check,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        // Create incident
        $incident = new MonitoringIncident(
            $this->check['type'],
            $e->getMessage()
        );

        // Handle incident
        $this->security->handleMonitoringIncident($incident);

        // Notify team
        $this->alerts->trigger(
            new MonitoringFailureAlert($this->check, $e)
        );
    }
}
