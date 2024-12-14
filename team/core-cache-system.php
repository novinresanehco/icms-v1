<?php

namespace App\Core\Cache;

use Illuminate\Support\Facades\{Cache, Redis, Log};
use App\Core\Security\SecurityManagerInterface;
use App\Core\Monitoring\PerformanceMonitor;

class CacheManager implements CacheManagerInterface 
{
    private SecurityManagerInterface $security;
    private PerformanceMonitor $monitor;
    private array $config;
    private string $prefix;

    public function __construct(
        SecurityManagerInterface $security,
        PerformanceMonitor $monitor,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->config = $config;
        $this->prefix = config('cache.prefix', 'cms');
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $cacheKey = $this->generateKey($key);
        $this->validateKey($cacheKey);

        $span = $this->monitor->startSpan('cache.remember');

        try {
            if ($value = $this->get($cacheKey)) {
                $this->monitor->incrementHits();
                return $value;
            }

            $value = $this->security->executeCriticalOperation(
                fn() => $callback(),
                new SecurityContext('cache.generate', ['key' => $key])
            );

            $this->put($cacheKey, $value, $ttl);
            $this->monitor->incrementMisses();

            return $value;

        } finally {
            $this->monitor->endSpan($span);
        }
    }

    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        $cacheKey = $this->generateKey($key);
        $this->validateKey($cacheKey);
        $ttl ??= $this->config['ttl'] ?? 3600;

        $span = $this->monitor->startSpan('cache.put');

        try {
            $success = Cache::put(
                $cacheKey,
                $this->security->encryptIfNeeded($value),
                $ttl
            );

            if ($success) {
                $this->trackDependencies($cacheKey, $value);
                $this->monitor->recordCacheSize($cacheKey, strlen(serialize($value)));
            }

            return $success;

        } finally {
            $this->monitor->endSpan($span);
        }
    }

    public function get(string $key): mixed
    {
        $cacheKey = $this->generateKey($key);
        $this->validateKey($cacheKey);

        $span = $this->monitor->startSpan('cache.get');

        try {
            $value = Cache::get($cacheKey);

            if ($value !== null) {
                return $this->security->decryptIfNeeded($value);
            }

            return null;

        } finally {
            $this->monitor->endSpan($span);
        }
    }

    public function forget(string $key): bool
    {
        $cacheKey = $this->generateKey($key);
        $this->validateKey($cacheKey);

        $span = $this->monitor->startSpan('cache.forget');

        try {
            $this->invalidateDependencies($cacheKey);
            return Cache::forget($cacheKey);

        } finally {
            $this->monitor->endSpan($span);
        }
    }

    public function flush(): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => Cache::flush(),
            new SecurityContext('cache.flush')
        );
    }

    private function generateKey(string $key): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->prefix,
            $this->config['version'] ?? '1',
            $key
        );
    }

    private function validateKey(string $key): void
    {
        if (strlen($key) > 250) {
            throw new CacheException('Cache key too long');
        }

        if (!preg_match('/^[\w\-\:\.]+$/', $key)) {
            throw new CacheException('Invalid cache key format');
        }
    }

    private function trackDependencies(string $key, mixed $value): void
    {
        if (!is_object($value)) {
            return;
        }

        $dependencies = $this->extractDependencies($value);
        
        if (!empty($dependencies)) {
            Redis::sadd(
                $this->generateKey('dependencies'),
                array_merge([$key], $dependencies)
            );
        }
    }

    private function invalidateDependencies(string $key): void
    {
        $dependencies = Redis::smembers(
            $this->generateKey('dependencies')
        );

        foreach ($dependencies as $dep) {
            if (str_starts_with($dep, $key)) {
                Cache::forget($dep);
            }
        }
    }

    private function extractDependencies(object $value): array
    {
        $dependencies = [];

        if (method_exists($value, 'getCacheDependencies')) {
            $dependencies = array_merge(
                $dependencies,
                $value->getCacheDependencies()
            );
        }

        return array_map(
            fn($dep) => $this->generateKey($dep),
            array_unique($dependencies)
        );
    }
}
