<?php

namespace App\Core\Tag\Services;

use App\Core\Tag\Models\Tag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Core\Tag\Exceptions\TagPerformanceException;

class TagPerformanceService
{
    /**
     * @var TagCacheService
     */
    protected TagCacheService $cacheService;

    public function __construct(TagCacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    /**
     * Optimize tag queries.
     */
    public function optimizeQueries(): array
    {
        $stats = [
            'analyzed_queries' => 0,
            'optimized_queries' => 0,
            'created_indexes' => 0
        ];

        try {
            // Analyze slow queries
            $slowQueries = $this->analyzeSlowQueries();
            $stats['analyzed_queries'] = count($slowQueries);

            // Create missing indexes
            $stats['created_indexes'] = $this->createMissingIndexes($slowQueries);

            // Optimize query patterns
            $stats['optimized_queries'] = $this->optimizeQueryPatterns($slowQueries);

            return $stats;
        } catch (\Exception $e) {
            throw new TagPerformanceException("Query optimization failed: {$e->getMessage()}");
        }
    }

    /**
     * Warm up tag caches.
     */
    public function warmupCaches(): array
    {
        $stats = [
            'warmed_up' => 0,
            'failed' => 0
        ];

        try {
            // Warm up frequently accessed tags
            $frequentTags = $this->getFrequentlyAccessedTags();
            foreach ($frequentTags as $tag) {
                try {
                    $this->cacheService->warmupTag($tag->id);
                    $stats['warmed_up']++;
                } catch (\Exception $e) {
                    $stats['failed']++;
                }
            }

            // Warm up popular tag lists
            $this->warmupPopularTagLists();
            $stats['warmed_up']++;

            return $stats;
        } catch (\Exception $e) {
            throw new TagPerformanceException("Cache warmup failed: {$e->getMessage()}");
        }
    }

    /**
     * Monitor tag performance.
     */
    public function monitorPerformance(): array
    {
        return [
            'query_stats' => $this->collectQueryStats(),
            'cache_stats' => $this->collectCacheStats(),
            'memory_usage' => $this->collectMemoryStats()
        ];
    }

    /**
     * Get frequently accessed tags.
     */
    protected function getFrequentlyAccessedTags(): Collection
    {
        return DB::table('taggables')
            ->select('tag_id', DB::raw('COUNT(*) as usage_count'))
            ->groupBy('tag_id')
            ->orderByDesc('usage_count')
            ->limit(100)
            ->get();
    }

    /**
     * Analyze slow queries.
     */
    protected function analyzeSlowQueries(): array
    {
        return DB::select("
            SELECT query, count(*) as count, avg(duration) as avg_duration
            FROM slow_query_log
            WHERE table_name LIKE '%tags%'
            GROUP BY query
            HAVING avg_duration > ?
            ORDER BY avg_duration DESC
        ", [100]); // 100ms threshold
    }

    /**
     * Create missing indexes.
     */
    protected function createMissingIndexes(array $slowQueries): int
    {
        $createdIndexes = 0;

        foreach ($slowQueries as $query) {
            $suggestedIndexes = $this->suggestIndexes($query->query);
            foreach ($suggestedIndexes as $index) {
                if (!$this->indexExists($index)) {
                    DB::statement($this->buildCreateIndexSQL($index));
                    $createdIndexes++;
                }
            }
        }

        return $createdIndexes;
    }

    /**
     * Optimize query patterns.
     */
    protected function optimizeQueryPatterns(array $slowQueries): int
    {
        $optimizedQueries = 0;

        foreach ($slowQueries as $query) {
            $optimizedQuery = $this->optimizeQuery($query->query);
            if ($optimizedQuery !== $query->query) {
                // Store optimized query pattern
                $this->storeOptimizedPattern($query->query, $optimizedQuery);
                $optimizedQueries++;
            }
        }

        return $optimizedQueries;
    }

    /**
     * Collect query statistics.
     */
    protected function collectQueryStats(): array
    {
        return [
            'avg_response_time' => $this->calculateAverageResponseTime(),
            'slow_queries' => $this->countSlowQueries(),
            'query_patterns' => $this->analyzeQueryPatterns()
        ];
    }

    /**
     * Collect cache statistics.
     */
    protected function collectCacheStats(): array
    {
        return [
            'hit_ratio' => $this->calculateCacheHitRatio(),
            'memory_usage' => $this->getCacheMemoryUsage(),
            'expired_keys' => $this->countExpiredCacheKeys()
        ];
    }

    /**
     * Collect memory statistics.
     */
    protected function collectMemoryStats(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ];
    }
}
