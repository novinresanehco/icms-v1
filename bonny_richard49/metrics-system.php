// app/Core/Metrics/MetricsCollector.php
<?php

namespace App\Core\Metrics;

use Illuminate\Support\Facades\Redis;

class MetricsCollector
{
    private string $prefix;
    private array $metrics = [];

    public function __construct(string $prefix = 'app_metrics')
    {
        $this->prefix = $prefix;
    }

    public function increment(string $metric, int $value = 1): void
    {
        Redis::hincrby($this->getKey($metric), 'value', $value);
        Redis::hincrby($this->getKey($metric), 'count', 1);
    }

    public function gauge(string $metric, float $value): void
    {
        Redis::hset($this->getKey($metric), 'value', $value);
        Redis::hset($this->getKey($metric), 'timestamp', time());
    }

    public function timing(string $metric, float $value): void
    {
        Redis::hset($this->getKey($metric), 'value', $value);
        Redis::hset($this->getKey($metric), 'timestamp', time());
        Redis::hincrby($this->getKey($metric), 'count', 1);
    }

    public function histogram(string $metric, float $value): void
    {
        $bucket = $this->getBucket($value);
        Redis::hincrby($this->getKey($metric), $bucket, 1);
    }

    private function getKey(string $metric): string
    {
        return "{$this->prefix}:{$metric}";
    }

    private function getBucket(float $value): string
    {
        $buckets = [0.01, 0.05, 0.1, 0.5, 1, 5, 10, 50, 100];
        foreach ($buckets as $bucket) {
            if ($value <= $bucket) {
                return "le_$bucket";
            }
        }
        return 'gt_100';
    }
}

// app/Core/Metrics/MetricsAggregator.php
<?php

namespace App\Core\Metrics;

use Illuminate\Support\Facades\Redis;

class MetricsAggregator
{
    private MetricsCollector $collector;
    private string $prefix;

    public function __construct(MetricsCollector $collector, string $prefix = 'app_metrics')
    {
        $this->collector = $collector;
        $this->prefix = $prefix;
    }

    public function aggregate(string $metric, string $interval = '1h'): array
    {
        $key = "{$this->prefix}:{$metric}";
        $data = Redis::hgetall($key);

        return [
            'metric' => $metric,
            'interval' => $interval,
            'value' => $data['value'] ?? 0,
            'count' => $data['count'] ?? 0,
            'timestamp' => $data['timestamp'] ?? time()
        ];
    }

    public function aggregateAll(array $metrics, string $interval = '1h'): array
    {
        $results = [];
        foreach ($metrics as $metric) {
            $results[$metric] = $this->aggregate($metric, $interval);
        }
        return $results;
    }
}

// app/Core/Metrics/MetricsExporter.php
<?php

namespace App\Core\Metrics;

class MetricsExporter
{
    private MetricsAggregator $aggregator;

    public function __construct(MetricsAggregator $aggregator)
    {
        $this->aggregator = $aggregator;
    }

    public function exportPrometheus(array $metrics): string
    {
        $output = '';
        $data = $this->aggregator->aggregateAll($metrics);

        foreach ($data as $metric => $info) {
            $output .= "# HELP {$metric} {$metric} metric\n";
            $output .= "# TYPE {$metric} gauge\n";
            $output .= "{$metric} {$info['value']} {$info['timestamp']}\n";
        }

        return $output;
    }

    public function exportJSON(array $metrics): string
    {
        return json_encode(
            $this->aggregator->aggregateAll($metrics),
            JSON_PRETTY_PRINT
        );
    }
}

// app/Core/Metrics/Middleware/MetricsMiddleware.php
<?php

namespace App\Core\Metrics\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Core\Metrics\MetricsCollector;

class MetricsMiddleware
{
    private MetricsCollector $metrics;

    public function __construct(MetricsCollector $metrics)
    {
        $this->metrics = $metrics;
    }

    public function handle(Request $request, Closure $next)
    {
        $startTime = microtime(true);

        $response = $next($request);

        $duration = microtime(true) - $startTime;

        $this->recordMetrics($request, $response, $duration);

        return $response;
    }

    private function recordMetrics(Request $request, $response, float $duration): void
    {
        $path = str_replace('/', '_', trim($request->path(), '/')) ?: 'root';

        $this->metrics->increment("http_requests_total");
        $this->metrics->increment("http_requests_by_path.$path");
        $this->metrics->increment("http_requests_by_method.{$request->method()}");
        $this->metrics->increment("http_status.{$response->status()}");
        
        $this->metrics->timing("http_request_duration_seconds", $duration);
        $this->metrics->histogram("http_request_size_bytes", strlen($response->content()));
    }
}