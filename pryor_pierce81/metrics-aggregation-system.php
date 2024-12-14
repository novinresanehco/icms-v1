<?php

namespace App\Core\Monitoring\Metrics;

class MetricsAggregationSystem
{
    private MetricsCollector $collector;
    private TimeSeriesManager $timeSeriesManager;
    private AggregationStrategy $strategy;
    private StorageManager $storage;
    private MetricsCache $cache;
    private MetricsValidator $validator;

    public function __construct(
        MetricsCollector $collector,
        TimeSeriesManager $timeSeriesManager,
        AggregationStrategy $strategy,
        StorageManager $storage,
        MetricsCache $cache,
        MetricsValidator $validator
    ) {
        $this->collector = $collector;
        $this->timeSeriesManager = $timeSeriesManager;
        $this->strategy = $strategy;
        $this->storage = $storage;
        $this->cache = $cache;
        $this->validator = $validator;
    }

    public function aggregateMetrics(TimeWindow $window): AggregationResult
    {
        $cacheKey = $this->generateCacheKey($window);
        
        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }

        try {
            // Start aggregation transaction
            $transaction = DB::beginTransaction();

            // Collect raw metrics
            $rawMetrics = $this->collector->collect($window);

            // Validate metrics
            $validatedMetrics = $this->validator->validate($rawMetrics);

            // Create time series
            $timeSeries = $this->timeSeriesManager->createTimeSeries(
                $validatedMetrics,
                $window
            );

            // Apply aggregation strategy
            $aggregatedMetrics = $this->strategy->aggregate($timeSeries);

            // Store results
            $result = new AggregationResult(
                $aggregatedMetrics,
                $this->calculateStatistics($aggregatedMetrics),
                $window
            );

            $this->storage->store($result);
            $this->cache->set($cacheKey, $result, $this->getCacheDuration($window));

            // Commit transaction
            $transaction->commit();

            return $result;

        } catch (\Exception $e) {
            $transaction->rollback();
            throw new MetricsAggregationException(
                "Failed to aggregate metrics: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    private function calculateStatistics(AggregatedMetrics $metrics): MetricsStatistics
    {
        return new MetricsStatistics(
            $metrics->getValues(),
            $this->getStatisticalMethods()
        );
    }

    private function getStatisticalMethods(): array
    {
        return [
            new AverageCalculator(),
            new MedianCalculator(),
            new PercentileCalculator(95),
            new StandardDeviationCalculator(),
            new VarianceCalculator()
        ];
    }
}

class TimeSeriesManager
{
    private TimeFrameNormalizer $normalizer;
    private DataPointAggregator $aggregator;
    private OutlierDetector $outlierDetector;

    public function createTimeSeries(ValidatedMetrics $metrics, TimeWindow $window): TimeSeries
    {
        // Normalize time frames
        $normalizedData = $this->normalizer->normalize($metrics, $window);

        // Aggregate data points
        $aggregatedPoints = $this->aggregator->aggregate($normalizedData);

        // Detect and handle outliers
        $cleanedPoints = $this->outlierDetector->removeOutliers($aggregatedPoints);

        return new TimeSeries(
            $cleanedPoints,
            $window,
            $this->generateMetadata($metrics, $cleanedPoints)
        );
    }

    private function generateMetadata(ValidatedMetrics $metrics, array $points): array
    {
        return [
            'point_count' => count($points),
            'start_time' => reset($points)->getTimestamp(),
            'end_time' => end($points)->getTimestamp(),
            'metric_types' => $metrics->getTypes(),
            'resolution' => $this->calculateResolution($points)
        ];
    }

    private function calculateResolution(array $points): string
    {
        if (count($points) < 2) {
            return 'single_point';
        }

        $first = reset($points);
        $second = next($points);
        
        return $second->getTimestamp() - $first->getTimestamp() . 's';
    }
}

class AggregationStrategy
{
    private array $aggregators;
    private StrategySelector $selector;
    private PerformanceMonitor $monitor;

    public function aggregate(TimeSeries $timeSeries): AggregatedMetrics
    {
        // Select appropriate aggregation strategy
        $strategy = $this->selector->select($timeSeries);

        // Start performance monitoring
        $monitor = $this->monitor->start();

        try {
            // Apply selected aggregators
            $results = [];
            foreach ($this->getAggregators($strategy) as $aggregator) {
                $results[$aggregator->getName()] = $aggregator->aggregate($timeSeries);
            }

            return new AggregatedMetrics(
                $results,
                $strategy,
                $monitor->stop()
            );

        } catch (\Exception $e) {
            $monitor->stop();
            throw new AggregationStrategyException(
                "Aggregation strategy failed: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    private function getAggregators(string $strategy): array
    {
        return array_filter(
            $this->aggregators,
            fn($aggregator) => $aggregator->supportsStrategy($strategy)
        );
    }
}

class TimeSeries
{
    private array $dataPoints;
    private TimeWindow $window;
    private array $metadata;
    private array $statistics;

    public function __construct(array $dataPoints, TimeWindow $window, array $metadata)
    {
        $this->dataPoints = $dataPoints;
        $this->window = $window;
        $this->metadata = $metadata;
        $this->statistics = $this->calculateStatistics();
    }

    public function getDataPoints(): array
    {
        return $this->dataPoints;
    }

    public function getWindow(): TimeWindow
    {
        return $this->window;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getStatistics(): array
    {
        return $this->statistics;
    }

    private function calculateStatistics(): array
    {
        $values = array_map(fn($point) => $point->getValue(), $this->dataPoints);

        return [
            'min' => min($values),
            'max' => max($values),
            'avg' => array_sum($values) / count($values),
            'count' => count($values),
            'range' => max($values) - min($values)
        ];
    }
}

class AggregatedMetrics
{
    private array $metrics;
    private string $strategy;
    private PerformanceData $performance;
    private float $timestamp;

    public function __construct(array $metrics, string $strategy, PerformanceData $performance)
    {
        $this->metrics = $metrics;
        $this->strategy = $strategy;
        $this->performance = $performance;
        $this->timestamp = microtime(true);
    }

    public function getValues(): array
    {
        return $this->metrics;
    }

    public function getStrategy(): string
    {
        return $this->strategy;
    }

    public function getPerformance(): PerformanceData
    {
        return $this->performance;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }
}

class MetricsStatistics
{
    private array $values;
    private array $methods;
    private array $results;

    public function __construct(array $values, array $methods)
    {
        $this->values = $values;
        $this->methods = $methods;
        $this->results = $this->calculate();
    }

    private function calculate(): array
    {
        $results = [];

        foreach ($this->methods as $method) {
            try {
                $results[$method->getName()] = $method->calculate($this->values);
            } catch (\Exception $e) {
                Log::warning("Failed to calculate statistics", [
                    'method' => $method->getName(),
                    'error' => $e->getMessage()
                ]);
                $results[$method->getName()] = null;
            }
        }

        return $results;
    }

    public function getResults(): array
    {
        return $this->results;
    }
}
