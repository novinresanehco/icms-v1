<?php

namespace App\Core\Cache\Contracts;

interface CacheManagerInterface
{
    public function get(string $key, $default = null);
    public function put(string $key, $value, $ttl = null): bool;
    public function remember(string $key, $ttl, Closure $callback);
    public function forget(string $key): bool;
    public function tags(array $tags): TaggedCache;
    public function flush(): bool;
}

namespace App\Core\Cache\Services;

class CacheManager implements CacheManagerInterface
{
    protected array $stores = [];
    protected array $drivers = [];
    protected Config $config;
    protected EventDispatcher $events;

    public function __construct(Config $config, EventDispatcher $events)
    {
        $this->config = $config;
        $this->events = $events;
    }

    public function get(string $key, $default = null)
    {
        $value = $this->store()->get($key);

        if ($value === null) {
            $this->events->dispatch(new CacheMissed($key));
            return value($default);
        }

        $this->events->dispatch(new CacheHit($key));
        return $value;
    }

    public function put(string $key, $value, $ttl = null): bool
    {
        $ttl = $ttl ?? $this->config->get('cache.ttl');
        $success = $this->store()->put($key, $value, $ttl);

        if ($success) {
            $this->events->dispatch(new CacheKeyWritten($key));
        }

        return $success;
    }

    public function remember(string $key, $ttl, Closure $callback)
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->put($key, $value, $ttl);

        return $value;
    }

    public function forget(string $key): bool
    {
        $success = $this->store()->forget($key);

        if ($success) {
            $this->events->dispatch(new CacheKeyForgotten($key));
        }

        return $success;
    }

    public function tags(array $tags): TaggedCache
    {
        return $this->store()->tags($tags);
    }

    public function flush(): bool
    {
        $success = $this->store()->flush();

        if ($success) {
            $this->events->dispatch(new CacheFlushed());
        }

        return $success;
    }

    protected function store(string $name = null): CacheStore
    {
        $name = $name ?? $this->getDefaultDriver();

        if (!isset($this->stores[$name])) {
            $this->stores[$name] = $this->createStore($name);
        }

        return $this->stores[$name];
    }

    protected function createStore(string $driver): CacheStore
    {
        if (isset($this->drivers[$driver])) {
            return call_user_func($this->drivers[$driver], $this->config);
        }

        $method = 'create'.ucfirst($driver).'Driver';

        if (method_exists($this, $method)) {
            return $this->$method();
        }

        throw new InvalidArgumentException("Cache driver [{$driver}] not supported.");
    }
}

namespace App\Core\Cache\Services;

class InvalidationManager
{
    protected CacheManager $cache;
    protected array $tags = [];
    protected array $patterns = [];

    public function invalidate(string $pattern): void
    {
        $keys = $this->findMatchingKeys($pattern);
        
        foreach ($keys as $key) {
            $this->cache->forget($key);
        }

        if (!empty($this->tags[$pattern])) {
            $this->cache->tags($this->tags[$pattern])->flush();
        }
    }

    public function registerPattern(string $pattern, array $tags = []): void
    {
        $this->patterns[] = $pattern;
        if (!empty($tags)) {
            $this->tags[$pattern] = $tags;
        }
    }

    protected function findMatchingKeys(string $pattern): array
    {
        $keys = [];
        $store = $this->cache->store()->getRedis();
        
        foreach ($store->keys($pattern) as $key) {
            $keys[] = $key;
        }

        return $keys;
    }
}

namespace App\Core\Cache\Services;

class WarmupManager
{
    protected CacheManager $cache;
    protected array $warmers = [];

    public function register(CacheWarmer $warmer): void
    {
        $this->warmers[] = $warmer;
    }

    public function warmup(): void
    {
        foreach ($this->warmers as $warmer) {
            try {
                $warmer->warm($this->cache);
            } catch (\Exception $e) {
                // Log error but continue with other warmers
                logger()->error('Cache warmer failed: ' . $e->getMessage());
            }
        }
    }
}

namespace App\Core\Cache\Services;

class CacheMetrics
{
    protected MetricsCollector $metrics;
    protected EventDispatcher $events;

    public function __construct(MetricsCollector $metrics, EventDispatcher $events)
    {
        $this->metrics = $metrics;
        $this->events = $events;
        
        $this->registerEventListeners();
    }

    protected function registerEventListeners(): void
    {
        $this->events->listen(CacheHit::class, function ($event) {
            $this->recordHit();
        });

        $this->events->listen(CacheMissed::class, function ($event) {
            $this->recordMiss();
        });

        $this->events->listen(CacheKeyWritten::class, function ($event) {
            $this->recordWrite();
        });
    }

    public function recordHit(): void
    {
        $this->metrics->increment('cache.hits');
    }

    public function recordMiss(): void
    {
        $this->metrics->increment('cache.misses');
    }

    public function recordWrite(): void
    {
        $this->metrics->increment('cache.writes');
    }

    public function getHitRatio(): float
    {
        $hits = $this->metrics->get('cache.hits');
        $total = $hits + $this->metrics->get('cache.misses');

        if ($total === 0) {
            return 0;
        }

        return $hits / $total;
    }
}

namespace App\Core\Cache\Strategies;

class CachingStrategy
{
    protected array $rules = [];

    public function shouldCache(string $key, $value = null): bool
    {
        foreach ($this->rules as $rule) {
            if (!$rule->evaluate($key, $value)) {
                return false;
            }
        }

        return true;
    }

    public function getTTL(string $key, $value = null): int
    {
        $ttl = config('cache.ttl');

        foreach ($this->rules as $rule) {
            if ($rule->hasTTL() && $rule->evaluate($key, $value)) {
                $ttl = min($ttl, $rule->getTTL());
            }
        }

        return $ttl;
    }

    public function getTags(string $key, $value = null): array
    {
        $tags = [];

        foreach ($this->rules as $rule) {
            if ($rule->evaluate($key, $value)) {
                $tags = array_merge($tags, $rule->getTags());
            }
        }

        return array_unique($tags);
    }
}

trait Cacheable
{
    public function getCacheKey(): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->getTable(),
            $this->getKey(),
            $this->updated_at?->timestamp ?? 'null'
        );
    }

    public function getCacheTags(): array
    {
        return [
            $this->getTable(),
            sprintf('%s:%s', $this->getTable(), $this->getKey())
        ];
    }

    protected static function bootCacheable(): void
    {
        static::saved(function ($model) {
            $model->invalidateCache();
        });

        static::deleted(function ($model) {
            $model->invalidateCache();
        });
    }

    public function invalidateCache(): void
    {
        $tags = $this->getCacheTags();
        cache()->tags($tags)->flush();
    }
}
