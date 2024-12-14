<?php

namespace App\Core\Database\Performance;

use App\Core\Repositories\Analysis\QueryAnalyzer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class DatabasePerformanceManager
{
    protected QueryAnalyzer $queryAnalyzer;
    protected array $metrics = [];
    protected array $thresholds;
    
    public function __construct(QueryAnalyzer $queryAnalyzer)
    {
        $this->queryAnalyzer = $queryAnalyzer;
        $this->thresholds = config('database.performance.thresholds');
    }

    public function monitorQueryPerformance(): void
    {
        DB::listen(function($query) {
            $this->queryAnalyzer->recordQuery(
                $query->sql,
                $query->bindings,
                $query->time
            );

            $this->checkQueryThresholds($query);
        });
    }

    public function analyzeTablePerformance(string $table): array
    {
        $stats = $this->gatherTableStatistics($table);
        $indexes = $this->analyzeIndexUsage($table);
        $recommendations = $this->generateOptimizationRecommendations($table, $stats, $indexes);

        return [
            'table_name' => $table,
            'statistics' => $stats,
            'index_analysis' => $indexes,
            'recommendations' => $recommendations,
            'health_score' => $this->calculateHealthScore($stats, $indexes)
        ];
    }

    protected function gatherTableStatistics(string $table): array
    {
        $stats = DB::select("
            SELECT 
                (SELECT reltuples::bigint FROM pg_class WHERE relname = ?) as row_count,
                pg_size_pretty(pg_total_relation_size(?)) as total_size,
                pg_size_pretty(pg_table_size(?)) as table_size,
                pg_size_pretty(pg_indexes_size(?)) as index_size,
                (SELECT count(*) FROM pg_indexes WHERE tablename = ?) as index_count
        ", [$table, $table, $table, $table, $table]);

        return (array) $stats[0];
    }

    protected function analyzeIndexUsage(string $table): array
    {
        $indexUsage = DB::select("
            SELECT
                schemaname,
                relname,
                indexrelname,
                idx_scan,
                idx_tup_read,
                idx_tup_fetch
            FROM pg_stat_user_indexes
            WHERE relname = ?
        ", [$table]);

        return collect($indexUsage)->map(function($index) {
            return [
                'name' => $index->indexrelname,
                'scans' => $index->idx_scan,
                'tuples_read' => $index->idx_tup_read,
                'tuples_fetched' => $index->idx_tup_fetch,
                'efficiency' => $this->calculateIndexEfficiency(
                    $index->idx_tup_read,
                    $index->idx_tup_fetch
                )
            ];
        })->toArray();
    }

    protected function generateOptimizationRecommendations(
        string $table,
        array $stats,
        array $indexes
    ): array {
        $recommendations = [];

        // Check table size and suggest partitioning if needed
        if ($stats['row_count'] > 1000000) {
            $recommendations[] = [
                'type' => 'partitioning',
                'priority' => 'high',
                'message' => "Consider table partitioning due to large row count ({$stats['row_count']} rows)"
            ];
        }

        // Analyze index usage efficiency
        foreach ($indexes as $index) {
            if ($index['efficiency'] < 0.5 && $index['scans'] > 0) {
                $recommendations[] = [
                    'type' => 'index_optimization',
                    'priority' => 'medium',
                    'message' => "Index {$index['name']} has low efficiency ({$index['efficiency']}). Consider restructuring."
                ];
            }
        }

        // Check for missing indexes based on query patterns
        $missingIndexes = $this->identifyMissingIndexes($table);
        foreach ($missingIndexes as $suggestion) {
            $recommendations[] = [
                'type' => 'missing_index',
                'priority' => 'high',
                'message' => $suggestion
            ];
        }

        return $recommendations;
    }

    protected function calculateHealthScore(array $stats, array $indexes): float
    {
        $scores = [
            'size_score' => $this->calculateSizeScore($stats),
            'index_score' => $this->calculateIndexScore($indexes),
            'performance_score' => $this->calculatePerformanceScore($stats)
        ];

        return array_sum($scores) / count($scores);
    }

    protected function calculateSizeScore(array $stats): float
    {
        // Implementation of size scoring logic
        $rowCountScore = min(1.0, 1000000 / max(1, $stats['row_count']));
        $sizeScore = min(1.0, 5000000000 / $this->parseSize($stats['total_size']));
        
        return ($rowCountScore + $sizeScore) / 2;
    }

    protected function calculateIndexScore(array $indexes): float
    {
        if (empty($indexes)) {
            return 0.0;
        }

        $totalEfficiency = array_sum(array_column($indexes, 'efficiency'));
        return $totalEfficiency / count($indexes);
    }

    protected function calculatePerformanceScore(array $stats): float
    {
        // Implement performance scoring based on query execution times
        // and other performance metrics
        $queryMetrics = $this->queryAnalyzer->getAnalysis();
        
        return min(1.0, $this->thresholds['query_time'] / 
            max(1, $queryMetrics['summary']['average_time']));
    }

    protected function checkQueryThresholds($query): void
    {
        if ($query->time > $this->thresholds['slow_query']) {
            $this->logSlowQuery($query);
            event(new SlowQueryDetected($query));
        }

        $this->updateMetrics($query);
    }

    protected function updateMetrics($query): void
    {
        $key = 'db_metrics:' . date('Y-m-d');
        $metrics = Cache::get($key, [
            'total_queries' => 0,
            'total_time' => 0,
            'slow_queries' => 0,
        ]);

        $metrics['total_queries']++;
        $metrics['total_time'] += $query->time;
        
        if ($query->time > $this->thresholds['slow_query']) {
            $metrics['slow_queries']++;
        }

        Cache::put($key, $metrics, now()->addDays(7));
    }

    protected function identifyMissingIndexes(string $table): array
    {
        $analysis = $this->queryAnalyzer->getAnalysis();
        $suggestions = [];

        foreach ($analysis['patterns'] as $pattern) {
            if ($pattern['performance_rating'] === 'Poor' && 
                stripos($pattern['sql'], $table) !== false) {
                $suggestions[] = $this->generateIndexSuggestion($pattern['sql'], $table);
            }
        }

        return array_filter($suggestions);
    }

    protected function generateIndexSuggestion(string $sql, string $table): ?string
    {
        // Parse SQL and suggest indexes based on WHERE and JOIN conditions
        preg_match('/WHERE\s+([^;]+)/i', $sql, $whereMatches);
        preg_match('/JOIN\s+' . $table . '\s+ON\s+([^;]+)/i', $sql, $joinMatches);

        $conditions = [];
        if (!empty($whereMatches[1])) {
            $conditions[] = $whereMatches[1];
        }
        if (!empty($joinMatches[1])) {
            $conditions[] = $joinMatches[1];
        }

        if (empty($conditions)) {
            return null;
        }

        $columns = $this->extractColumnsFromConditions(implode(' AND ', $conditions));
        if (empty($columns)) {
            return null;
        }

        return "Consider adding index on columns: " . implode(', ', $columns);
    }

    protected function extractColumnsFromConditions(string $conditions): array
    {
        preg_match_all('/\b(\w+)\b\s*(?:=|>|<|LIKE|IN)\s*/i', $conditions, $matches);
        return array_unique($matches[1] ?? []);
    }

    protected function parseSize(string $size): int
    {
        preg_match('/^(\d+)(\w+)$/', $size, $matches);
        if (empty($matches)) {
            return 0;
        }

        $units = [
            'bytes' => 1,
            'kB' => 1024,
            'MB' => 1024 * 1024,
            'GB' => 1024 * 1024 * 1024
        ];

        return $matches[1] * ($units[$matches[2]] ?? 1);
    }
}
