<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Model;

class CacheService
{
    protected $tags = [];
    protected $prefix = 'cms';
    protected $defaultTTL = 3600;

    public function setTags(array $tags)
    {
        $this->tags = $tags;
        return $this;
    }

    public function remember(string $key, $callback, $ttl = null)
    {
        $key = $this->buildKey($key);
        $ttl = $ttl ?? $this->defaultTTL;

        if (!empty($this->tags)) {
            return Cache::tags($this->tags)->remember($key, $ttl, $callback);
        }

        return Cache::remember($key, $ttl, $callback);
    }

    public function forget(string $key)
    {
        $key = $this->buildKey($key);

        if (!empty($this->tags)) {
            return Cache::tags($this->tags)->forget($key);
        }

        return Cache::forget($key);
    }

    public function flush(array $tags = [])
    {
        $tags = $tags ?: $this->tags;

        if (!empty($tags)) {
            return Cache::tags($tags)->flush();
        }

        return Cache::flush();
    }

    public function rememberModel(Model $model, $callback, $ttl = null)
    {
        $key = $this->buildModelKey($model);
        return $this->setTags($this->getModelTags($model))
                    ->remember($key, $callback, $ttl);
    }

    public function forgetModel(Model $model)
    {
        $key = $this->buildModelKey($model);
        return $this->setTags($this->getModelTags($model))
                    ->forget($key);
    }

    protected function buildKey(string $key): string
    {
        return sprintf('%s.%s', $this->prefix, $key);
    }

    protected function buildModelKey(Model $model): string
    {
        return sprintf(
            '%s.%s.%s',
            $model->getTable(),
            $model->getKey(),
            md5(serialize($model->getAttributes()))
        );
    }

    protected function getModelTags(Model $model): array
    {
        return [
            sprintf('%s.%s', $model->getTable(), $model->getKey()),
            $model->getTable()
        ];
    }
}
