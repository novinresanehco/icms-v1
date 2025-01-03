<?php

namespace App\Core\Cache;

use App\Core\Interfaces\CacheManagerInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Psr\Log\LoggerInterface;

class CacheService implements CacheManagerInterface
{
    private LoggerInterface $logger;
    private array $config;
    private array $metrics;

    private const DEFAULT_TTL = 3600;
    private const LOCK_TIMEOUT = 5;
    private const RETRY_ATTEMPTS = 3;
    private const METRICS_KEY = 'cache:metrics';

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->config = config('cache');
        $this->metrics = [];
    }

    public function get(string $key, $default = null)
    {
        try {
            $startTime = microtime(true);
            $value = Cache::get($key, $default);
            $this->recordMetrics('get', $key, microtime(true) - $startTime, !is_null($value));
            return $value;
        } catch (\Exception $e) {
            $this->handleCacheError('get', $e, $key);
            return $default;
        }
    }

    public function put(string $key, $value, ?int $ttl = null): bool
    {
        try {
            $ttl = $ttl ?? self::DEFAULT_TTL;
            $startTime = microtime(true);
            $result = Cache::put($key, $value, $ttl);
            $this->recordMetrics('put', $key, microtime(true) - $startTime, true);
            return $result;
        } catch (\Exception $e) {
            $this->handleCacheError('put', $e, $key);
            return false;
        }
    }

    public function remember(string $key, ?int $ttl, callable $callback)
    {
        return Cache::remember($key, $ttl ?? self::DEFAULT_TTL, function() use ($callback, $key) {
            try {
                $startTime = microtime(true);
                $value = $callback();
                $this->recordMetrics('remember', $key, microtime(true) - $startTime, true);
                return $value;
            } catch (\Exception $e) {
                $this->handleCacheError('remember', $e, $key);
                throw $e;
            }
        });
    }

    public function forget(string $key): bool
    {
        try {
            $startTime = microtime(true);
            $result = Cache::forget($key);
            $this->recordMetrics('forget', $key, microtime(true) - $startTime, true);
            return $result;
        } catch (\Exception $e) {
            $this->handleCacheError('forget', $e, $key);
            return false;
        }
    }

    public function flush(): bool
    {
        try {
            $startTime = microtime(true);
            $result = Cache::flush();
            $this->recordMetrics('flush', 'all', microtime(true) - $startTime, true);
            return $result;
        } catch (\Exception $e) {
            $this->handleCacheError('flush', $e);
            return false;
        }
    }

    public function tags(array $tags): CacheManagerInterface
    {
        try {
            return Cache::tags($tags);
        } catch (\Exception $e) {
            $this->handleCacheError('tags', $e);
            return $this;
        }
    }

    public function lock(string $key, int $timeout = null): bool
    {
        try {
            return Redis::set(
                $this->getLockKey($key),
                1,
                'EX',
                $timeout ?? self::LOCK_TIMEOUT,
                'NX'
            );
        } catch (\Exception $e) {
            $this->handleCacheError('lock', $e, $key);
            return false;
        }
    }

    public function unlock(string $key): bool
    {
        try {
            return Redis::del($this->getLockKey($key)) > 0;
        } catch (\Exception $e) {
            $this->handleCacheError('unlock', $e, $key);
            return false;
        }
    }

    public function getHitRate(): float
    {
        $metrics = $this->getMetrics();
        $hits = $metrics['hits'] ?? 0;
        $total = $hits + ($metrics['misses'] ?? 0);
        return $total > 0 ? ($hits / $total) * 100 : 0;
    }

    public function getMemoryUsage(): array
    {
        try {
            $info = Redis::info('memory');
            return [
                'used' => $info['used_memory'],
                'peak' => $info['used_memory_peak'],
                'fragmentation' => $info['mem_fragmentation_ratio']
            ];
        } catch (\Exception $e) {
            $this->handleCacheError('memory_usage', $e);
            return ['used' => 0, 'peak' => 0, 'fragmentation' => 0];
        }
    }

    public function getKeysCount(): int
    {
        try {
            return Redis::dbsize();
        } catch (\Exception $e) {
            $this->handleCacheError('keys_count', $e);
            return 0;
        }
    }

    protected function getMetrics(): array
    {
        try {
            $metrics = Redis::get(self::METRICS_KEY);
            return $metrics ? json_decode($metrics, true) : [];
        } catch (\Exception $e) {
            $this->handleCacheError('get_metrics', $e);
            return [];
        }
    }

    protected function saveMetrics(array $metrics): void
    {
        try {
            Redis::set(self::METRICS_KEY, json_encode($metrics));
        } catch (\Exception $e) {
            $this->handleCacheError('save_metrics', $e);
        }
    }

    protected function recordMetrics(string $operation, string $key, float $duration, bool $success): void
    {
        $metrics = $this->getMetrics();

        $metrics['operations'][$operation] = ($metrics['operations'][$operation] ?? 0) + 1;
        $metrics['duration'][$operation] = ($metrics['duration'][$operation] ?? 0) + $duration;

        if ($operation === 'get') {
            if ($success) {
                $metrics['hits'] = ($metrics['hits'] ?? 0) + 1;
            } else {
                $metrics['misses'] = ($metrics['misses'] ?? 0) + 1;
            }
        }

        $this->saveMetrics($metrics);
    }

    protected function getLockKey(string $key): string
    {
        return "lock:{$key}";
    }

    protected function handleCacheError(string $operation, \Exception $e, string $key = null): void
    {
        $context = [
            'operation' => $operation,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ];

        if ($key) {
            $context['key'] = $key;
        }

        $this->logger->error('Cache operation failed', $context);

        if ($this->isRetryableError($e)) {
            $this->handleRetry($operation, $key, $e);
        }
    }

    protected function handleRetry(string $operation, ?string $key, \Exception $e): void
    {
        $attempts = 0;
        while ($attempts < self::RETRY_ATTEMPTS) {
            try {
                $attempts++;
                usleep(100 * $attempts); // Exponential backoff
                
                if ($key) {
                    $this->get($key);
                }
                
                break;
            } catch (\Exception $retryException) {
                if ($attempts === self::RETRY_ATTEMPTS) {
                    $this->logger->critical('Cache retry exhausted', [
                        'operation' => $operation,
                        'key' => $key,
                        'attempts' => $attempts,
                        'original_error' => $e->getMessage(),
                        'final_error' => $retryException->getMessage()
                    ]);
                }
            }
        }
    }

    protected function isRetryableError(\Exception $e): bool
    {
        return !($e instanceof \InvalidArgumentException) &&
               !($e instanceof \LogicException);
    }
}
