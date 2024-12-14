<?php

namespace App\Core\Monitoring;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use App\Core\Security\SecurityManagerInterface;
use App\Core\Events\SystemAlert;
use App\Core\Exceptions\MonitoringException;

class SystemMonitor implements MonitoringInterface
{
    protected SecurityManagerInterface $security;
    protected AlertManager $alerts;
    protected MetricsCollector $metrics;
    protected array $thresholds;
    protected array $activeOperations = [];

    public function __construct(
        SecurityManagerInterface $security,
        AlertManager $alerts,
        MetricsCollector $metrics,
        array $thresholds
    ) {
        $this->security = $security;
        $this->alerts = $alerts;
        $this->metrics = $metrics;
        $this->thresholds = $thresholds;
    }

    public function startOperation(string $operationId, array $context = []): void
    {
        $startTime = microtime(true);
        
        $this->activeOperations[$operationId] = [
            'start_time' => $startTime,
            'context' => $context,
            'metrics' => [
                'memory_start' => memory_get_usage(true),
                'cpu_start' => sys_getloadavg()[0]
            ]
        ];

        // Initialize monitoring
        $this->metrics->initializeOperation($operationId, $context);
        
        // Start real-time tracking
        $this->startRealTimeMonitoring($operationId);
    }

    public function recordMetrics(string $operationId, string $type, array $data): void
    {
        $timestamp = microtime(true);

        // Validate and sanitize metrics
        $metrics = $this->validateMetrics($data);
        
        // Store in time-series database
        $this->storeMetrics($operationId, $type, $metrics, $timestamp);
        
        // Check thresholds
        $this->checkThresholds($operationId, $type, $metrics);
        
        // Update real-time stats
        $this->updateRealTimeStats($operationId, $metrics);
    }

    public function stopOperation(string $operationId): void
    {
        if (!isset($this->activeOperations[$operationId])) {
            throw new MonitoringException("Unknown operation: $operationId");
        }

        $operation = $this->activeOperations[$operationId];
        $duration = microtime(true) - $operation['start_time'];

        // Record final metrics
        $finalMetrics = [
            'duration' => $duration,
            'memory_peak' => memory_get_peak_usage(true),
            'cpu_usage' => sys_getloadavg()[0] - $operation['metrics']['cpu_start']
        ];

        $this->recordMetrics($operationId, 'completion', $finalMetrics);
        
        // Stop real-time monitoring
        $this->stopRealTimeMonitoring($operationId);
        
        // Archive operation data
        $this->archiveOperation($operationId, $operation, $finalMetrics);
        
        unset($this->activeOperations[$operationId]);
    }

    public function recordFailure(string $operationId, string $type, \Throwable $e): void
    {
        $context = $this->activeOperations[$operationId]['context'] ?? [];
        
        // Log failure
        Log::error("Operation failure: $type", [
            'operation_id' => $operationId,
            'exception' => $e->getMessage(),
            'context' => $context
        ]);

        // Record failure metrics
        $this->metrics->recordFailure($operationId, $type, [
            'error_type' => get_class($e),
            'error_message' => $e->getMessage(),
            'stack_trace' => $e->getTraceAsString()
        ]);

        // Generate alert
        $this->alerts->criticalAlert(
            "Operation $type failed",
            $this->formatFailureAlert($operationId, $e)
        );

        // Execute failure protocols
        $this->executeFailureProtocols($operationId, $type, $e);
    }

    public function checkSystemHealth(): array
    {
        $health = [
            'cpu' => $this->checkCpuHealth(),
            'memory' => $this->checkMemoryHealth(),
            'storage' => $this->checkStorageHealth(),
            'services' => $this->checkServicesHealth()
        ];

        // Record health metrics
        $this->metrics->recordHealthCheck($health);

        // Check critical thresholds
        foreach ($health as $component => $status) {
            if ($status['status'] === 'critical') {
                $this->handleCriticalHealth($component, $status);
            }
        }

        return $health;
    }

    protected function startRealTimeMonitoring(string $operationId): void
    {
        Redis::zadd(
            'active_operations',
            microtime(true),
            $operationId
        );

        // Initialize real-time metrics
        Redis::hset("op:$operationId", [
            'start_time' => microtime(true),
            'status' => 'active',
            'metrics' => json_encode([])
        ]);
    }

    protected function updateRealTimeStats(string $operationId, array $metrics): void
    {
        Redis::hset(
            "op:$operationId",
            'metrics',
            json_encode($metrics)
        );

        // Update global stats
        foreach ($metrics as $key => $value) {
            Redis::zadd("stats:$key", microtime(true), $value);
        }
    }

    protected function stopRealTimeMonitoring(string $operationId): void
    {
        Redis::zrem('active_operations', $operationId);
        Redis::del("op:$operationId");
    }

    protected function checkThresholds(string $operationId, string $type, array $metrics): void
    {
        foreach ($metrics as $metric => $value) {
            if (isset($this->thresholds[$type][$metric])) {
                $threshold = $this->thresholds[$type][$metric];
                
                if ($value > $threshold['critical']) {
                    $this->handleCriticalThreshold($operationId, $metric, $value);
                } elseif ($value > $threshold['warning']) {
                    $this->handleWarningThreshold($operationId, $metric, $value);
                }
            }
        }
    }

    protected function handleCriticalThreshold(string $operationId, string $metric, $value): void
    {
        $this->alerts->criticalAlert(
            "Critical threshold exceeded",
            [
                'operation_id' => $operationId,
                'metric' => $metric,
                'value' => $value,
                'threshold' => $this->thresholds[$metric]['critical']
            ]
        );

        // Execute critical protocols
        $this->executeCriticalProtocols($operationId, $metric, $value);
    }

    protected function validateMetrics(array $metrics): array
    {
        $validated = [];
        
        foreach ($metrics as $key => $value) {
            if (!is_numeric($value)) {
                throw new MonitoringException("Invalid metric value for $key");
            }
            $validated[$key] = (float) $value;
        }
        
        return $validated;
    }

    protected function storeMetrics(
        string $operationId,
        string $type,
        array $metrics,
        float $timestamp
    ): void {
        foreach ($metrics as $metric => $value) {
            $key = "metrics:$type:$metric";
            Redis::zadd($key, $timestamp, "$operationId:$value");
        }
    }

    protected function archiveOperation(
        string $operationId,
        array $operation,
        array $finalMetrics
    ): void {
        $archiveData = [
            'operation_id' => $operationId,
            'start_time' => $operation['start_time'],
            'end_time' => microtime(true),
            'context' => $operation['context'],
            'initial_metrics' => $operation['metrics'],
            'final_metrics' => $finalMetrics
        ];

        // Store in time-series database
        $this->metrics->archiveOperation($archiveData);
    }

    protected function checkCpuHealth(): array
    {
        $load = sys_getloadavg();
        
        return [
            'load_1m' => $load[0],
            'load_5m' => $load[1],
            'load_15m' => $load[2],
            'status' => $this->getCpuStatus($load[0])
        ];
    }

    protected function checkMemoryHealth(): array
    {
        $memInfo = $this->getMemoryInfo();
        $usagePercent = ($memInfo['used'] / $memInfo['total']) * 100;
        
        return [
            'total' => $memInfo['total'],
            'used' => $memInfo['used'],
            'free' => $memInfo['free'],
            'status' => $this->getMemoryStatus($usagePercent)
        ];
    }

    protected function handleCriticalHealth(string $component, array $status): void
    {
        $this->alerts->criticalAlert(
            "Critical system health: $component",
            [
                'component' => $component,
                'status' => $status,
                'timestamp' => microtime(true)
            ]
        );

        // Execute emergency protocols
        $this->executeEmergencyProtocols($component, $status);
    }
}
