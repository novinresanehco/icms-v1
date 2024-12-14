<?php

namespace App\Core\Monitoring;

use Illuminate\Support\Facades\{Cache, Log, DB};

class MonitoringService implements MonitoringInterface
{
    private MetricsCollector $metrics;
    private PerformanceAnalyzer $analyzer;
    private AlertManager $alerts;
    private array $thresholds;

    public function __construct(
        MetricsCollector $metrics,
        PerformanceAnalyzer $analyzer,
        AlertManager $alerts
    ) {
        $this->metrics = $metrics;
        $this->analyzer = $analyzer;
        $this->alerts = $alerts;
        $this->thresholds = config('monitoring.thresholds');
    }

    public function track(string $operation, callable $callback): mixed
    {
        $context = $this->initializeContext($operation);
        
        try {
            $result = $this->executeWithMonitoring($callback, $context);
            $this->finalizeSuccess($context);
            return $result;
            
        } catch (\Throwable $e) {
            $this->handleFailure($e, $context);
            throw $e;
        }
    }

    public function monitorPerformance(string $identifier): PerformanceResult
    {
        $metrics = $this->metrics->collect([
            'cpu_usage' => $this->getCpuUsage(),
            'memory_usage' => $this->getMemoryUsage(),
            'db_connections' => $this->getDbConnections(),
            'cache_hits' => $this->getCacheHits(),
            'response_times' => $this->getResponseTimes()
        ]);

        $analysis = $this->analyzer->analyze($metrics);
        
        if ($analysis->hasThresholdViolations()) {
            $this->handleThresholdViolations($analysis);
        }

        return new PerformanceResult($metrics, $analysis);
    }

    public function watchSystem(): void
    {
        DB::beforeExecuting(function($query) {
            $this->trackQuery($query);
        });

        Cache::macro('remember', function($key, $ttl, $callback) {
            $this->trackCacheOperation($key);
            return Cache::remember($key, $ttl, $callback);
        });
    }

    private function initializeContext(string $operation): MonitoringContext
    {
        return new MonitoringContext([
            'operation' => $operation,
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(true)
        ]);
    }

    private function executeWithMonitoring(callable $callback, MonitoringContext $context): mixed
    {
        $this->beginMonitoring($context);
        $result = $callback();
        $this->checkOperationMetrics($context);
        return $result;
    }

    private function beginMonitoring(MonitoringContext $context): void
    {
        $this->metrics->begin($context->operation, [
            'timestamp' => microtime(true),
            'memory_start' => memory_get_usage(true),
            'cpu_start' => $this->getCpuUsage()
        ]);
    }

    private function checkOperationMetrics(MonitoringContext $context): void
    {
        $currentMetrics = [
            'duration' => microtime(true) - $context->start_time,
            'memory_peak' => memory_get_peak_usage(true),
            'cpu_usage' => $this->getCpuUsage()
        ];

        foreach ($currentMetrics as $metric => $value) {
            if ($this->isThresholdViolated($metric, $value)) {
                $this->alerts->trigger(
                    "threshold_violation_{$metric}",
                    $context->operation,
                    compact('value')
                );
            }
        }
    }

    private function finalizeSuccess(MonitoringContext $context): void
    {
        $this->metrics->complete($context->operation, [
            'duration' => microtime(true) - $context->start_time,
            'memory_used' => memory_get_usage(true) - $context->start_memory,
            'status' => 'success'
        ]);
    }

    private function handleFailure(\Throwable $e, MonitoringContext $context): void
    {
        $this->metrics->complete($context->operation, [
            'duration' => microtime(true) - $context->start_time,
            'memory_used' => memory_get_usage(true) - $context->start_memory,
            'status' => 'failure',
            'error' => $e->getMessage()
        ]);

        $this->alerts->triggerError(
            $context->operation,
            $e,
            $this->collectFailureContext($context)
        );
    }

    private function handleThresholdViolations(PerformanceAnalysis $analysis): void
    {
        foreach ($analysis->getViolations() as $violation) {
            $this->alerts->triggerThresholdViolation(
                $violation->getMetric(),
                $violation->getCurrentValue(),
                $violation->getThreshold()
            );
        }
    }

    private function collectFailureContext(MonitoringContext $context): array
    {
        return [
            'system_load' => sys_getloadavg(),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'db_stats' => $this->getDbStats(),
            'cache_stats' => $this->getCacheStats()
        ];
    }

    private function isThresholdViolated(string $metric, $value): bool
    {
        return isset($this->thresholds[$metric]) &&
               $value > $this->thresholds[$metric];
    }

    private function getCpuUsage(): float
    {
        return sys_getloadavg()[0];
    }

    private function getMemoryUsage(): int
    {
        return memory_get_usage(true);
    }

    private function getDbConnections(): int
    {
        return DB::connection()->select('SHOW STATUS LIKE "Threads_connected"')[0]->Value;
    }

    private function getCacheHits(): array
    {
        return Cache::getStore()->get('stats:cache_hits') ?? [];
    }

    private function getResponseTimes(): array
    {
        return $this->metrics->getAverageResponseTimes();
    }

    private function getDbStats(): array
    {
        return [
            'connections' => $this->getDbConnections(),
            'slow_queries' => DB::select('SHOW GLOBAL STATUS LIKE "Slow_queries"')[0]->Value
        ];
    }

    private function getCacheStats(): array
    {
        return [
            'hits' => $this->getCacheHits(),
            'memory_usage' => Cache::getStore()->getMemoryStats()
        ];
    }
}
