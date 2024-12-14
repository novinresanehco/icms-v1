<?php

namespace App\Core\Infrastructure;

class PerformanceMonitor 
{
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    
    private const THRESHOLDS = [
        'response_time' => 200, // ms
        'memory_usage' => 75, // percentage
        'cpu_load' => 70 // percentage
    ];

    public function track(callable $operation): mixed 
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        try {
            $result = $operation();
            
            $this->collectMetrics(
                microtime(true) - $startTime,
                memory_get_usage() - $startMemory
            );

            return $result;
        } catch (\Exception $e) {
            $this->alerts->critical('Performance operation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function collectMetrics(float $duration, int $memory): void 
    {
        $metrics = [
            'duration' => $duration * 1000, // convert to ms
            'memory' => $memory / 1024 / 1024, // convert to MB
            'cpu' => sys_getloadavg()[0]
        ];

        $this->metrics->record($metrics);
        $this->checkThresholds($metrics);
    }

    private function checkThresholds(array $metrics): void 
    {
        if ($metrics['duration'] > self::THRESHOLDS['response_time']) {
            $this->alerts->warning('Response time threshold exceeded');
        }

        if (($metrics['memory'] / PHP_MEMORY_LIMIT) * 100 > self::THRESHOLDS['memory_usage']) {
            $this->alerts->warning('Memory usage threshold exceeded');
        }

        if ($metrics['cpu'] > self::THRESHOLDS['cpu_load']) {
            $this->alerts->warning('CPU load threshold exceeded');
        }
    }
}

class CacheOptimizer 
{
    private CacheManager $cache;
    private MetricsCollector $metrics;

    public function optimize(): void 
    {
        $this->cleanupExpiredCache();
        $this->optimizeMemoryUsage();
        $this->recordCacheMetrics();
    }

    private function cleanupExpiredCache(): void 
    {
        $this->cache->cleanup([
            'expired' => true,
            'unused' => 24 * 3600 // 24 hours
        ]);
    }

    private function optimizeMemoryUsage(): void 
    {
        if ($this->cache->getMemoryUsage() > 75) {
            $this->cache->clear(['priority' => 'low']);
        }
    }

    private function recordCacheMetrics(): void 
    {
        $this->metrics->record([
            'cache_hits' => $this->cache->getHitRate(),
            'cache_memory' => $this->cache->getMemoryUsage(),
            'cache_items' => $this->cache->getItemCount()
        ]);
    }
}

class DatabaseOptimizer 
{
    private QueryAnalyzer $analyzer;
    private IndexManager $indexManager;

    public function optimize(): void 
    {
        $this->analyzeQueries();
        $this->optimizeIndexes();
        $this->cleanupConnections();
    }

    private function analyzeQueries(): void 
    {
        $slowQueries = $this->analyzer->findSlowQueries();
        foreach ($slowQueries as $query) {
            $this->optimizeQuery($query);
        }
    }

    private function optimizeIndexes(): void 
    {
        $unusedIndexes = $this->indexManager->findUnusedIndexes();
        foreach ($unusedIndexes as $index) {
            $this->indexManager->removeIndex($index);
        }

        $missingIndexes = $this->indexManager->findMissingIndexes();
        foreach ($missingIndexes as $index) {
            $this->indexManager->createIndex($index);
        }
    }

    private function cleanupConnections(): void 
    {
        DB::disconnect();
        gc_collect_cycles();
    }
}
