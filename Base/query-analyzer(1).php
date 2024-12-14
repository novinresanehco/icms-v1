<?php

namespace App\Core\Repositories\Analysis;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class QueryAnalyzer
{
    protected array $queryLog = [];
    protected array $slowQueries = [];
    protected array $queryPatterns = [];
    protected float $slowQueryThreshold;

    public function __construct(float $slowQueryThreshold = 100.0)
    {
        $this->slowQueryThreshold = $slowQueryThreshold;
    }

    public function analyzeQuery(Builder $query): array
    {
        $explainer = DB::raw('EXPLAIN ANALYZE ' . $query->toSql());
        $analysis = DB::select($explainer, $query->getBindings());

        return $this->processExplainResults($analysis, $query);
    }

    public function recordQuery(string $sql, array $bindings, float $time): void
    {
        $normalizedSql = $this->normalizeSql($sql);
        $hash = md5($normalizedSql);

        $this->queryLog[] = [
            'sql' => $sql,
            'normalized_sql' => $normalizedSql,
            'bindings' => $bindings,
            'time' => $time,
            'timestamp' => microtime(true),
            'pattern_hash' => $hash
        ];

        $this->updateQueryPatterns($hash, $normalizedSql, $time);

        if ($time > $this->slowQueryThreshold) {
            $this->slowQueries[] = end($this->queryLog);
        }
    }

    protected function processExplainResults(array $analysis, Builder $query): array
    {
        $result = [
            'query' => $query->toSql(),
            'bindings' => $query->getBindings(),
            'tables' => $this->extractTables($analysis),
            'indexes' => $this->extractIndexes($analysis),
            'operations' => $this->extractOperations($analysis),
            'suggestions' => []
        ];

        // Analyze and add suggestions
        $result['suggestions'] = array_merge(
            $this->analyzeMissingIndexes($analysis),
            $this->analyzeJoinEfficiency($analysis),
            $this->analyzeTableScans($analysis)
        );

        return $result;
    }

    protected function normalizeSql(string $sql): string
    {
        // Replace specific values with placeholders
        $normalized = preg_replace('/[\'"]\d+[\'"]/', '?', $sql);
        $normalized = preg_replace('/\d+/', '?', $normalized);
        
        // Normalize IN clauses
        $normalized = preg_replace('/IN \([^\)]+\)/', 'IN (?)', $normalized);
        
        return trim($normalized);
    }

    protected function updateQueryPatterns(string $hash, string $sql, float $time): void
    {
        if (!isset($this->queryPatterns[$hash])) {
            $this->queryPatterns[$hash] = [
                'sql' => $sql,
                'count' => 0,
                'total_time' => 0,
                'min_time' => $time,
                'max_time' => $time
            ];
        }

        $pattern = &$this->queryPatterns[$hash];
        $pattern['count']++;
        $pattern['total_time'] += $time;
        $pattern['min_time'] = min($pattern['min_time'], $time);
        $pattern['max_time'] = max($pattern['max_time'], $time);
    }

    public function getAnalysis(): array
    {
        return [
            'summary' => $this->generateSummary(),
            'patterns' => $this->analyzePatterns(),
            'slow_queries' => $this->analyzeSlowQueries(),
            'optimization_suggestions' => $this->generateSuggestions()
        ];
    }

    protected function generateSummary(): array
    {
        $totalQueries = count($this->queryLog);
        $totalTime = array_sum(array_column($this->queryLog, 'time'));
        
        return [
            'total_queries' => $totalQueries,
            'total_time' => $totalTime,
            'average_time' => $totalQueries ? $totalTime / $totalQueries : 0,
            'slow_queries' => count($this->slowQueries),
            'unique_patterns' => count($this->queryPatterns)
        ];
    }

    protected function analyzePatterns(): array
    {
        return collect($this->queryPatterns)
            ->map(function ($pattern) {
                $avgTime = $pattern['total_time'] / $pattern['count'];
                return array_merge($pattern, [
                    'average_time' => $avgTime,
                    'frequency' => $pattern['count'] / count($this->queryLog),
                    'performance_rating' => $this->calculatePerformanceRating($avgTime)
                ]);
            })
            ->sortByDesc('count')
            ->values()
            ->toArray();
    }

    protected function generateSuggestions(): array
    {
        $suggestions = [];

        // Analyze query patterns for potential optimizations
        foreach ($this->queryPatterns as $pattern) {
            if ($pattern['average_time'] > $this->slowQueryThreshold) {
                $suggestions[] = [
                    'type' => 'slow_pattern',
                    'sql' => $pattern['sql'],
                    'avg_time' => $pattern['average_time'],
                    'frequency' => $pattern['count'],
                    'recommendation' => $this->suggestOptimization($pattern)
                ];
            }
        }

        return $suggestions;
    }

    protected function suggestOptimization(array $pattern): string
    {
        // Add optimization suggestions based on query pattern
        if (stripos($pattern['sql'], 'WHERE') !== false && 
            stripos($pattern['sql'], 'INDEX') === false) {
            return 'Consider adding an index for the WHERE clause conditions';
        }

        if (stripos($pattern['sql'], 'JOIN') !== false && 
            $pattern['average_time'] > $this->slowQueryThreshold) {
            return 'Review join conditions and ensure proper indexes are in place';
        }

        return 'Review query for potential optimization opportunities';
    }

    protected function calculatePerformanceRating(float $avgTime): string
    {
        if ($avgTime <= $this->slowQueryThreshold * 0.1) {
            return 'Excellent';
        } elseif ($avgTime <= $this->slowQueryThreshold * 0.5) {
            return 'Good';
        } elseif ($avgTime <= $this->slowQueryThreshold) {
            return 'Fair';
        }
        return 'Poor';
    }
}
