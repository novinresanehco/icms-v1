namespace App\Core\Services;

use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class MetricsCollector
{
    private CacheManager $cache;
    private array $config;
    private string $prefix;
    private array $buffer = [];
    private int $lastFlush;

    public function __construct(CacheManager $cache, array $config)
    {
        $this->cache = $cache;
        $this->config = $config;
        $this->prefix = $config['metrics_prefix'];
        $this->lastFlush = time();
    }

    public function recordPerformance(string $metric, float $value): void
    {
        $key = "{$this->prefix}:performance:{$metric}";
        $timestamp = time();

        try {
            Redis::pipeline(function($pipe) use ($key, $value, $timestamp) {
                $pipe->zadd("{$key}:values", $timestamp, "{$timestamp}:{$value}");
                $pipe->zadd("{$key}:raw", $timestamp, $value);
                $pipe->hincrby("{$key}:stats", 'count', 1);
                $pipe->hincrbyfloat("{$key}:stats", 'sum', $value);
                
                if ($this->shouldUpdateMinMax($key, $value)) {
                    $pipe->hset("{$key}:stats", 'min', $value);
                    $pipe->hset("{$key}:stats", 'max', $value);
                }
            });

            $this->checkThresholds($metric, $value);
            $this->bufferMetric('performance', $metric, $value);

        } catch (\Throwable $e) {
            $this->handleMetricError('performance', $metric, $e);
        }
    }

    public function recordSecurityEvent(string $type, string $severity): void
    {
        $timestamp = time();
        $key = "{$this->prefix}:security:{$type}";

        try {
            Redis::pipeline(function($pipe) use ($key, $severity, $timestamp) {
                $pipe->hincrby("{$key}:count", $severity, 1);
                $pipe->zadd("{$key}:timeline", $timestamp, "{$timestamp}:{$severity}");
                $pipe->hincrby("{$key}:daily:" . date('Y-m-d'), $severity, 1);
            });

            if ($this->isHighSeverity($severity)) {
                $this->triggerSecurityAlert($type, $severity);
            }

            $this->bufferMetric('security', $type, ['severity' => $severity]);

        } catch (\Throwable $e) {
            $this->handleMetricError('security', $type, $e);
        }
    }

    public function recordCacheOperation(string $operation, float $duration): void
    {
        $key = "{$this->prefix}:cache:{$operation}";
        $timestamp = time();

        try {
            Redis::pipeline(function($pipe) use ($key, $duration, $timestamp) {
                $pipe->hincrby("{$key}:stats", 'count', 1);
                $pipe->hincrbyfloat("{$key}:stats", 'duration_sum', $duration);
                $pipe->zadd("{$key}:durations", $timestamp, "{$timestamp}:{$duration}");
            });

            $this->checkCachePerformance($operation, $duration);
            $this->bufferMetric('cache', $operation, $duration);

        } catch (\Throwable $e) {
            $this->handleMetricError('cache', $operation, $e);
        }
    }

    public function incrementOperation(string $type): void
    {
        $key = "{$this->prefix}:operations:{$type}";
        $date = date('Y-m-d');

        try {
            Redis::pipeline(function($pipe) use ($key, $date) {
                $pipe->hincrby("{$key}:count", 'total', 1);
                $pipe->hincrby("{$key}:daily:{$date}", 'count', 1);
            });

            $this->bufferMetric('operations', $type, 1);

        } catch (\Throwable $e) {
            $this->handleMetricError('operations', $type, $e);
        }
    }

    public function markSuccess(string $trackingId): void
    {
        try {
            Redis::pipeline(function($pipe) use ($trackingId) {
                $pipe->hincrby("{$this->prefix}:success", 'total', 1);
                $pipe->hincrby("{$this->prefix}:success:daily:" . date('Y-m-d'), 'count', 1);
                $pipe->set("{$this->prefix}:tracking:{$trackingId}", 'success');
            });
        } catch (\Throwable $e) {
            $this->handleMetricError('success', $trackingId, $e);
        }
    }

    public function markFailure(string $trackingId, int $errorCode): void
    {
        try {
            Redis::pipeline(function($pipe) use ($trackingId, $errorCode) {
                $pipe->hincrby("{$this->prefix}:failures", 'total', 1);
                $pipe->hincrby("{$this->prefix}:failures:codes", $errorCode, 1);
                $pipe->set("{$this->prefix}:tracking:{$trackingId}", "failure:{$errorCode}");
            });

            $this->checkFailureRate();
        } catch (\Throwable $e) {
            $this->handleMetricError('failure', $trackingId, $e);
        }
    }

    public function getMetrics(string $type, string $metric, int $duration = 3600): array
    {
        $key = "{$this->prefix}:{$type}:{$metric}";
        $now = time();
        $start = $now - $duration;

        try {
            return [
                'values' => Redis::zrangebyscore("{$key}:values", $start, $now),
                'stats' => Redis::hgetall("{$key}:stats"),
                'timeline' => Redis::zrangebyscore("{$key}:timeline", $start, $now)
            ];
        } catch (\Throwable $e) {
            $this->handleMetricError('get', "{$type}:{$metric}", $e);
            return [];
        }
    }

    protected function bufferMetric(string $type, string $metric, mixed $value): void
    {
        $this->buffer[] = [
            'type' => $type,
            'metric' => $metric,
            'value' => $value,
            'timestamp' => microtime(true)
        ];

        if ($this->shouldFlushBuffer()) {
            $this->flushBuffer();
        }
    }

    protected function shouldFlushBuffer(): bool
    {
        return count($this->buffer) >= $this->config['buffer_size'] ||
               (time() - $this->lastFlush) >= $this->config['buffer_ttl'];
    }

    protected function flushBuffer(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        try {
            $this->cache->put(
                "metrics:buffer:" . uniqid(),
                $this->buffer,
                300
            );

            $this->buffer = [];
            $this->lastFlush = time();
        } catch (\Throwable $e) {
            Log::error('Failed to flush metrics buffer', [
                'error' => $e->getMessage(),
                'buffer_size' => count($this->buffer)
            ]);
        }
    }

    protected function shouldUpdateMinMax(string $key, float $value): bool
    {
        $stats = Redis::hgetall("{$key}:stats");
        return !isset($stats['min'], $stats['max']) ||
               $value < floatval($stats['min']) ||
               $value > floatval($stats['max']);
    }

    protected function isHighSeverity(string $severity): bool
    {
        return in_array($severity, ['critical', 'alert', 'emergency']);
    }

    protected function handleMetricError(string $operation, string $metric, \Throwable $e): void
    {
        Log::error('Metrics operation failed', [
            'operation' => $operation,
            'metric' => $metric,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    protected function checkThresholds(string $metric, float $value): void
    {
        $threshold = $this->config['thresholds'][$metric] ?? null;
        if ($threshold && $value > $threshold) {
            $this->triggerThresholdAlert($metric, $value, $threshold);
        }
    }

    protected function checkFailureRate(): void
    {
        $total = Redis::hget("{$this->prefix}:failures", 'total') ?? 0;
        $threshold = $this->config['failure_threshold'];
        
        if ($total > $threshold) {
            $this->triggerFailureAlert($total);
        }
    }

    protected function checkCachePerformance(string $operation, float $duration): void
    {
        $threshold = $this->config['cache_thresholds'][$operation] ?? null;
        if ($threshold && $duration > $threshold) {
            $this->triggerCacheAlert($operation, $duration);
        }
    }
}
