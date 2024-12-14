<?php

namespace App\Core\Audit;

class AuditDataAggregator
{
    private DataTransformer $transformer;
    private MetricsCalculator $calculator;
    private TimeSeriesAnalyzer $timeAnalyzer;
    private CacheManager $cache;
    private array $config;

    public function __construct(
        DataTransformer $transformer,
        MetricsCalculator $calculator,
        TimeSeriesAnalyzer $timeAnalyzer,
        CacheManager $cache,
        array $config = []
    ) {
        $this->transformer = $transformer;
        $this->calculator = $calculator;
        $this->timeAnalyzer = $timeAnalyzer;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function aggregate(array $data, AggregationConfig $config): AggregationResult
    {
        try {
            // Transform data
            $transformedData = $this->transformer->transform($data);

            // Perform time-based aggregations
            $timeAggregations = $this->aggregateByTime($transformedData, $config);

            // Perform category aggregations
            $categoryAggregations = $this->aggregateByCategory($transformedData, $config);

            // Perform metric aggregations
            $metricAggregations = $this->aggregateMetrics($transformedData, $config);

            // Combine results
            $result = new AggregationResult([
                'time_series' => $timeAggregations,
                'categories' => $categoryAggregations,
                'metrics' => $metricAggregations,
                'metadata' => $this->generateMetadata($transformedData)
            ]);

            // Cache results if needed
            if ($config->shouldCache()) {
                $this->cacheResults($result, $config);
            }

            return $result;

        } catch (\Exception $e) {
            throw new AggregationException(
                'Failed to aggregate audit data: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    protected function aggregateByTime(array $data, AggregationConfig $config): array
    {
        $intervals = $config->getTimeIntervals();
        $metrics = $config->getTimeMetrics();

        return $this->timeAnalyzer->analyze($data, [
            'intervals' => $intervals,
            'metrics' => $metrics,
            'grouping' => $config->getTimeGrouping()
        ]);
    }

    protected function aggregateByCategory(array $data, AggregationConfig $config): array
    {
        $categories = [];

        foreach ($config->getCategories() as $category) {
            $categories[$category] = $this->aggregateCategory(
                $data,
                $category,
                $config->getCategoryConfig($category)
            );
        }

        return $categories;
    }

    protected function aggregateCategory(array $data, string $category, array $config): array
    {
        return [
            'total' => $this->calculateCategoryTotal($data, $category),
            'distribution' => $this->calculateDistribution($data, $category),
            'trends' => $this->calculateCategoryTrends($data, $category),
            'metrics' => $this->calculateCategoryMetrics($data, $category, $config)
        ];
    }

    protected function aggregateMetrics(array $data, AggregationConfig $config): array
    {
        $metrics = [];

        foreach ($config->getMetrics() as $metric => $settings) {
            $metrics[$metric] = $this->calculator->calculate(
                $data,
                $metric,
                $settings
            );
        }

        return $metrics;
    }

    protected function calculateDistribution(array $data, string $category): array
    {
        $distribution = [];

        foreach ($data as $item) {
            $value = $item[$category] ?? 'unknown';
            $distribution[$value] = ($distribution[$value] ?? 0) + 1;
        }

        arsort($distribution);

        return [
            'values' => $distribution,
            'statistics' => $this->calculateDistributionStatistics($distribution)
        ];
    }

    protected function calculateDistributionStatistics(array $distribution): array
    {
        $total = array_sum($distribution);
        $stats = [];

        foreach ($distribution as $value => $count) {
            $stats[$value] = [
                'count' => $count,
                'percentage' => ($count / $total) * 100,
                'rank' => array_search($value, array_keys($distribution)) + 1
            ];
        }

        return $stats;
    }

    protected function calculateCategoryTrends(array $data, string $category): array
    {
        return $this->timeAnalyzer->analyzeTrends(
            $data,
            $category,
            [
                'interval' => $this->config['trend_interval'] ?? 'daily',
                'metrics' => $this->config['trend_metrics'] ?? ['count', 'average'],
                'window' => $this->config['trend_window'] ?? 30
            ]
        );
    }

    protected function cacheResults(AggregationResult $result, AggregationConfig $config): void
    {
        $key = $this->generateCacheKey($config);
        $duration = $config->getCacheDuration();

        $this->cache->put($key, $result, $duration);
    }

    protected function generateCacheKey(AggregationConfig $config): string
    {
        return 'audit:aggregation:' . md5(serialize([
            'config' => $config->toArray(),
            'timestamp' => floor(time() / ($config->getCacheDuration() ?? 3600))
        ]));
    }

    protected function generateMetadata(array $data): array
    {
        return [
            'total_records' => count($data),
            'time_range' => [
                'start' => min(array_column($data, 'timestamp')),
                'end' => max(array_column($data, 'timestamp'))
            ],
            'categories' => array_unique(array_merge(...array_map(
                fn($item) => array_keys($item),
                $data
            ))),
            'aggregation_timestamp' => now()
        ];
    }
}
