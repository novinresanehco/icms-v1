<?php

namespace App\Core\Repositories\Profiler;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class QueryProfiler
{
    protected array $queries = [];
    protected array $slowQueries = [];
    protected float $slowQueryThreshold;

    public function __construct(float $slowQueryThreshold = 1.0)
    {
        $this->slowQueryThreshold = $slowQueryThreshold;
    }

    public function profile(callable $callback)
    {
        DB::enableQueryLog();

        $startTime = microtime(true);
        $result = $callback();
        $endTime = microtime(true);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->analyzeQueries($queries, $startTime, $endTime);

        return $result;
    }

    protected function analyzeQueries(array $queries, float $startTime, float $endTime): void
    {
        foreach ($queries as $query) {
            $queryInfo = [
                'sql' => $query['query'],
                'bindings' => $query['bindings'],
                'time' => $query['time'],
                'timestamp' => now()
            ];

            $this->queries[] = $queryInfo;

            if ($query['time'] > $this->slowQueryThreshold) {
                $this->slowQueries[] = array_merge($queryInfo, [
                    'stack_trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)
                ]);
            }
        }

        $this->queries[] = [
            'total_time' => $endTime - $startTime,
            'query_count' => count($queries),
            'average_time' => count($queries) ? ($endTime - $startTime) / count($queries) : 0
        ];
    }

    public function getQueryReport(): array
    {
        return [
            'summary' => $this->getQuerySummary(),
            'slow_queries' => $this->slowQueries,
            'all_queries' => $this->queries
        ];
    }

    protected function getQuerySummary(): array
    {
        $totalQueries = count($this->queries) - 1; // Excluding summary record
        $totalTime = end($this->queries)['total_time'];
        $slowQueryCount = count($this->slowQueries);

        return [
            'total_queries' => $totalQueries,
            'total_time' => $totalTime,
            'average_time' => $totalQueries ? $totalTime / $totalQueries : 0,
            'slow_queries' => $slowQueryCount,
            'slow_query_percentage' => $totalQueries ? ($slowQueryCount / $totalQueries) * 100 : 0
        ];
    }
}
