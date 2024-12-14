<?php

namespace App\Core\Monitoring;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Core\Security\SecurityContext;
use App\Core\Interfaces\MonitoringInterface;

class PerformanceMonitor implements MonitoringInterface
{
    private SecurityContext $security;
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private array $thresholds;
    
    private const CRITICAL_METRICS = [
        'response_time',
        'memory_usage',
        'cpu_load',
        'error_rate',
        'query_time'
    ];

    public function __construct(
        SecurityContext $security,
        MetricsCollector $metrics,
        AlertManager $alerts,
        array $thresholds
    ) {
        $this->security = $security;
        $this->metrics = $metrics;
        $this->alerts = $alerts;
        $this->thresholds = $thresholds;
    }

    public function startOperation(string $operation): string
    {
        $operationId = $this->generateOperationId();
        
        $this->metrics->record($operationId, [
            'operation' => $operation,
            'start_time' => microtime(true),
            'memory_start' => memory_get_usage(),
            'user_id' => $this->security->getCurrentUserId(),
            'request_id' => request()->id()
        ]);

        return $operationId;
    }

    public function endOperation(string $operationId): array
    {
        $startMetrics = $this->metrics->get($operationId);
        if (!$startMetrics) {
            throw new \RuntimeException('Operation not found');
        }

        $endMetrics = [
            'end_time' => microtime(true),
            'memory_end' => memory_get_usage(),
            'duration' => microtime(true) - $startMetrics['start_time'],
            'memory_peak' => memory_get_peak_usage(),
            'cpu_usage' => sys_getloadavg()[0]
        ];

        $this->metrics->update($operationId, $endMetrics);
        $this->checkThresholds($startMetrics['operation'], $endMetrics);

        return array_merge($startMetrics, $endMetrics);
    }

    public function recordMetric(string $metric, $value, array $context = []): void
    {
        $this->validateMetric($metric);
        
        $metricData = [
            'value' => $value,
            'timestamp' => microtime(true),
            'context' => $context
        ];

        $this->metrics->recordMetric($metric, $metricData);
        
        if ($this->isThresholdExceeded($metric, $value)) {
            $this->handleThresholdViolation($metric, $value, $context);
        }
    }

    public function trackResource(string $resource, callable $operation)
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        try {
            $result = $operation();
            
            $this->recordMetric("resource.$resource", [
                'duration' => microtime(true) - $startTime,
                'memory' => memory_get_usage() - $startMemory,
                'success' => true
            ]);

            return $result;

        } catch (\Throwable $e) {
            $this->recordMetric("resource.$resource", [
                'duration' => microtime(true) - $startTime,
                'memory' => memory_get_usage() - $startMemory,
                'success' => false,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    public function monitorQuery(string $query, callable $execution)
    {
        $startTime = microtime(true);
        
        try {
            $result = $execution();
            $duration = microtime(true) - $startTime;
            
            $this->recordMetric('query_time', $duration, [
                'query' => $this->sanitizeQuery($query),
                'success' => true
            ]);

            return $result;

        } catch (\Throwable $e) {
            $duration = microtime(true) - $startTime;
            
            $this->recordMetric('query_time', $duration, [
                'query' => $this->sanitizeQuery($query),
                'success' => false,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    public function getMetrics(string $metric = null): array
    {
        if ($metric) {
            return $this->metrics->getMetric($metric);
        }

        return $this->metrics->getAllMetrics();
    }

    public function getPerformanceReport(): array
    {
        return [
            'response_times' => $this->calculateResponseTimeStats(),
            'memory_usage' => $this->calculateMemoryStats(),
            'cpu_load' => $this->calculateCpuStats(),
            'error_rates' => $this->calculateErrorRates(),
            'query_performance' => $this->calculateQueryStats()
        ];
    }

    protected function checkThresholds(string $operation, array $metrics): void
    {
        foreach (self::CRITICAL_METRICS as $metric) {
            if (isset($metrics[$metric]) && 
                $this->isThresholdExceeded($metric, $metrics[$metric])) {
                $this->handleThresholdViolation($metric, $metrics[$metric], [
                    'operation' => $operation
                ]);
            }
        }
    }

    protected function isThresholdExceeded(string $metric, $value): bool
    {
        return isset($this->thresholds[$metric]) && 
               $value > $this->thresholds[$metric];
    }

    protected function handleThresholdViolation(
        string $metric,
        $value,
        array $context = []
    ): void {
        $this->alerts->trigger("threshold_exceeded.$metric", [
            'metric' => $metric,
            'value' => $value,
            'threshold' => $this->thresholds[$metric],
            'context' => $context
        ]);

        Log::warning("Performance threshold exceeded", [
            'metric' => $metric,
            'value' => $value,
            'threshold' => $this->thresholds[$metric],
            'context' => $context
        ]);
    }

    protected function generateOperationId(): string
    {
        return md5(uniqid('op_', true));
    }

    protected function validateMetric(string $metric): void
    {
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $metric)) {
            throw new \InvalidArgumentException('Invalid metric name');
        }
    }

    protected function sanitizeQuery(string $query): string
    {
        return preg_replace('/\s+/', ' ', trim($query));
    }

    protected function calculateResponseTimeStats(): array
    {
        return $this->metrics->calculateStats('response_time');
    }

    protected function calculateMemoryStats(): array
    {
        return $this->metrics->calculateStats('memory_usage');
    }

    protected function calculateCpuStats(): array
    {
        return $this->metrics->calculateStats('cpu_load');
    }

    protected function calculateErrorRates(): array
    {
        return $this->metrics->calculateStats('error_rate');
    }

    protected function calculateQueryStats(): array
    {
        return $this->metrics->calculateStats('query_time');
    }
}
