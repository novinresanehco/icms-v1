<?php

namespace App\Core\Cache;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Core\Interfaces\CacheManagerInterface;
use App\Core\Security\SecurityContext;
use App\Core\Monitoring\PerformanceMonitor;

class CacheManager implements CacheManagerInterface
{
    private SecurityContext $security;
    private PerformanceMonitor $monitor;
    private array $config;
    
    private const CRITICAL_KEYS = [
        'content',
        'user',
        'permissions',
        'settings'
    ];

    public function __construct(
        SecurityContext $security,
        PerformanceMonitor $monitor,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->config = $config;
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $this->validateKey($key);
        $cacheKey = $this->buildKey($key);
        $startTime = microtime(true);

        try {
            if ($this->has($cacheKey)) {
                $this->monitor->recordHit($key);
                return $this->get($cacheKey);
            }

            $value = $callback();
            $this->set($cacheKey, $value, $ttl);
            $this->monitor->recordMiss($key);
            
            return $value;

        } catch (\Exception $e) {
            $this->monitor->recordError($key, $e);
            Log::error('Cache operation failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            throw $e;
        } finally {
            $this->monitor->recordLatency($key, microtime(true) - $startTime);
        }
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->validateKey($key);
        $cacheKey = $this->buildKey($key);
        $ttl = $ttl ?? $this->getDefaultTtl($key);

        try {
            Cache::put($cacheKey, $this->serialize($value), $ttl);
            $this->monitor->recordWrite($key);
            return true;

        } catch (\Exception $e) {
            $this->monitor->recordError($key, $e);
            Log::error('Cache write failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function get(string $key): mixed
    {
        $this->validateKey($key);
        $cacheKey = $this->buildKey($key);

        try {
            $value = Cache::get($cacheKey);
            return $value ? $this->unserialize($value) : null;

        } catch (\Exception $e) {
            $this->monitor->recordError($key, $e);
            Log::error('Cache read failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);
        return Cache::has($this->buildKey($key));
    }

    public function forget(string $key): bool
    {
        $this->validateKey($key);
        return Cache::forget($this->buildKey($key));
    }

    public function tags(array $tags): static
    {
        foreach ($tags as $tag) {
            $this->validateKey($tag);
        }
        return new static($this->security, $this->monitor, [
            'tags' => $tags,
            ...$this->config
        ]);
    }

    protected function validateKey(string $key): void
    {
        if (empty($key)) {
            throw new \InvalidArgumentException('Cache key cannot be empty');
        }

        if (strlen($key) > 250) {
            throw new \InvalidArgumentException('Cache key too long');
        }

        if (!preg_match('/^[a-zA-Z0-9:._-]+$/', $key)) {
            throw new \InvalidArgumentException('Invalid cache key format');
        }

        if (in_array($key, self::CRITICAL_KEYS)) {
            $this->security->validateAccess("cache.$key");
        }
    }

    protected function buildKey(string $key): string
    {
        $prefix = $this->config['prefix'] ?? 'app';
        return sprintf(
            '%s:%s:%s',
            $prefix,
            $this->security->getCurrentContext(),
            $key
        );
    }

    protected function getDefaultTtl(string $key): int
    {
        return $this->config['ttl'][$key] ?? 
               $this->config['default_ttl'] ?? 
               3600;
    }

    protected function serialize(mixed $value): string
    {
        return serialize($value);
    }

    protected function unserialize(string $value): mixed
    {
        return unserialize($value);
    }

    public function flush(): bool
    {
        if (!$this->security->isAdmin()) {
            throw new \RuntimeException('Only admins can flush cache');
        }
        return Cache::flush();
    }

    public function getMetrics(): array
    {
        return $this->monitor->getMetrics();
    }
}
