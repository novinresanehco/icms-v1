<?php

namespace App\Core\Audit;

class AuditPerformanceMonitor
{
    private MetricsCollector $metrics;
    private PerformanceAnalyzer $analyzer;
    private AlertManager $alertManager;
    private CacheManager $cache;
    private array $thresholds;

    public function __construct(
        MetricsCollector $metrics,
        PerformanceAnalyzer $analyzer,
        AlertManager $alertManager,
        CacheManager $cache,
        array $thresholds = []
    ) {
        $this->metrics = $metrics;
        $this->analyzer = $analyzer;
        $this->alertManager = $alertManager;
        $this->cache = $cache;
        $this->thresholds = $thresholds;
    }

    public function monitorOperation(string $operation, callable $callback): mixed
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        try {
            // Execute operation
            $result = $callback();

            // Record success metrics
            $this->recordOperationMetrics(
                $operation,
                microtime(true) - $startTime,
                memory_get_usage(true) - $startMemory,
                true
            );

            return $result;

        } catch (\Exception $e) {
            // Record failure metrics
            $this->recordOperationMetrics(
                $operation,
                microtime(true) - $startTime,
                memory_get_usage(true) - $startMemory,
                false
            );

            throw $e;
        }
    }

    public function analyzePerformance(): PerformanceReport
    {
        try {
            // Get cached report if available
            $cacheKey = $this->generateReportCacheKey();
            if ($cached = $this->cache->get($cacheKey)) {
                return $cached;
            }

            // Collect metrics
            $metrics = $this->collectPerformanceMetrics();

            // Analyze metrics
            $analysis = $this->analyzer->analyze($metrics);

            // Generate report
            $report = $this->generatePerformanceReport($analysis);

            // Cache report
            $this->cache->put($cacheKey, $report, $this->getCacheDuration());

            return $report;

        } catch (\Exception $e) {
            $this->handleAnalysisError($e);
            throw $e;
        }
    }

    public function checkThresholds(array $metrics): array
    {
        $violations = [];

        foreach ($this->thresholds as $metric => $threshold) {
            if (isset($metrics[$metric]) && $this->isThresholdViolated($metrics[$metric], $threshold)) {
                $violations[] = new ThresholdViolation($metric, $metrics[$metric], $threshold);
            }
        }

        if (!empty($violations)) {
            $this->handleThresholdViolations($violations);
        }

        return $violations;
    }

    protected function recordOperationMetrics(
        string $operation,
        float $duration,
        int $memoryUsage,
        bool $success
    ): void {
        $this->metrics->record([
            'operation_duration' => [
                'value' => $duration,
                'tags' => ['operation' => $operation]
            ],
            'operation_memory' => [
                'value' => $memoryUsage,
                'tags' => ['operation' => $operation]
            ],
            'operation_success' => [
                'value' => (int)$success,
                'tags' => ['operation' => $operation]
            ]
        ]);

        // Check thresholds
        $this->checkOperationThresholds($operation, $duration, $memoryUsage);
    }

    protected function collectPerformanceMetrics(): array
    {
        return [
            'operations' => $this->metrics->getOperationMetrics(),
            'resources' => $this->collectResourceMetrics(),
            'database' => $this->collectDatabaseMetrics(),
            'cache' => $this->collectCacheMetrics()
        ];
    }

    protected function collectResourceMetrics(): array
    {
        return [
            'cpu_usage' => $this->getCpuUsage(),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'disk_usage' => $this->getDiskUsage()
        ];
    }

    protected function collectDatabaseMetrics(): array
    {
        return [
            'query_count' => DB::getQueryLog()->count(),
            'slow_queries' => $this->getSlowQueryCount(),
            'average_query_time' => $this->calculateAverageQueryTime(),
            'connection_count' => DB::getConnections()->count()
        ];
    }

    protected function collectCacheMetrics(): array
    {
        return [
            'hit_rate' => $this->cache->getHitRate(),
            'miss_rate' => $this->cache->getMissRate(),
            'memory_usage' => $this->cache->getMemoryUsage(),
            'key_count' => $this->cache->getKeyCount()
        ];
    }

    protected function generatePerformanceReport(array $analysis): PerformanceReport
    {
        return new PerformanceReport([
            'metrics' => $analysis['metrics'],
            'bottlenecks' => $analysis['bottlenecks'],
            'recommendations' => $this->generateRecommendations($analysis),
            'trends' => $this->analyzeTrends($analysis),
            'alerts' => $this->generateAlerts($analysis),
            'timestamp' => now()
        ]);
    }

    protected function isThresholdViolated($value, $threshold): bool
    {
        if (is_array($threshold)) {
            return $value < $threshold['min'] || $value > $threshold['max'];
        }

        return $value > $threshold;
    }

    protected function handleThresholdViolations(array $violations): void
    {
        foreach ($violations as $violation) {
            $this->alertManager->sendAlert(
                new ThresholdAlert($violation)
            );
        }
    }

    protected function generateReportCacheKey(): string
    {
        return 'audit:performance:report:' . date('Y-m-d-H');
    }

    protected function getCacheDuration(): int
    {
        return config('audit.performance.cache_duration', 3600);
    }
}
