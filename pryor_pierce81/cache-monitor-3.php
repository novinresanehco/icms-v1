<?php

namespace App\Core\Monitoring\Cache;

class CacheMonitor {
    private CacheMetricsCollector $metricsCollector;
    private CachePerformanceAnalyzer $performanceAnalyzer;
    private HitRateOptimizer $hitRateOptimizer;
    private MemoryAnalyzer $memoryAnalyzer;
    private AlertDispatcher $alertDispatcher;

    public function __construct(
        CacheMetricsCollector $metricsCollector,
        CachePerformanceAnalyzer $performanceAnalyzer,
        HitRateOptimizer $hitRateOptimizer,
        MemoryAnalyzer $memoryAnalyzer,
        AlertDispatcher $alertDispatcher
    ) {
        $this->metricsCollector = $metricsCollector;
        $this->performanceAnalyzer = $performanceAnalyzer;
        $this->hitRateOptimizer = $hitRateOptimizer;
        $this->memoryAnalyzer = $memoryAnalyzer;
        $this->alertDispatcher = $alertDispatcher;
    }

    public function monitor(): CacheReport 
    {
        $metrics = $this->metricsCollector->collect();
        $performance = $this->performanceAnalyzer->analyze($metrics);
        $hitRateAnalysis = $this->hitRateOptimizer->analyze($metrics);
        $memoryAnalysis = $this->memoryAnalyzer->analyze($metrics);

        if ($this->hasIssues($performance, $hitRateAnalysis, $memoryAnalysis)) {
            $this->alertDispatcher->dispatch(
                new CacheAlert($performance, $hitRateAnalysis, $memoryAnalysis)
            );
        }

        return new CacheReport($metrics, $performance, $hitRateAnalysis, $memoryAnalysis);
    }

    private function hasIssues(
        PerformanceAnalysis $performance,
        HitRateAnalysis $hitRate,
        MemoryAnalysis $memory
    ): bool {
        return $performance->hasIssues() || 
               $hitRate->isBelow($this->hitRateOptimizer->getMinimumRate()) ||
               $memory->isNearCapacity();
    }
}

class CacheMetricsCollector {
    private CacheInterface $cache;
    private array $metrics = [];

    public function collect(): CacheMetrics 
    {
        $this->collectHitRateMetrics();
        $this->collectMemoryMetrics();
        $this->collectPerformanceMetrics();
        $this->collectKeyMetrics();

        return new CacheMetrics($this->metrics);
    }

    private function collectHitRateMetrics(): void 
    {
        $stats = $this->cache->getStats();
        $this->metrics['hit_rate'] = [
            'hits' => $stats['hits'],
            'misses' => $stats['misses'],
            'rate' => $this->calculateHitRate($stats['hits'], $stats['misses'])
        ];
    }

    private function collectMemoryMetrics(): void 
    {
        $this->metrics['memory'] = [
            'used' => $this->cache->getMemoryUsage(),
            'available' => $this->cache->getMemoryLimit(),
            'fragmentation' => $this->cache->getFragmentationRatio()
        ];
    }

    private function collectPerformanceMetrics(): void 
    {
        $this->metrics['performance'] = [
            'average_get_time' => $this->cache->getAverageGetTime(),
            'average_set_time' => $this->cache->getAverageSetTime(),
            'evictions' => $this->cache->getEvictionCount()
        ];
    }

    private function collectKeyMetrics(): void 
    {
        $this->metrics['keys'] = [
            'total' => $this->cache->getKeyCount(),
            'expired' => $this->cache->getExpiredCount(),
            'evicted' => $this->cache->getEvictedCount()
        ];
    }

    private function calculateHitRate(int $hits, int $misses): float 
    {
        $total = $hits + $misses;
        return $total > 0 ? ($hits / $total) * 100 : 0;
    }
}

class CachePerformanceAnalyzer {
    private array $thresholds;

    public function analyze(CacheMetrics $metrics): PerformanceAnalysis 
    {
        $issues = [];
        $recommendations = [];

        $this->analyzeResponseTimes($metrics, $issues, $recommendations);
        $this->analyzeEvictionRate($metrics, $issues, $recommendations);
        $this->analyzeFragmentation($metrics, $issues, $recommendations);

        return new PerformanceAnalysis($issues, $recommendations);
    }

    private function analyzeResponseTimes(
        CacheMetrics $metrics,
        array &$issues,
        array &$recommendations
    ): void {
        $performance = $metrics->getPerformanceMetrics();

        if ($performance['average_get_time'] > $this->thresholds['get_time']) {
            $issues[] = new PerformanceIssue(
                'high_get_time',
                'Average GET operation time exceeds threshold',
                $performance['average_get_time']
            );
            $recommendations[] = new Recommendation(
                'optimize_get_operations',
                'Consider optimizing GET operations or scaling cache resources'
            );
        }

        if ($performance['average_set_time'] > $this->thresholds['set_time']) {
            $issues[] = new PerformanceIssue(
                'high_set_time',
                'Average SET operation time exceeds threshold',
                $performance['average_set_time']
            );
            $recommendations[] = new Recommendation(
                'optimize_set_operations',
                'Consider optimizing SET operations or reviewing cache value sizes'
            );
        }
    }

    private function analyzeEvictionRate(
        CacheMetrics $metrics,
        array &$issues,
        array &$recommendations
    ): void {
        $evictions = $metrics->getPerformanceMetrics()['evictions'];
        $threshold = $this->thresholds['eviction_rate'];

        if ($evictions > $threshold) {
            $issues[] = new PerformanceIssue(
                'high_eviction_rate',
                'Cache eviction rate exceeds threshold',
                $evictions
            );
            $recommendations[] = new Recommendation(
                'review_cache_size',
                'Consider increasing cache size or reviewing cache policy'
            );
        }
    }

    private function analyzeFragmentation(
        CacheMetrics $metrics,
        array &$issues,
        array &$recommendations
    ): void {
        $fragmentation = $metrics->getMemoryMetrics()['fragmentation'];
        $threshold = $this->thresholds['fragmentation'];

        if ($fragmentation > $threshold) {
            $issues[] = new PerformanceIssue(
                'high_fragmentation',
                'Cache memory fragmentation exceeds threshold',
                $fragmentation
            );
            $recommendations[] = new Recommendation(
                'defragment_cache',
                'Consider scheduling cache defragmentation'
            );
        }
    }
}

class HitRateOptimizer {
    private float $minimumRate;
    private array $optimizationRules;

    public function analyze(CacheMetrics $metrics): HitRateAnalysis 
    {
        $hitRate = $metrics->getHitRate();
        $analysis = new HitRateAnalysis($hitRate);

        if ($hitRate < $this->minimumRate) {
            foreach ($this->optimizationRules as $rule) {
                $recommendation = $rule->analyze($metrics);
                if ($recommendation) {
                    $analysis->addRecommendation($recommendation);
                }
            }
        }

        return $analysis;
    }

    public function getMinimumRate(): float 
    {
        return $this->minimumRate;
    }
}

class MemoryAnalyzer {
    private float $capacityThreshold;
    private float $fragmentationThreshold;

    public function analyze(CacheMetrics $metrics): MemoryAnalysis 
    {
        $memoryMetrics = $metrics->getMemoryMetrics();
        $analysis = new MemoryAnalysis($memoryMetrics);

        $this->analyzeCapacity($analysis, $memoryMetrics);
        $this->analyzeFragmentation($analysis, $memoryMetrics);

        return $analysis;
    }

    private function analyzeCapacity(MemoryAnalysis $analysis, array $metrics): void 
    {
        $usagePercent = ($metrics['used'] / $metrics['available']) * 100;
        
        if ($usagePercent > $this->capacityThreshold) {
            $analysis->addIssue(new MemoryIssue(
                'high_memory_usage',
                'Memory usage exceeds capacity threshold',
                $usagePercent
            ));
        }
    }

    private function analyzeFragmentation(MemoryAnalysis $analysis, array $metrics): void 
    {
        if ($metrics['fragmentation'] > $this->fragmentationThreshold) {
            $analysis->addIssue(new MemoryIssue(
                'high_fragmentation',
                'Memory fragmentation exceeds threshold',
                $metrics['fragmentation']
            ));
        }
    }
}

class CacheReport {
    private CacheMetrics $metrics;
    private PerformanceAnalysis $performance;
    private HitRateAnalysis $hitRate;
    private MemoryAnalysis $memory;
    private float $timestamp;

    public function __construct(
        CacheMetrics $metrics,
        PerformanceAnalysis $performance,
        HitRateAnalysis $hitRate,
        MemoryAnalysis $memory
    ) {
        $this->metrics = $metrics;
        $this->performance = $performance;
        $this->hitRate = $hitRate;
        $this->memory = $memory;
        $this->timestamp = microtime(true);
    }

    public function toArray(): array 
    {
        return [
            'metrics' => $this->metrics->toArray(),
            'performance' => $this->performance->toArray(),
            'hit_rate' => $this->hitRate->toArray(),
            'memory' => $this->memory->toArray(),
            'timestamp' => $this->timestamp
        ];
    }
}
