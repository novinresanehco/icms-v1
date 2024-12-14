<?php

namespace App\Core\Monitoring;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Exceptions\MonitoringException;
use Illuminate\Support\Facades\{DB, Log};

class MetricsManager implements MetricsInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private MetricStore $store;
    private AlertManager $alerts;
    private array $config;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        MetricStore $store,
        AlertManager $alerts,
        array $config
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->store = $store;
        $this->alerts = $alerts;
        $this->config = $config;
    }

    public function recordMetric(string $key, $value, array $tags = []): void
    {
        $startTime = microtime(true);
        
        try {
            $metric = new Metric($key, $value, $tags, time());
            
            $this->validateMetric($metric);
            $this->store->record($metric);
            $this->checkThresholds($metric);
            
            $this->cache->tags(['metrics', $key])->set(
                $this->getCacheKey($metric),
                $metric,
                $this->config['cache_ttl']
            );

        } catch (\Exception $e) {
            $this->handleError($e, $key, $value);
        } finally {
            $this->recordOperationTime($key, microtime(true) - $startTime);
        }
    }

    public function queryMetrics(string $key, array $criteria = []): MetricCollection
    {
        return $this->cache->tags(['metrics', $key])->remember(
            $this->getQueryCacheKey($key, $criteria),
            fn() => $this->store->query($key, $criteria)
        );
    }

    public function getMetricStats(string $key, string $interval = '1h'): MetricStats
    {
        return $this->cache->tags(['metrics', $key])->remember(
            "stats.{$key}.{$interval}",
            fn() => $this->calculateStats($key, $interval)
        );
    }

    public function trackOperation(string $operation, callable $callback): mixed
    {
        $startTime = microtime(true);
        $success = true;
        
        try {
            $result = $callback();
            return $result;
        } catch (\Exception $e) {
            $success = false;
            throw $e;
        } finally {
            $this->recordOperationMetrics($operation, [
                'duration' => microtime(true) - $startTime,
                'success' => $success,
                'memory' => memory_get_peak_usage(true)
            ]);
        }
    }

    private function validateMetric(Metric $metric): void
    {
        if (!$this->isValidMetricName($metric->getKey())) {
            throw new MonitoringException('Invalid metric name');
        }

        if (!$this->isValidMetricValue($metric->getValue())) {
            throw new MonitoringException('Invalid metric value');
        }

        foreach ($metric->getTags() as $tag => $value) {
            if (!$this->isValidTag($tag, $value)) {
                throw new MonitoringException('Invalid metric tag');
            }
        }
    }

    private function checkThresholds(Metric $metric): void
    {
        $thresholds = $this->config['thresholds'][$metric->getKey()] ?? null;
        
        if (!$thresholds) {
            return;
        }

        foreach ($thresholds as $threshold) {
            if ($this->isThresholdViolated($metric, $threshold)) {
                $this->alerts->triggerAlert(
                    new ThresholdAlert($metric, $threshold)
                );
            }
        }
    }

    private function calculateStats(string $key, string $interval): MetricStats
    {
        $metrics = $this->store->queryInterval($key, $interval);
        
        return new MetricStats([
            'count' => $metrics->count(),
            'min' => $metrics->min(),
            'max' => $metrics->max(),
            'avg' => $metrics->average(),
            'p95' => $metrics->percentile(95),
            'p99' => $metrics->percentile(99)
        ]);
    }

    private function recordOperationMetrics(string $operation, array $metrics): void
    {
        foreach ($metrics as $key => $value) {
            $this->recordMetric(
                "{$operation}.{$key}",
                $value,
                ['operation' => $operation]
            );
        }
    }

    private function isThresholdViolated(Metric $metric, array $threshold): bool
    {
        $value = $metric->getValue();
        
        return match ($threshold['type']) {
            'min' => $value < $threshold['value'],
            'max' => $value > $threshold['value'],
            'equals' => $value === $threshold['value'],
            'change_rate' => $this->checkChangeRate($metric, $threshold),
            default => false
        };
    }

    private function checkChangeRate(Metric $metric, array $threshold): bool
    {
        $previous = $this->store->getLast($metric->getKey(), 2);
        
        if ($previous->count() < 2) {
            return false;
        }

        $rate = ($metric->getValue() - $previous[0]->getValue()) / 
                ($metric->getTimestamp() - $previous[0]->getTimestamp());
                
        return abs($rate) > $threshold['value'];
    }

    private function getCacheKey(Metric $metric): string
    {
        return implode('.', [
            $metric->getKey(),
            $metric->getTimestamp(),
            md5(serialize($metric->getTags()))
        ]);
    }

    private function getQueryCacheKey(string $key, array $criteria): string
    {
        return "query.{$key}." . md5(serialize($criteria));
    }

    private function isValidMetricName(string $name): bool
    {
        return preg_match('/^[a-zA-Z0-9._-]+$/', $name) === 1;
    }

    private function isValidMetricValue($value): bool
    {
        return is_numeric($value) || is_bool($value);
    }

    private function isValidTag(string $tag, $value): bool
    {
        return preg_match('/^[a-zA-Z0-9._-]+$/', $tag) === 1 &&
               (is_string($value) || is_numeric($value));
    }

    private function handleError(\Exception $e, string $key, $value): void
    {
        Log::error('Metrics recording failed', [
            'key' => $key,
            'value' => $value,
            'error' => $e->getMessage()
        ]);

        if ($e instanceof SecurityException) {
            throw $e;
        }

        throw new MonitoringException(
            'Failed to record metric: ' . $e->getMessage(),
            0,
            $e
        );
    }
}

class MetricStore
{
    private DB $database;
    
    public function record(Metric $metric): void
    {
        $this->database->table('metrics')->insert([
            'key' => $metric->getKey(),
            'value' => $metric->getValue(),
            'tags' => json_encode($metric->getTags()),
            'timestamp' => $metric->getTimestamp()
        ]);
    }

    public function query(string $key, array $criteria = []): MetricCollection
    {
        $query = $this->database->table('metrics')
            ->where('key', $key);

        foreach ($criteria as $field => $value) {
            $query->where($field, $value);
        }

        return new MetricCollection($query->get());
    }

    public function queryInterval(string $key, string $interval): MetricCollection
    {
        $start = strtotime("-{$interval}");
        
        return new MetricCollection(
            $this->database->table('metrics')
                ->where('key', $key)
                ->where('timestamp', '>=', $start)
                ->get()
        );
    }
}
