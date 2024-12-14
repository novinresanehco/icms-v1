<?php

namespace App\Core\Services\Cache;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;
use App\Core\Contracts\CacheServiceInterface;

interface CacheServiceInterface
{
    public function remember(string $key, $value, ?int $ttl = null);
    public function forget(string $key): bool;
    public function tags(array $tags): self;
    public function invalidateModelCache(Model $model): void;
}

class CacheService implements CacheServiceInterface
{
    protected array $config;
    protected ?array $currentTags = null;
    protected const DEFAULT_TTL = 3600; // 1 hour

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'prefix' => 'cms_cache_',
            'default_ttl' => self::DEFAULT_TTL,
            'use_tags' => true
        ], $config);
    }

    public function remember(string $key, $value, ?int $ttl = null)
    {
        $ttl = $ttl ?? $this->config['default_ttl'];
        $cacheKey = $this->getCacheKey($key);

        $cacheInstance = $this->currentTags 
            ? Cache::tags($this->currentTags)
            : Cache::store();

        return $cacheInstance->remember($cacheKey, Carbon::now()->addSeconds($ttl), $value);
    }

    public function forget(string $key): bool
    {
        $cacheKey = $this->getCacheKey($key);
        
        $cacheInstance = $this->currentTags 
            ? Cache::tags($this->currentTags)
            : Cache::store();

        return $cacheInstance->forget($cacheKey);
    }

    public function tags(array $tags): self
    {
        if (!$this->config['use_tags']) {
            throw new \RuntimeException('Cache tags are not supported with current cache driver');
        }

        $this->currentTags = $tags;
        return $this;
    }

    public function invalidateModelCache(Model $model): void
    {
        $modelClass = get_class($model);
        $modelId = $model->getKey();
        
        $tags = [
            $this->config['prefix'] . strtolower(class_basename($modelClass)),
            $this->config['prefix'] . "model_{$modelId}"
        ];

        if ($this->config['use_tags']) {
            Cache::tags($tags)->flush();
        }

        // Also clear specific cache keys
        $this->forget("model_{$modelClass}_{$modelId}");
        $this->forget("model_list_{$modelClass}");
    }

    protected function getCacheKey(string $key): string
    {
        return $this->config['prefix'] . $key;
    }

    public function flush(): bool
    {
        if ($this->currentTags) {
            return Cache::tags($this->currentTags)->flush();
        }
        return Cache::flush();
    }

    protected function isTaggable(): bool
    {
        return in_array(Cache::getDefaultDriver(), ['redis', 'memcached']);
    }
}
