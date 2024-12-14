<?php

namespace App\Core\Services;

use App\Core\Interfaces\CacheServiceInterface;
use Psr\Log\LoggerInterface;
use Illuminate\Support\Facades\Cache;
use App\Core\Exceptions\CacheException;

class CacheService implements CacheServiceInterface
{
    private LoggerInterface $logger;
    private array $config;
    private array $metrics = [];

    private const DEFAULT_TTL = 3600;
    private const CACHE_VERSION = 'v1';
    private const MAX_KEY_LENGTH = 250;
    private const LOCK_TIMEOUT = 10;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->config = config('cache');
    }

    public function get(string $key, $default = null)
    {
        try {
            $cacheKey = $this->formatKey($key);
            $value = Cache::get($cacheKey);

            $this->trackMetric('cache.get', $value !== null);

            return $value ?? $default;
        } catch (\Exception $e) {
            $this->handleError('Cache get failed', $e);
            return $default;
        }
    }

    public function put(string $key, $value, ?int $ttl = null): bool
    {
        try {
            $cacheKey = $this->formatKey($key);
            $ttl = $ttl ?? self::DEFAULT_TTL;

            $result = Cache::put($cacheKey, $value, $ttl);
            
            $this->trackMetric('cache.put', true);
            
            return $result;
        } catch (\Exception $e) {
            $this->handleError('Cache put failed', $e);
            return false;
        }
    }

    public function remember(string $key, callable $callback, ?int $ttl = null)
    {
        try {
            $cacheKey = $this->formatKey($key);
            $ttl = $ttl ?? self::DEFAULT_TTL;

            return Cache::remember($cacheKey, $ttl, function() use ($callback, $key) {
                try {
                    return $callback();
                } catch (\Exception $e) {
                    $this->logger->error('Cache callback failed', [
                        'key' => $key,
                        'error' => $e->getMessage()
                    ]);
                    throw $e;
                }
            });
        } catch (\Exception $e) {
            $this->handleError('Cache remember failed', $e);
            return $callback();
        }
    }

    public function rememberForever(string $key, callable $callback)
    {
        try {
            $cacheKey = $this->formatKey($key);

            return Cache::rememberForever($cacheKey, function() use ($callback, $key) {
                try {
                    return $callback();
                } catch (\Exception $e) {
                    $this->logger->error('Cache callback failed', [
                        'key' => $key,
                        'error' => $e->getMessage()
                    ]);
                    throw $e;
                }
            });
        } catch (\Exception $e) {
            $this->handleError('Cache rememberForever failed', $e);
            return $callback();
        }
    }

    public function forget(string $key): bool
    {
        try {
            $cacheKey = $this->formatKey($key);
            return Cache::forget($cacheKey);
        } catch (\Exception $e) {
            $this->handleError('Cache forget failed', $e);
            return false;
        }
    }

    public function flush(): bool
    {
        try {
            return Cache::flush();
        } catch (\Exception $e) {
            $this->handleError('Cache flush failed', $e);
            return false;
        }
    }

    public function tags(array $tags): CacheServiceInterface
    {
        return new TaggedCache($this, $tags);
    }

    public function lock(string $key, int $timeout = self::LOCK_TIMEOUT)
    {
        try {
            $lockKey = $this->formatKey("lock:$key");
            return Cache::lock($lockKey, $timeout);
        } catch (\Exception $e) {
            $this->handleError('Cache lock failed', $e);
            return null;
        }
    }

    private function formatKey(string $key): string
    {
        if (strlen($key) > self::MAX_KEY_LENGTH) {
            $key = substr($key, 0, self::MAX_KEY_LENGTH - 32) . '_' . md5($key);
        }

        return implode(':', [
            self::CACHE_VERSION,
            $this->config['prefix'] ?? 'app',
            $key
        ]);
    }

    private function trackMetric(string $operation, bool $success): void
    {
        $metric = [
            'operation' => $operation,
            'success' => $success,
            'timestamp' => microtime(true)
        ];

        $this->metrics[] = $metric;

        if (count($this->metrics) >= 100) {
            $this->flushMetrics();
        }
    }

    private function flushMetrics(): void
    {
        try {
            // Implementation depends on monitoring system
            $this->metrics = [];
        } catch (\Exception $e) {
            $this->logger->error('Failed to flush cache metrics', [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function handleError(string $message, \Exception $e): void
    {
        $this->logger->error($message, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        throw new CacheException($message, 0, $e);
    }
}
