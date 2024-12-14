<?php

namespace App\Core\Repositories\Cache;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use App\Core\Contracts\CacheStrategyInterface;

class AdvancedCacheStrategy implements CacheStrategyInterface
{
    protected string $prefix;
    protected int $ttl;
    protected array $tags;
    protected bool $useVersioning;
    
    public function __construct(array $config = [])
    {
        $this->prefix = $config['prefix'] ?? 'repo_cache_';
        $this->ttl = $config['ttl'] ?? 3600;
        $this->tags = $config['tags'] ?? [];
        $this->useVersioning = $config['use_versioning'] ?? true;
    }

    public function remember(string $key, callable $callback, ?int $ttl = null)
    {
        $cacheKey = $this->buildKey($key);
        $cacheTags = $this->buildTags();

        if ($this->useVersioning) {
            $cacheKey = $this->getVersionedKey($cacheKey);
        }

        return Cache::tags($cacheTags)->remember(
            $cacheKey,
            $ttl ?? $this->ttl,
            $callback
        );
    }

    public function invalidate(Model $model, string $operation = null): void
    {
        $modelTags = $this->getModelTags($model);
        
        if ($this->useVersioning) {
            $this->incrementVersion($modelTags);
        } else {
            Cache::tags($modelTags)->flush();
        }

        // Invalidate related caches based on operation
        if ($operation) {
            $this->invalidateRelated($model, $operation);
        }
    }

    protected function buildKey(string $key): string
    {
        return "{$this->prefix}{$key}";
    }

    protected function buildTags(): array
    {
        return array_merge($this->tags, ['repository_cache']);
    }

    protected function getModelTags(Model $model): array
    {
        return [
            "model:" . get_class($model),
            "table:" . $model->getTable(),
            "id:" . $model->getKey()
        ];
    }

    protected function getVersionedKey(string $key): string
    {
        $version = Cache::get("version:{$key}", 1);
        return "{$key}:v{$version}";
    }

    protected function incrementVersion(array $tags): void
    {
        foreach ($tags as $tag) {
            $versionKey = "version:{$tag}";
            Cache::increment($versionKey, 1);
        }
    }

    protected function invalidateRelated(Model $model, string $operation): void
    {
        // Invalidate related model caches
        foreach ($model->getRelations() as $relation => $models) {
            if ($models instanceof Collection) {
                foreach ($models as $relatedModel) {
                    $this->invalidate($relatedModel);
                }
            } elseif ($models instanceof Model) {
                $this->invalidate($models);
            }
        }

        // Invalidate operation-specific caches
        $operationTag = "operation:{$operation}";
        if ($this->useVersioning) {
            $this->incrementVersion([$operationTag]);
        } else {
            Cache::tags([$operationTag])->flush();
        }
    }
}
