<?php

namespace App\Core\Cache;

use Illuminate\Support\Facades\Cache;
use App\Core\Security\SecurityManager;
use App\Core\Interfaces\CacheInterface;

class CacheManager implements CacheInterface 
{
    private SecurityManager $security;
    private AuditLogger $auditLogger;
    private MetricsCollector $metrics;
    private array $config;

    private const MAX_KEY_LENGTH = 250;
    private const MAX_LOCK_WAIT = 5;

    public function __construct(
        SecurityManager $security,
        AuditLogger $auditLogger,
        MetricsCollector $metrics,
        array $config
    ) {
        $this->security = $security;
        $this->auditLogger = $auditLogger;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function remember(string $key, $ttl, callable $callback)
    {
        $startTime = microtime(true);
        $this->validateKey($key);

        try {
            return Cache::tags($this->getTags($key))
                ->remember($this->normalizeKey($key), $ttl, function() use ($callback, $key) {
                    $value = $callback();
                    $this->validateValue($value);
                    return $value;
                });
        } finally {
            $this->recordMetrics('remember', $startTime);
        }
    }

    public function rememberForever(string $key, callable $callback)
    {
        $startTime = microtime(true);
        $this->validateKey($key);

        try {
            return Cache::tags($this->getTags($key))
                ->rememberForever($this->normalizeKey($key), function() use ($callback, $key) {
                    $value = $callback();
                    $this->validateValue($value);
                    return $value;
                });
        } finally {
            $this->recordMetrics('rememberForever', $startTime);
        }
    }

    public function get(string $key, $default = null)
    {
        $startTime = microtime(true);
        $this->validateKey($key);

        try {
            return Cache::tags($this->getTags($key))
                ->get($this->normalizeKey($key), $default);
        } finally {
            $this->recordMetrics('get', $startTime);
        }
    }

    public function put(string $key, $value, $ttl = null): bool
    {
        $startTime = microtime(true);
        $this->validateKey($key);
        $this->validateValue($value);

        try {
            return Cache::tags($this->getTags($key))
                ->put($this->normalizeKey($key), $value, $ttl);
        } finally {
            $this->recordMetrics('put', $startTime);
        }
    }

    public function putMany(array $values, $ttl = null): bool
    {
        $startTime = microtime(true);
        
        foreach ($values as $key => $value) {
            $this->validateKey($key);
            $this->validateValue($value);
        }

        try {
            $normalized = [];
            foreach ($values as $key => $value) {
                $normalized[$this->normalizeKey($key)] = $value;
            }
            
            return Cache::putMany($normalized, $ttl);
        } finally {
            $this->recordMetrics('putMany', $startTime);
        }
    }

    public function add(string $key, $value, $ttl = null): bool
    {
        $startTime = microtime(true);
        $this->validateKey($key);
        $this->validateValue($value);

        try {
            return Cache::tags($this->getTags($key))
                ->add($this->normalizeKey($key), $value, $ttl);
        } finally {
            $this->recordMetrics('add', $startTime);
        }
    }

    public function forever(string $key, $value): bool
    {
        $startTime = microtime(true);
        $this->validateKey($key);
        $this->validateValue($value);

        try {
            return Cache::tags($this->getTags($key))
                ->forever($this->normalizeKey($key), $value);
        } finally {
            $this->recordMetrics('forever', $startTime);
        }
    }

    public function forget(string $key): bool
    {
        $startTime = microtime(true);
        $this->validateKey($key);

        try {
            return Cache::tags($this->getTags($key))
                ->forget($this->normalizeKey($key));
        } finally {
            $this->recordMetrics('forget', $startTime);
        }
    }

    public function flush(array $tags = []): bool
    {
        $startTime = microtime(true);

        try {
            if (empty($tags)) {
                return Cache::flush();
            }
            return Cache::tags($tags)->flush();
        } finally {
            $this->recordMetrics('flush', $startTime);
        }
    }

    public function lock(string $key, int $seconds = 0)
    {
        $startTime = microtime(true);
        $this->validateKey($key);

        try {
            return Cache::lock($this->normalizeKey($key), $seconds);
        } finally {
            $this->recordMetrics('lock', $startTime);
        }
    }

    public function restoreLock(string $key): bool
    {
        $startTime = microtime(true);
        $this->validateKey($key);

        try {
            return Cache::restoreLock($this->normalizeKey($key));
        } finally {
            $this->recordMetrics('restoreLock', $startTime);
        }
    }

    protected function validateKey(string $key): void
    {
        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw new CacheException('Cache key exceeds maximum length');
        }

        if (!preg_match('/^[a-zA-Z0-9:._\-]+$/', $key)) {
            throw new CacheException('Invalid cache key format');
        }
    }

    protected function validateValue($value): void
    {
        if (!is_scalar($value) && !is_array($value) && !is_null($value)) {
            throw new CacheException('Invalid cache value type');
        }

        if (is_array($value)) {
            array_walk_recursive($value, function($item) {
                if (!is_scalar($item) && !is_null($item)) {
                    throw new CacheException('Invalid cache value type in array');
                }
            });
        }
    }

    protected function normalizeKey(string $key): string
    {
        return str_replace(['{', '}', '(', ')', '/', '\\', '@'], '_', $key);
    }

    protected function getTags(string $key): array
    {
        $parts = explode(':', $key);
        return array_slice($parts, 0, 2);
    }

    protected function recordMetrics(string $operation, float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        
        $this->metrics->record("cache.{$operation}.time", $duration);
        $this->metrics->increment("cache.{$operation}.count");
        
        if ($duration > ($this->config['slow_threshold'] ?? 0.1)) {
            $this->auditLogger->warning('slow_cache_operation', [
                'operation' => $operation,
                'duration' => $duration
            ]);
        }
    }
}
