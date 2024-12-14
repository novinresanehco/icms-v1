<?php

namespace App\Core\Metrics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class MetricsStore implements MetricsStorageInterface 
{
    private SecurityManager $security;
    
    public function __construct(SecurityManager $security) 
    {
        $this->security = $security;
    }

    public function store(string $metricsId, array $encryptedMetrics, int $ttl): void
    {
        DB::table('system_metrics')->insert([
            'metrics_id' => $metricsId,
            'metrics_data' => $encryptedMetrics,
            'created_at' => now(),
            'expires_at' => now()->addSeconds($ttl),
            'checksum' => $this->generateChecksum($encryptedMetrics)
        ]);

        $this->updateAggregates($metricsId, $encryptedMetrics);
    }

    public function getAggregatedMetric(string $metric, string $aggregation): float
    {
        $cacheKey = "metric_{$metric}_{$aggregation}";
        
        return Cache::remember($cacheKey, now()->addMinutes(5), function() use ($metric, $aggregation) {
            return match($aggregation) {
                'avg' => $this->calculateAverage($metric),
                'max' => $this->calculateMax($metric),
                'min' => $this->calculateMin($metric),
                'sum' => $this->calculateSum($metric),
                'p95' => $this->calculatePercentile($metric, 95),
                default => throw new MetricsException("Unknown aggregation: {$aggregation}")
            };
        });
    }

    public function getCurrentMetric(string $metric): int
    {
        $cacheKey = "current_metric_{$metric}";
        
        return Cache::remember($cacheKey, now()->addSeconds(10), function() use ($metric) {
            return $this->fetchCurrentMetric($metric);
        });
    }

    public function getMetricBreakdown(string $metric): array
    {
        $cacheKey = "metric_breakdown_{$metric}";
        
        return Cache::remember($cacheKey, now()->addMinutes(15), function() use ($metric) {
            return $this->calculateMetricBreakdown($metric);
        });
    }

    public function getBaselineMetrics(int $period): array
    {
        return Cache::remember('baseline_metrics', now()->addHours(1), function() use ($period) {
            return $this->calculateBaselineMetrics($period);
        });
    }

    private function generateChecksum(array $metrics): string
    {
        return hash_hmac(
            'sha256',
            serialize($metrics),
            config('app.key')
        );
    }

    private function updateAggregates(string $metricsId, array $metrics): void
    {
        DB::transaction(function() use ($metricsId, $metrics) {
            foreach ($this->extractMetricValues($metrics) as $metric => $value) {
                DB::table('metric_aggregates')->insert([
                    'metrics_id' => $metricsId,
                    'metric_name' => $metric,
                    'metric_value' => $value,
                    'created_at' => now()
                ]);
            }
        });
    }

    private function calculateAverage(string $metric): float
    {
        return DB::table('metric_aggregates')
            ->where('metric_name', $metric)
            ->where('created_at', '>=', now()->subMinutes(15))
            ->avg('metric_value') ?? 0.0;
    }

    private function calculateMax(string $metric): float
    {
        return DB::table('metric_aggregates')
            ->where('metric_name', $metric)
            ->where('created_at', '>=', now()->subMinutes(15))
            ->max('metric_value') ?? 0.0;
    }

    private function calculateMin(string $metric): float
    {
        return DB::table('metric_aggregates')
            ->where('metric_name', $metric)
            ->where('created_at', '>=', now()->subMinutes(15))
            ->min('metric_value') ?? 0.0;
    }

    private function calculateSum(string $metric): float
    {
        return DB::table('metric_aggregates')
            ->where('metric_name', $metric)
            ->where('created_at', '>=', now()->subMinutes(15))
            ->sum('metric_value') ?? 0.0;
    }

    private function calculatePercentile(string $metric, int $percentile): float
    {
        $values = DB::table('metric_aggregates')
            ->where('metric_name', $metric)
            ->where('created_at', '>=', now()->subMinutes(15))
            ->pluck('metric_value')
            ->sort()
            ->values()
            ->toArray();

        if (empty($values)) {
            return 0.0;
        }

        $index = (int) ceil(($percentile / 100) * count($values));
        return $values[$index - 1];
    }

    private function fetchCurrentMetric(string $metric): int
    {
        return DB::table('metric_aggregates')
            ->where('metric_name', $metric)
            ->where('created_at', '>=', now()->subSeconds(60))
            ->value('metric_value') ?? 0;
    }

    private function calculateMetricBreakdown(string $metric): array
    {
        return DB::table('metric_aggregates')
            ->select('metric_value', DB::raw('count(*) as count'))
            ->where('metric_name', $metric)
            ->where('created_at', '>=', now()->subMinutes(15))
            ->groupBy('metric_value')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->mapWithKeys(function($row) {
                return [(string)$row->metric_value => $row->count];
            })
            ->toArray();
    }

    private function calculateBaselineMetrics(int $period): array
    {
        return DB::table('metric_aggregates')
            ->select('metric_name', DB::raw('avg(metric_value) as baseline'))
            ->where('created_at', '>=', now()->subDays($period))
            ->groupBy('metric_name')
            ->get()
            ->mapWithKeys(function($row) {
                return [$row->metric_name => $row->baseline];
            })
            ->toArray();
    }

    private function extractMetricValues(array $metrics): array
    {
        $values = [];
        foreach ($metrics as $category => $categoryMetrics) {
            if (is_array($categoryMetrics)) {
                foreach ($categoryMetrics as $key => $value) {
                    if (is_numeric($value)) {
                        $values["{$category}.{$key}"] = $value;
                    }
                }
            }
        }
        return $values;
    }
}
