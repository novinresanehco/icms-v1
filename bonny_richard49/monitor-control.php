<?php

namespace App\Core\Monitoring;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use App\Core\Security\SecurityManager;
use App\Core\Metrics\MetricsCollector;

class MonitoringSystem implements MonitorInterface
{
    private SecurityManager $security;
    private MetricsCollector $metrics;
    private array $config;
    private array $activeOperations = [];

    public function __construct(
        SecurityManager $security,
        MetricsCollector $metrics,
        array $config
    ) {
        $this->security = $security;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function startOperation(string $operationId, array $context = []): void
    {
        $this->validateOperation($operationId);

        $operation = [
            'id' => $operationId,
            'start_time' => microtime(true),
            'context' => $context,
            'metrics' => [
                'cpu_start' => $this->getCpuUsage(),
                'memory_start' => memory_get_usage(true),
                'queries_start' => $this->getQueryCount()
            ]
        ];

        $this->activeOperations[$operationId] = $operation;
        
        $this->metrics->increment("operations.started.{$this->getOperationType($operationId)}");
        
        if ($this->isHighRiskOperation($operationId)) {
            $this->security->notifyOperationStart($operationId, $context);
        }
    }

    public function endOperation(string $operationId, array $result = []): void
    {
        if (!isset($this->activeOperations[$operationId])) {
            throw new MonitoringException("Operation not found: {$operationId}");
        }

        $operation = $this->activeOperations[$operationId];
        $endTime = microtime(true);
        
        $metrics = [
            'duration' => $endTime - $operation['start_time'],
            'cpu_usage' => $this->getCpuUsage() - $operation['metrics']['cpu_start'],
            'memory_usage' => memory_get_usage(true) - $operation['metrics']['memory_start'],
            'queries_count' => $this->getQueryCount() - $operation['metrics']['queries_start']
        ];

        $this->validateMetrics($operationId, $metrics);
        
        $this->recordOperationMetrics($operationId, $metrics, $result);
        
        if ($this->hasPerformanceIssue($metrics)) {
            $this->handlePerformanceIssue($operationId, $metrics);
        }

        unset($this->activeOperations[$operationId]);
    }

    public function monitorResource(string $resource): array
    {
        return [
            'cpu' => $this->getCpuUsage(),
            'memory' => $this->getMemoryUsage(),
            'disk' => $this->getDiskUsage(),
            'load' => sys_getloadavg(),
            'connections' => $this->getConnectionCount(),
            'queue_size' => $this->getQueueSize()
        ];
    }

    public function checkHealth(): HealthStatus
    {
        $status = new HealthStatus();
        
        // Check critical systems
        $status->database = $this->checkDatabaseHealth();
        $status->cache = $this->checkCacheHealth();
        $status->queue = $this->checkQueueHealth();
        $status->storage = $this->checkStorageHealth();
        
        // Check resource usage
        $resources = $this->monitorResource('system');
        $status->resources = $this->validateResourceUsage($resources);
        
        // Check active operations
        $status->operations = $this->validateActiveOperations();
        
        return $status;
    }

    private function validateOperation(string $operationId): void
    {
        $this->security->validateOperation('monitoring.track', ['operation_id' => $operationId]);
        
        if (count($this->activeOperations) >= $this->config['max_concurrent_operations']) {
            throw new MonitoringException('Too many concurrent operations');
        }
    }

    private function validateMetrics(string $operationId, array $metrics): void
    {
        $limits = $this->config['operation_limits'][$this->getOperationType($operationId)] ?? $this->config['default_limits'];

        if ($metrics['duration'] > $limits['max_duration']) {
            $this->handlePerformanceViolation($operationId, 'duration', $metrics['duration'], $limits['max_duration']);
        }

        if ($metrics['memory_usage'] > $limits['max_memory']) {
            $this->handlePerformanceViolation($operationId, 'memory', $metrics['memory_usage'], $limits['max_memory']);
        }

        if ($metrics['queries_count'] > $limits['max_queries']) {
            $this->handlePerformanceViolation($operationId, 'queries', $metrics['queries_count'], $limits['max_queries']);
        }
    }

    private function handlePerformanceViolation(string $operationId, string $metric, $value, $limit): void
    {
        $context = [
            'operation_id' => $operationId,
            'metric' => $metric,
            'value' => $value,
            'limit' => $limit,
            'type' => $this->getOperationType($operationId)
        ];

        Log::warning('Performance limit exceeded', $context);
        $this->metrics->increment("performance.violations.{$metric}");
        
        if ($this->shouldTriggerAlert($metric, $value, $limit)) {
            $this->security->triggerAlert('performance_violation', $context);
        }
    }

    private function shouldTriggerAlert(string $metric, $value, $limit): bool
    {
        return ($value / $limit) > $this->config['alert_threshold'][$metric];
    }

    private function recordOperationMetrics(string $operationId, array $metrics, array $result): void
    {
        $type = $this->getOperationType($operationId);
        
        $this->metrics->record("operations.{$type}", [
            'duration' => $metrics['duration'],
            'cpu_usage' => $metrics['cpu_usage'],
            'memory_usage' => $metrics['memory_usage'],
            'queries_count' => $metrics['queries_count'],
            'success' => !empty($result),
            'timestamp' => time()
        ]);

        Redis::zadd(
            "operation_history:{$type}",
            time(),
            json_encode([
                'operation_id' => $operationId,
                'metrics' => $metrics,
                'result' => $result
            ])
        );

        Redis::zremrangebyrank(
            "operation_history:{$type}",
            0,
            -($this->config['history_size'] + 1)
        );
    }

    private function hasPerformanceIssue(array $metrics): bool
    {
        return $metrics['duration'] > $this->config['performance_threshold']['duration'] ||
            $metrics['memory_usage'] > $this->config['performance_threshold']['memory'] ||
            $metrics['cpu_usage'] > $this->config['performance_threshold']['cpu'];
    }

    private function handlePerformanceIssue(string $operationId, array $metrics): void
    {
        $context = [
            'operation_id' => $operationId,
            'metrics' => $metrics,
            'type' => $this->getOperationType($operationId)
        ];

        Log::error('Performance issue detected', $context);
        
        $this->security->triggerAlert('performance_issue', $context);
        
        if ($this->shouldInitiateEmergencyProcedures($metrics)) {
            $this->initiateEmergencyProcedures($operationId, $metrics);
        }
    }

    private function shouldInitiateEmergencyProcedures(array $metrics): bool
    {
        return $metrics['duration'] > $this->config['emergency_threshold']['duration'] ||
            $metrics['memory_usage'] > $this->config['emergency_threshold']['memory'] ||
            $metrics['cpu_usage'] > $this->config['emergency_threshold']['cpu'];
    }

    private function initiateEmergencyProcedures(string $operationId, array $metrics): void
    {
        $this->security->triggerEmergencyProcedures([
            'operation_id' => $operationId,
            'metrics' => $metrics,
            'timestamp' => time()
        ]);
    }

    private function getOperationType(string $operationId): string
    {
        return explode('.', $operationId)[0];
    }

    private function getCpuUsage(): float
    {
        return sys_getloadavg()[0];
    }

    private function getMemoryUsage(): array
    {
        return [
            'used' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true)
        ];
    }

    private function getDiskUsage(): array
    {
        $path = storage_path();
        return [
            'free' => disk_free_space($path),
            'total' => disk_total_space($path)
        ];
    }

    private function getConnectionCount(): int
    {
        return count(Redis::client()->client('list'));
    }

    private function getQueueSize(): int
    {
        return Redis::llen('queue:default');
    }

    private function getQueryCount(): int
    {
        return DB::getQueryLog() ? count(DB::getQueryLog()) : 0;
    }

    private function isHighRiskOperation(string $operationId): bool
    {
        return in_array(
            $this->getOperationType($operationId),
            $this->config['high_risk_operations']
        );
    }
}
