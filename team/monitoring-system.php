<?php

namespace App\Core\Monitoring;

use Illuminate\Support\Facades\{Redis, Log};
use App\Core\Security\SecurityManagerInterface;

class MonitoringService implements MonitoringInterface
{
    private SecurityManagerInterface $security;
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private array $config;

    private const CRITICAL_THRESHOLD = 90;
    private const WARNING_THRESHOLD = 75;

    public function __construct(
        SecurityManagerInterface $security,
        MetricsCollector $metrics,
        AlertManager $alerts,
        array $config
    ) {
        $this->security = $security;
        $this->metrics = $metrics;
        $this->alerts = $alerts;
        $this->config = $config;
    }

    public function startOperation(string $type): string
    {
        $operationId = $this->generateOperationId();
        
        $this->metrics->record("operation.start", [
            'id' => $operationId,
            'type' => $type,
            'timestamp' => microtime(true),
            'memory' => memory_get_usage(true),
            'cpu' => sys_getloadavg()[0]
        ]);

        return $operationId;
    }

    public function track(string $operationId, callable $operation): mixed
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        try {
            $result = $operation();
            
            $this->recordSuccess($operationId, $startTime, $startMemory);
            
            return $result;

        } catch (\Throwable $e) {
            $this->recordFailure($operationId, $e, $startTime, $startMemory);
            throw $e;
        }
    }

    public function stopOperation(string $operationId): void
    {
        $this->metrics->record("operation.end", [
            'id' => $operationId,
            'timestamp' => microtime(true),
            'memory' => memory_get_usage(true),
            'cpu' => sys_getloadavg()[0]
        ]);

        $this->checkThresholds($operationId);
    }

    public function monitorPerformance(callable $operation, array $context = []): mixed
    {
        $span = $this->startSpan('performance');

        try {
            $result = $operation();
            
            $this->recordPerformanceMetrics($span, $context);
            
            return $result;

        } finally {
            $this->endSpan($span);
        }
    }

    public function recordMetric(string $name, float $value, array $tags = []): void
    {
        $this->security->executeCriticalOperation(
            fn() => $this->metrics->push($name, $value, array_merge(
                $tags,
                ['timestamp' => microtime(true)]
            )),
            new SecurityContext('metrics.record')
        );

        $this->checkMetricThresholds($name, $value, $tags);
    }

    public function startSpan(string $name): string
    {
        $spanId = $this->generateSpanId();
        
        Redis::hset("span:$spanId", [
            'name' => $name,
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(true)
        ]);

        return $spanId;
    }

    public function endSpan(string $spanId): void
    {
        $span = Redis::hgetall("span:$spanId");
        
        if (!$span) {
            return;
        }

        $duration = microtime(true) - (float)$span['start_time'];
        $memoryUsage = memory_get_usage(true) - (int)$span['start_memory'];

        $this->metrics->record("span.complete", [
            'id' => $spanId,
            'name' => $span['name'],
            'duration' => $duration,
            'memory_usage' => $memoryUsage
        ]);

        Redis::del("span:$spanId");
    }

    private function recordSuccess(string $operationId, float $startTime, int $startMemory): void
    {
        $duration = microtime(true) - $startTime;
        $memoryUsage = memory_get_usage(true) - $startMemory;

        $this->metrics->record("operation.success", [
            'id' => $operationId,
            'duration' => $duration,
            'memory_usage' => $memoryUsage,
            'cpu_usage' => sys_getloadavg()[0]
        ]);

        if ($duration > $this->config['slow_threshold'] ?? 1.0) {
            $this->alerts->warning('Slow operation detected', [
                'operation_id' => $operationId,
                'duration' => $duration
            ]);
        }
    }

    private function recordFailure(string $operationId, \Throwable $e, float $startTime, int $startMemory): void
    {
        $duration = microtime(true) - $startTime;
        $memoryUsage = memory_get_usage(true) - $startMemory;

        $this->metrics->record("operation.failure", [
            'id' => $operationId,
            'error' => $e->getMessage(),
            'duration' => $duration,
            'memory_usage' => $memoryUsage,
            'cpu_usage' => sys_getloadavg()[0]
        ]);

        $this->alerts->error('Operation failed', [
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function checkThresholds(string $operationId): void
    {
        $metrics = $this->metrics->getOperationMetrics($operationId);
        
        if ($metrics['cpu_usage'] > self::CRITICAL_THRESHOLD) {
            $this->alerts->critical('CPU usage critical', [
                'operation_id' => $operationId,
                'cpu_usage' => $metrics['cpu_usage']
            ]);
        }

        if ($metrics['memory_usage'] > self::CRITICAL_THRESHOLD) {
            $this->alerts->critical('Memory usage critical', [
                'operation_id' => $operationId,
                'memory_usage' => $metrics['memory_usage']
            ]);
        }
    }

    private function generateOperationId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function generateSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
