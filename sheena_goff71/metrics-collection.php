<?php

namespace App\Core\Metrics;

use App\Core\Metrics\Contracts\MetricsCollectorInterface;
use App\Core\Metrics\DTOs\Metric;
use App\Core\Metrics\Exceptions\MetricsException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MetricsCollector implements MetricsCollectorInterface
{
    private array $collectors = [];
    private array $aggregators = [];
    private int $flushInterval;

    public function __construct(array $config = [])
    {
        $this->flushInterval = $config['flush_interval'] ?? 60;
    }

    public function collect(string $metric, $value, array $tags = []): void
    {
        try {
            $metricObject = new Metric($metric, $value, $tags);
            $this->storeMetric($metricObject);
        } catch (\Exception $e) {
            Log::error('Failed to collect metric', [
                'metric' => $metric,
                'error' => $e->getMessage()
            ]);
            throw new MetricsException("Failed to collect metric: {$e->getMessage()}", 0, $e);
        }
    }

    public function registerCollector(string $type, callable $collector): void
    {
        $this->collectors[$type] = $collector;
    }

    public function registerAggregator(string $type, callable $aggregator): void
    {
        $this->aggregators[$type] = $aggregator;
    }

    protected function storeMetric(Metric $metric): void
    {
        $key = $this->getStorageKey($metric);
        
        Cache::remember($key, $this->flushInterval, function () {
            return [];
        });

        $metrics = Cache::get($key, []);
        $metrics[] = $metric;
        
        Cache::put($key, $metrics, $this->flushInterval);

        if (count($metrics) >= 100) {
            $this->flush($key);
        }
    }

    protected function getStorageKey(Metric $metric): string
    {
        return sprintf(
            'metrics:%s:%s',
            $metric->name,
            date('Y-m-d-H-i')
        );
    }

    protected function flush(string $key): void
    {
        $metrics = Cache::get($key, []);
        
        if (empty($metrics)) {
            return;
        }

        try {
            foreach ($this->aggregators as $aggregator) {
                $aggregator($metrics);
            }

            Cache::forget($key);
        } catch (\Exception $e) {
            Log::error('Failed to flush metrics', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
        }
    }
}

namespace App\Core\Metrics\DTOs;

class Metric
{
    public function __construct(
        public readonly string $name,
        public readonly mixed $value,
        public readonly array $tags = [],
        public readonly int $timestamp = null
    ) {
        $this->timestamp = $timestamp ?? time();
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

    public static function fromArray(array $data): self
    {
        return new self(
            $data['name'],
            $data['value'],
            $data['tags'] ?? [],
            $data['timestamp'] ?? null
        );
    }
}

namespace App\Core\Metrics\Contracts;

interface MetricsCollectorInterface
{
    public function collect(string $metric, $value, array $tags = []): void;
    public function registerCollector(string $type, callable $collector): void;
    public function registerAggregator(string $type, callable $aggregator): void;
}

namespace App\Core\Metrics\Providers;

use App\Core\Metrics\Contracts\MetricsCollectorInterface;
use App\Core\Metrics\MetricsCollector;
use Illuminate\Support\ServiceProvider;

class MetricsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MetricsCollectorInterface::class, function ($app) {
            return new MetricsCollector(config('metrics', []));
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/metrics.php' => config_path('metrics.php'),
        ], 'metrics-config');
    }
}

namespace App\Core\Metrics\Aggregators;

use App\Core\Metrics\DTOs\Metric;
use Illuminate\Support\Facades\DB;

class DatabaseAggregator
{
    public function __invoke(array $metrics): void
    {
        $records = [];
        
        foreach ($metrics as $metric) {
            $records[] = [
                '