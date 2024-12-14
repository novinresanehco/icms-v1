<?php

namespace App\Core\Template\Cache;

use App\Core\Template\Exceptions\CacheException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use Psr\SimpleCache\CacheInterface;

class TemplateCacheManager implements CacheInterface
{
    private Collection $stores;
    private array $config;
    private array $tags = [];

    public function __construct(array $config = [])
    {
        $this->stores = new Collection();
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Store a value in the cache
     *
     * @param string $key
     * @param mixed $value
     * @param null|int|\DateInterval $ttl
     * @return bool
     */
    public function set($key, $value, $ttl = null): bool
    {
        $ttl = $ttl ?? $this->config['default_ttl'];
        $store = $this->selectStore($key, $value);

        try {
            if (!empty($this->tags)) {
                return $store->tags($this->tags)->put($key, $value, $ttl);
            }
            return $store->put($key, $value, $ttl);
        } catch (\Exception $e) {
            throw new CacheException("Failed to store cache value: {$e->getMessage()}");
        }
    }

    /**
     * Retrieve a value from cache
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get($key, $default = null)
    {
        $store = $this->selectStore($key);

        try {
            if (!empty($this->tags)) {
                $value = $store->tags($this->tags)->get($key);
            } else {
                $value = $store->get($key);
            }

            return $value ?? $default;
        } catch (\Exception $e) {
            throw new CacheException("Failed to retrieve cache value: {$e->getMessage()}");
        }
    }

    /**
     * Delete a value from cache
     *
     * @param string $key
     * @return bool
     */
    public function delete($key): bool
    {
        $store = $this->selectStore($key);

        try {
            if (!empty($this->tags)) {
                return $store->tags($this->tags)->forget($key);
            }
            return $store->forget($key);
        } catch (\Exception $e) {
            throw new CacheException("Failed to delete cache value: {$e->getMessage()}");
        }
    }

    /**
     * Clear the entire cache
     *
     * @return bool
     */
    public function clear(): bool
    {
        try {
            if (!empty($this->tags)) {
                foreach ($this->stores as $store) {
                    $store->tags($this->tags)->flush();
                }
            } else {
                foreach ($this->stores as $store) {
                    $store->flush();
                }
            }
            return true;
        } catch (\Exception $e) {
            throw new CacheException("Failed to clear cache: {$e->getMessage()}");
        }
    }

    /**
     * Store multiple values
     *
     * @param array $values
     * @param null|int|\DateInterval $ttl
     * @return bool
     */
    public function setMultiple($values, $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }
        return true;
    }

    /**
     * Retrieve multiple values
     *
     * @param array $keys
     * @param mixed $default
     * @return array
     */
    public function getMultiple($keys, $default = null): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    /**
     * Delete multiple values
     *
     * @param array $keys
     * @return bool
     */
    public function deleteMultiple($keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    /**
     * Check if key exists
     *
     * @param string $key
     * @return bool
     */
    public function has($key): bool
    {
        $store = $this->selectStore($key);

        try {
            if (!empty($this->tags)) {
                return $store->tags($this->tags)->has($key);
            }
            return $store->has($key);
        } catch (\Exception $e) {
            throw new CacheException("Failed to check cache key: {$e->getMessage()}");
        }
    }

    /**
     * Set cache tags
     *
     * @param array|string $tags
     * @return self
     */
    public function tags($tags): self
    {
        $this->tags = is_array($tags) ? $tags : func_get_args();
        return $this;
    }

    /**
     * Remember a value in cache
     *
     * @param string $key
     * @param int|\DateInterval $ttl
     * @param \Closure $callback
     * @return mixed
     */
    public function remember(string $key, $ttl, \Closure $callback)
    {
        if ($this->has($key)) {
            return $this->get($key);
        }

        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    /**
     * Select appropriate cache store
     *
     * @param string $key
     * @param mixed $value
     * @return \Illuminate\Contracts\Cache\Repository
     */
    protected function selectStore(string $key, $value = null)
    {
        // Select store based on value size and type
        if ($value !== null && $this->isLargeValue($value)) {
            return Cache::store($this->config['large_value_store']);
        }

        // Use default store
        return Cache::store($this->config['default_store']);
    }

    /**
     * Check if value is considered large
     *
     * @param mixed $value
     * @return bool
     */
    protected function isLargeValue($value): bool
    {
        return strlen(serialize($value)) > $this->config['large_value_threshold'];
    }

    /**
     * Get default configuration
     *
     * @return array
     */
    protected function getDefaultConfig(): array
    {
        return [
            'default_store' => 'redis',
            'large_value_store' => 'file',
            'large_value_threshold' => 1024 * 1024, // 1MB
            'default_ttl' => 3600,
            'prefix' => 'template_cache:'
        ];
    }
}

class CacheWarmer
{
    private TemplateCacheManager $cache;
    private Collection $templates;

    public function __construct(TemplateCacheManager $cache)
    {
        $this->cache = $cache;
        $this->templates = new Collection();
    }

    /**
     * Register templates for warming
     *
     * @param string $template
     * @param array $variants
     * @return void
     */
    public function register(string $template, array $variants = []): void
    {
        $this->templates->put($template, $variants);
    }

    /**
     * Warm up the cache
     *
     * @return array Statistics about the warming process
     */
    public function warmUp(): array
    {
        $stats = ['success' => 0, 'failed' => 0];

        foreach ($this->templates as $template => $variants) {
            try {
                $this->warmTemplate($template, $variants);
                $stats['success']++;
            } catch (\Exception $e) {
                $stats['failed']++;
                // Log the failure
                \Log::error("Cache warming failed for template: {$template}", [
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $stats;
    }

    /**
     * Warm a specific template
     *
     * @param string $template
     * @param array $variants
     * @return void
     */
    protected function warmTemplate(string $template, array $variants): void
    {
        foreach ($variants as $variant) {
            $key = $this->generateCacheKey($template, $variant);
            if (!$this->cache->has($key)) {
                // Generate and cache the template
                $content = view($template, $variant)->render();
                $this->cache->set($key, $content);
            }
        }
    }

    /**
     * Generate cache key for template variant
     *
     * @param string $template
     * @param array $variant
     * @return string
     */
    protected function generateCacheKey(string $template, array $variant): string
    {
        return sprintf(
            'template:%s:%s',
            str_replace(['/', '.'], '_', $template),
            md5(serialize($variant))
        );
    }
}

// Service Provider Registration
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Template\Cache\TemplateCacheManager;
use App\Core\Template\Cache\CacheWarmer;

class TemplateCacheServiceProvider extends ServiceProvider
{
    /**
     * Register services
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(TemplateCacheManager::class, function ($app) {
            return new TemplateCacheManager([
                'default_store' => config('cache.default'),
                'large_value_store' => config('cache.alternative', 'file'),
                'prefix' => config('cache.prefix', 'template:')
            ]);
        });

        $this->app->singleton(CacheWarmer::class);
    }

    /**
     * Bootstrap services
     *
     * @return void
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            $warmer = $this->app->make(CacheWarmer::class);
            
            // Register common templates for warming
            $warmer->register('layouts.main', [
                ['title' => 'Home'],
                ['title' => 'About']
            ]);
        }
    }
}
