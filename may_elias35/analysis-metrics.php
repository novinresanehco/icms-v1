<?php

namespace App\Core\Audit\Metrics;

class MetricsCollector
{
    private MetricsStorage $storage;
    private array $collectors;
    private array $metrics = [];

    public function __construct(MetricsStorage $storage, array $collectors = [])
    {
        $this->storage = $storage;
        $this->collectors = $collectors;
    }

    public function record(string $name, $value, array $tags = []): void
    {
        $metric = new Metric($name, $value, $tags, time());
        $this->metrics[] = $metric;
        $this->storage->store($metric);
    }

    public function increment(string $name, int $amount = 1, array $tags = []): void
    {
        $currentValue = $this->getCurrentValue($name, $tags) ?? 0;
        $this->record($name, $currentValue + $amount, $tags);
    }

    public function gauge(string $name, $value, array $tags = []): void
    {
        $this->record($name, $value, $tags);
    }

    public function timing(string $name, float $timeInMs, array $tags = []): void
    {
        $this->record($name, $timeInMs, array_merge($tags, ['type' => 'timing']));
    }

    public function histogram(string $name, $value, array $tags = []): void
    {
        $this->record($name, $value, array_merge($tags, ['type' => 'histogram']));
    }

    public function collect(): array
    {
        $metrics = [];
        foreach ($this->collectors as $collector) {
            $metrics = array_merge($metrics, $collector->collect());
        }
        return $metrics;
    }

    private function getCurrentValue(string $name, array $tags): ?float
    {
        return $this->storage->get($name, $tags);
    }
}

class MetricsStorage
{
    private ConnectionInterface $connection;
    private string $prefix;
    private int $ttl;

    public function __construct(ConnectionInterface $connection, string $prefix = 'metrics:', int $ttl = 86400)
    {
        $this->connection = $connection;
        $this->prefix = $prefix;
        $this->ttl = $ttl;
    }

    public function store(Metric $metric): void
    {
        $key = $this->generateKey($metric->getName(), $metric->getTags());
        $this->connection->store($key, $metric, $this->ttl);
    }

    public function get(string $name, array $tags = []): ?float
    {
        $key = $this->generateKey($name, $tags);
        $metric = $this->connection->get($key);
        return $metric ? $metric->getValue() : null;
    }

    public function getRange(string $name, array $tags = [], int $start = null, int $end = null): array
    {
        $key = $this->generateKey($name, $tags);
        return $this->connection->getRange($key, $start, $end);
    }

    private function generateKey(string $name, array $tags): string
    {
        $tagString = empty($tags) ? '' : ':' . http_build_query($tags);
        return $this->prefix . $name . $tagString;
    }
}

class Metric
{
    private string $name;
    private $value;
    private array $tags;
    private int $timestamp;

    public function __construct(string $name, $value, array $tags = [], int $timestamp = null)
    {
        $this->name = $name;
        $this->value = $value;
        $this->tags = $tags;
        $this->timestamp = $timestamp ?? time();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue()
    {
        return $this->value;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'value' => $this->value,
            'tags' => $this->tags,
            'timestamp' => $this->timestamp
        ];
    }
}

class PerformanceMetricsCollector
{
    private MetricsCollector $metrics;
    private array $timings = [];

    public function startTiming(string $name, array $tags = []): void
    {
        $this->timings[$this->getTimingKey($name, $tags)] = microtime(true);
    }

    public function endTiming(string $name, array $tags = []): float
    {
        $key = $this->getTimingKey($name, $tags);
        $start = $this->timings[$key] ?? null;
        
        if (!$start) {
            throw new \RuntimeException("No timing started for: {$name}");
        }

        $duration = (microtime(true) - $start) * 1000;
        $this->metrics->timing($name, $duration, $tags);
        
        unset($this->timings[$key]);
        
        return $duration;
    }

    public function recordMemoryUsage(string $name, array $tags = []): void
    {
        $this->metrics->gauge(
            $name, 
            memory_get_usage(true),
            array_merge($tags, ['type' => 'memory'])
        );
    }

    public function recordCpuUsage(string $name, array $tags = []): void
    {
        $usage = sys_getloadavg()[0];
        $this->metrics->gauge(
            $name,
            $usage,
            array_merge($tags, ['type' => 'cpu'])
        );
    }

    private function getTimingKey(string $name, array $tags): string
    {
        return $name . ':' . serialize($tags);
    }
}

class MetricsAggregator
{
    private MetricsStorage $storage;
    private array $aggregations = [];

    public function __construct(MetricsStorage $storage)
    {
        $this->storage = $storage;
    }

    public function aggregate(string $name, string $aggregationType, array $tags = [], int $interval = 60): void
    {
        $metrics = $this->storage->getRange(
            $name,
            $tags,
            time() - $interval,
            time()
        );

        $value = match($aggregationType) {
            'sum' => $this->sum($metrics),
            'avg' => $this->average($metrics),
            'min' => $this->minimum($metrics),
            'max' => $this->maximum($metrics),
            'count' => count($metrics),
            default => throw new \InvalidArgumentException("Unknown aggregation type: {$aggregationType}")
        };

        $this->aggregations[] = new Metric(
            "{$name}.{$aggregationType}",
            $value,
            array_merge($tags, ['interval' => $interval]),
            time()
        );
    }

    private function sum(array $metrics): float
    {
        return array_sum(array_column($metrics, 'value'));
    }

    private function average(array $metrics): float
    {
        $values = array_column($metrics, 'value');
        return count($values) > 0 ? array_sum($values) / count($values) : 0;
    }

    private function minimum(array $metrics): float
    {
        $values = array_column($metrics, 'value');
        return count($values) > 0 ? min($values) : 0;
    }

    private function maximum(array $metrics): float
    {
        $values = array_column($metrics, 'value');
        return count($values) > 0 ? max($values) : 0;
    }

    public function getAggregations(): array
    {
        return $this->aggregations;
    }
}
