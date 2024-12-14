<?php

namespace App\Core\Monitoring;

use Illuminate\Support\Facades\Log;
use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManagerInterface;
use App\Exceptions\MonitoringException;

class MonitoringService implements MonitoringServiceInterface
{
    private SecurityManagerInterface $security;
    private CacheManagerInterface $cache;
    private array $activeOperations = [];
    private array $thresholds;

    public function __construct(
        SecurityManagerInterface $security,
        CacheManagerInterface $cache,
        array $config
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->thresholds = $config['thresholds'];
    }

    /**
     * Start monitoring critical operation
     */
    public function startOperation(string $operationType): string
    {
        $operationId = $this->security->generateSecureId();
        
        $this->activeOperations[$operationId] = [
            'type' => $operationType,
            'start_time' => microtime(true),
            'memory_start' => memory_get_usage(true),
            'metrics' => [],
            'alerts' => []
        ];

        // Log operation start with secure context
        $this->logOperationEvent($operationId, 'start', [
            'type' => $operationType,
            'timestamp' => now()
        ]);

        return $operationId;
    }

    /**
     * Stop monitoring operation and validate metrics
     */
    public function stopOperation(string $operationId): void
    {
        if (!isset($this->activeOperations[$operationId])) {
            throw new MonitoringException('Invalid operation ID');
        }

        $operation = $this->activeOperations[$operationId];
        $duration = microtime(true) - $operation['start_time'];
        $memoryUsed = memory_get_usage(true) - $operation['memory_start'];

        // Validate against thresholds
        $this->validateOperationMetrics($operationId, $duration, $memoryUsed);

        // Log operation completion
        $this->logOperationEvent($operationId, 'complete', [
            'duration' => $duration,
            'memory_used' => $memoryUsed,
            'metrics' => $operation['metrics']
        ]);

        // Store metrics for analysis
        $this->storeOperationMetrics($operationId, $operation, $duration, $memoryUsed);

        unset($this->activeOperations[$operationId]);
    }

    /**
     * Record metric with real-time validation
     */
    public function recordMetric(string $operationId, string $metric, $value): void
    {
        if (!isset($this->activeOperations[$operationId])) {
            throw new MonitoringException('Invalid operation ID for metric recording');
        }

        $this->activeOperations[$operationId]['metrics'][$metric] = $value;

        // Validate metric against thresholds
        if (isset($this->thresholds[$metric]) && $value > $this->thresholds[$metric]) {
            $this->handleThresholdViolation($operationId, $metric, $value);
        }

        // Cache metric for real-time monitoring
        $this->cacheMetric($operationId, $metric, $value);
    }

    /**
     * Trigger system alert with immediate logging
     */
    public function triggerAlert(string $operationId, string $type, array $context = []): void
    {
        $alert = [
            'type' => $type,
            'timestamp' => now(),
            'context' => $context,
            'operation_id' => $operationId
        ];

        // Log alert with secure context
        $this->logSecureAlert($alert);

        // Store in active operations
        if (isset($this->activeOperations[$operationId])) {
            $this->activeOperations[$operationId]['alerts'][] = $alert;
        }

        // Cache alert for immediate access
        $this->cacheAlert($operationId, $alert);

        // Trigger immediate notification if critical
        if ($this->isCriticalAlert($type)) {
            $this->notifyCriticalAlert($alert);
        }
    }

    /**
     * Get current system state with metrics
     */
    public function getSystemState(): array
    {
        return [
            'active_operations' => count($this->activeOperations),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'cpu_usage' => sys_getloadavg()[0],
            'metrics' => $this->getCurrentMetrics()
        ];
    }

    private function validateOperationMetrics(string $operationId, float $duration, int $memoryUsed): void
    {
        $operation = $this->activeOperations[$operationId];
        
        if ($duration > $this->thresholds['operation_duration']) {
            $this->handleSlowOperation($operationId, $duration);
        }

        if ($memoryUsed > $this->thresholds['operation_memory']) {
            $this->handleExcessiveMemory($operationId, $memoryUsed);
        }
    }

    private function handleThresholdViolation(string $operationId, string $metric, $value): void
    {
        $this->triggerAlert($operationId, 'threshold_violation', [
            'metric' => $metric,
            'value' => $value,
            'threshold' => $this->thresholds[$metric]
        ]);
    }

    private function handleSlowOperation(string $operationId, float $duration): void
    {
        $this->triggerAlert($operationId, 'slow_operation', [
            'duration' => $duration,
            'threshold' => $this->thresholds['operation_duration']
        ]);
    }

    private function handleExcessiveMemory(string $operationId, int $memoryUsed): void
    {
        $this->triggerAlert($operationId, 'excessive_memory', [
            'memory_used' => $memoryUsed,
            'threshold' => $this->thresholds['operation_memory']
        ]);
    }

    private function logSecureAlert(array $alert): void
    {
        Log::error('System Alert', [
            'alert' => $alert,
            'system_state' => $this->getSystemState()
        ]);
    }

    private function logOperationEvent(string $operationId, string $event, array $context): void
    {
        Log::info("Operation $event", array_merge($context, [
            'operation_id' => $operationId,
            'system_state' => $this->getSystemState()
        ]));
    }

    private function storeOperationMetrics(string $operationId, array $operation, float $duration, int $memoryUsed): void
    {
        $metrics = [
            'type' => $operation['type'],
            'duration' => $duration,
            'memory_used' => $memoryUsed,
            'metrics' => $operation['metrics'],
            'alerts' => $operation['alerts']
        ];

        $this->cache->set("operation_metrics:$operationId", $metrics, 3600);
    }

    private function getCurrentMetrics(): array
    {
        $metrics = [];
        foreach ($this->activeOperations as $id => $operation) {
            $metrics[$id] = $operation['metrics'];
        }
        return $metrics;
    }
}
