<?php

namespace App\Core\Tag\Services;

use App\Core\Tag\Models\Tag;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Core\Tag\Contracts\ReportGeneratorInterface;
use App\Core\Tag\DTOs\TagReportData;

class TagReportingService implements ReportGeneratorInterface
{
    /**
     * @var TagAnalyticsService
     */
    protected TagAnalyticsService $analyticsService;

    public function __construct(TagAnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    /**
     * Generate comprehensive tag usage report.
     */
    public function generateUsageReport(array $filters = []): TagReportData
    {
        $data = new TagReportData();
        
        $data->totalTags = $this->getTotalTags($filters);
        $data->activeTagsCount = $this->getActiveTagsCount($filters);
        $data->unusedTagsCount = $this->getUnusedTagsCount();
        $data->topTags = $this->getTopTags($filters);
        $data->tagUsageOverTime = $this->getTagUsageOverTime($filters);
        $data->contentDistribution = $this->getContentDistribution();
        
        return $data;
    }

    /**
     * Generate tag performance report.
     */
    public function generatePerformanceReport(): array
    {
        return [
            'query_performance' => $this->analyzeQueryPerformance(),
            'cache_efficiency' => $this->analyzeCacheEfficiency(),
            'resource_usage' => $this->analyzeResourceUsage(),
            'optimization_suggestions' => $this->generateOptimizationSuggestions()
        ];
    }

    /**
     * Generate tag audit report.
     */
    public function generateAuditReport(): array
    {
        return [
            'duplicate_tags' => $this->findDuplicateTags(),
            'invalid_relationships' => $this->findInvalidRelationships(),
            'orphaned_tags' => $this->findOrphanedTags(),
            'naming_violations' => $this->findNamingViolations()
        ];
    }

    /**
     * Get total tags count.
     */
    protected function getTotalTags(array $filters): int
    {
        $query = Tag::query();

        if (isset($filters['period'])) {
            $query->where('created_at', '>=', now()->sub($filters['period']));
        }

        return $query->count();
    }

    /**
     * Get active tags count.
     */
    protected function getActiveTagsCount(array $filters): int
    {
        return DB::table('taggables')
            ->distinct('tag_id')
            ->when(isset($filters['period']), function ($query) use ($filters) {
                $query->where('created_at', '>=', now()->sub($filters['period']));
            })
            ->count('tag_id');
    }

    /**
     * Get unused tags count.
     */
    protected function getUnusedTagsCount(): int
    {
        return Tag::whereNotExists(function ($query) {
            $query->select(DB::raw(1))
                  ->from('taggables')
                  ->whereColumn('taggables.tag_id', 'tags.id');
        })->count();
    }

    /**
     * Get top tags.
     */
    protected function getTopTags(array $filters, int $limit = 10): Collection
    {
        return DB::table('taggables')
            ->select('tag_id', DB::raw('COUNT(*) as usage_count'))
            ->when(isset($filters['period']), function ($query) use ($filters) {
                $query->where('created_at', '>=', now()->sub($filters['period']));
            })
            ->groupBy('tag_id')
            ->orderByDesc('usage_count')
            ->limit($limit)
            ->get();
    }

    /**
     * Get tag usage over time.
     */
    protected function getTagUsageOverTime(array $filters): Collection
    {
        $interval = $filters['interval'] ?? 'day';
        
        return DB::table('taggables')
            ->select(
                DB::raw("DATE_TRUNC('$interval', created_at) as period"),
                DB::raw('COUNT(*) as usage_count')
            )
            ->when(isset($filters['period']), function ($query) use ($filters) {
                $query->where('created_at', '>=', now()->sub($filters['period']));
            })
            ->groupBy('period')
            ->orderBy('period')
            ->get();
    }

    /**
     * Get content distribution.
     */
    protected function getContentDistribution(): array
    {
        return DB::table('taggables')
            ->select('taggable_type', DB::raw('COUNT(*) as count'))
            ->groupBy('taggable_type')
            ->get()
            ->pluck('count', 'taggable_type')
            ->toArray();
    }

    /**
     * Analyze query performance.
     */
    protected function analyzeQueryPerformance(): array
    {
        return [
            'avg_query_time' => $this->getAverageQueryTime(),
            'slow_queries' => $this->getSlowQueries(),
            'query_patterns' => $this->getQueryPatterns()
        ];
    }

    /**
     * Analyze cache efficiency.
     */
    protected function analyzeCacheEfficiency(): array
    {
        return [
            'hit_ratio' => $this->getCacheHitRatio(),
            'cache_size' => $this->getCacheSize(),
            'stale_entries' => $this->getStaleEntries()
        ];
    }

    /**
     * Generate optimization suggestions.
     */
    protected function generateOptimizationSuggestions(): array
    {
        $suggestions = [];

        // Check for missing indexes
        if ($this->shouldAddIndexes()) {
            $suggestions[] = [
                'type' => 'index',
                'description' => 'Add indexes for frequently queried columns',
                'impact' => 'high'
            ];
        }

        // Check cache configuration
        if ($this->shouldOptimizeCache()) {
            $suggestions[] = [
                'type' => 'cache',
                'description' => 'Optimize cache TTL for frequently accessed tags',
                'impact' => 'medium'
            ];
        }

        // Check query patterns
        if ($this->shouldOptimizeQueries()) {
            $suggestions[] = [
                'type' => 'query',
                'description' => 'Optimize frequently used query patterns',
                'impact' => 'high'
            ];
        }

        return $suggestions;
    }

    /**
     * Check if indexes should be added.
     */
    protected function shouldAddIndexes(): bool
    {
        $slowQueries = $this->getSlowQueries();
        return count($slowQueries) > 0;
    }

    /**
     * Check if cache should be optimized.
     */
    protected function shouldOptimizeCache(): bool
    {
        $hitRatio = $this->getCacheHitRatio();
        return $hitRatio < 0.8; // 80% threshold
    }

    /**
     * Check if queries should be optimized.
     */
    protected function shouldOptimizeQueries(): bool
    {
        $avgQueryTime = $this->getAverageQueryTime();
        return $avgQueryTime > 100; // 100ms threshold
    }
}
