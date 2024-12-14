<?php

namespace App\Core\Security\Services;

use Illuminate\Support\Facades\Cache;
use App\Core\Interfaces\MetricsInterface;
use App\Core\Security\Events\MetricEvent;

class MetricsCollector implements MetricsInterface
{
    private StorageManager $storage;
    private AnalyticsEngine $analytics;
    private array $config;
    private array $metrics;

    private const CACHE_TTL = 3600;
    private const BATCH_SIZE = 1000;
    private const CRITICAL_THRESHOLD = 90;

    public function __construct(
        StorageManager $storage,
        AnalyticsEngine $analytics,
        array $config
    ) {
        $this->storage = $storage;
        $this->analytics = $analytics;
        $this->config = $config;
        $this->metrics = [];
    }

    public function recordMetric(string $name, $value, array $tags = []): void
    {
        $metric = [
            'name' => $name,
            'value' => $value,
            'tags' => $tags,
            'timestamp' => microtime(true)
        ];

        $this->validateMetric($metric);
        $this->storeMetric($metric);
        $this->analyzeMetric($metric);

        if ($this->isSignificant($metric)) {
            $this->processSignificantMetric($metric);
        }
    }

    public function getMetrics(string $name, array $criteria = []): array
    {
        $metrics = $this->fetchMetrics($name, $criteria);
        return $this->processMetrics($metrics);
    }

    public function recordPerformanceMetrics(array $data): void
    {
        $metrics = $this->extractPerformanceMetrics($data);
        
        foreach ($metrics as $name => $value) {
            $this->recordMetric("performance.$name", $value, ['type' => 'performance']);
        }

        $this->analyzePerformanceData($metrics);
    }

    public function recordSecurityMetrics(array $data): void
    {
        $metrics = $this->extractSecurityMetrics($data);
        
        foreach ($metrics as $name => $value) {
            $this->recordMetric("security.$name", $value, ['type' => 'security']);
        }

        $this->analyzeSecurityData($metrics);
    }

    public function recordSystemMetrics(): void
    {
        $metrics = $this->collectSystemMetrics();
        $this->validateSystemMetrics($metrics);
        
        foreach ($metrics as $name => $value) {
            $this->recordMetric("system.$name", $value, ['type' => 'system']);
        }

        $this->analyzeSystemHealth($metrics);
    }

    public function getAggregatedMetrics(string $name, string $aggregation, array $criteria = []): array
    {
        $metrics = $this->fetchMetrics($name, $criteria);
        return $this->aggregateMetrics($metrics, $aggregation);
    }

    private function validateMetric(array $metric): void
    {
        if (empty($metric['name'])) {
            throw new MetricValidationException('Metric name is required');
        }

        if (!isset($metric['value'])) {
            throw new MetricValidationException('Metric value is required');
        }

        $this->validateMetricValue($metric['value']);
        $this->validateMetricTags($metric['tags']);
    }

    private function storeMetric(array $metric): void
    {
        $key = $this->getMetricKey($metric['name']);
        $batch = $this->getCurrentBatch($key);
        
        $batch[] = $metric;
        
        if (count($batch) >= self::BATCH_SIZE) {
            $this->flushBatch($key, $batch);
        } else {
            $this->saveBatch($key, $batch);
        }
    }

    private function analyzeMetric(array $metric): void
    {
        $analysis = $this->analytics->analyzeMetric($metric);
        
        if ($analysis['anomaly_score'] > $this->config['anomaly_threshold']) {
            $this->handleAnomaly($metric, $analysis);
        }

        if ($analysis['trend_score'] > $this->config['trend_threshold']) {
            $this->handleTrend($metric, $analysis);
        }
    }

    private function processSignificantMetric(array $metric): void
    {
        $event = new MetricEvent(
            $metric['name'],
            $metric['value'],
            $metric['tags']
        );

        $this->analytics->processSignificantEvent($event);
        $this->updateMetricStatus($metric);
    }

    private function fetchMetrics(string $name, array $criteria): array
    {
        $key = $this->getMetricKey($name);
        $metrics = $this->storage->fetch($key, $criteria);
        
        return array_filter($metrics, fn($metric) => 
            $this->matchesCriteria($metric, $criteria)
        );
    }

    private function processMetrics(array $metrics): array
    {
        $processed = [];
        
        foreach ($metrics as $metric) {
            $processed[] = $this->processMetric($metric);
        }

        return $processed;
    }

    private function extractPerformanceMetrics(array $data): array
    {
        return [
            'response_time' => $data['duration'] ?? null,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'cpu_usage' => sys_getloadavg()[0]
        ];
    }

    private function extractSecurityMetrics(array $data): array
    {
        return [
            'auth_attempts' => $data['auth_attempts'] ?? 0,
            'failed_attempts' => $data['failed_attempts'] ?? 0,
            'suspicious_activities' => $data['suspicious_activities'] ?? 0
        ];
    }

    private function collectSystemMetrics(): array
    {
        return [
            'memory' => [
                'used' => memory_get_usage(true),
                'peak' => memory_get_peak_usage(true),
                'limit' => $this->config['memory_limit']
            ],
            'cpu' => [
                'load' => sys_getloadavg(),
                'limit' => $this->config['cpu_limit']
            ],
            'disk' => [
                'free' => disk_free_space('/'),
                'total' => disk_total_space('/')
            ]
        ];
    }

    private function getCurrentBatch(string $key): array
    {
        return Cache::get($this->getBatchKey($key), []);
    }

    private function saveBatch(string $key, array $batch): void
    {
        Cache::put($this->getBatchKey($key), $batch, self::CACHE_TTL);
    }

    private function flushBatch(string $key, array $batch): void
    {
        $this->storage->storeBatch($key, $batch);
        Cache::delete($this->getBatchKey($key));
    }

    private function getBatchKey(string $key): string
    {
        return "metrics:batch:$key";
    }

    private function getMetricKey(string $name): string
    {
        return str_replace('.', ':', $name);
    }

    private function validateMetricValue($value): void
    {
        if (!is_numeric($value) && !is_bool($value)) {
            throw new MetricValidationException('Invalid metric value type');
        }
    }

    private function validateMetricTags(array $tags): void
    {
        foreach ($tags as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                throw new MetricValidationException('Invalid metric tag');
            }
        }
    }

    private function validateSystemMetrics(array $metrics): void
    {
        foreach ($metrics as $category => $values) {
            if (!is_array($values)) {
                throw new MetricValidationException("Invalid system metrics for $category");
            }
        }
    }

    private function isSignificant(array $metric): bool
    {
        return isset($this->config['significant_metrics'][$metric['name']])
            || $this->exceedsThreshold($metric);
    }

    private function exceedsThreshold(array $metric): bool
    {
        $threshold = $this->config['thresholds'][$metric['name']] ?? null;
        if (!$threshold) return false;

        return match($threshold['type']) {
            'max' => $metric['value'] > $threshold['value'],
            'min' => $metric['value'] < $threshold['value'],
            'range' => $metric['value'] < $threshold['min'] || $metric['value'] > $threshold['max'],
            default => false
        };
    }
}
