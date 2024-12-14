<?php

namespace App\Core\Repositories;

use App\Core\Contracts\Repositories\CacheableRepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

abstract class CacheableRepository extends AbstractRepository implements CacheableRepositoryInterface
{
    protected int $cacheTTL = 3600; // 1 hour default
    protected string $cachePrefix = 'repository_';

    public function find(int|string $id): ?Model
    {
        return Cache::remember(
            $this->getCacheKey(__FUNCTION__, [$id]),
            $this->getCacheTTL(),
            fn() => parent::find($id)
        );
    }

    public function findAll(): Collection
    {
        return Cache::remember(
            $this->getCacheKey(__FUNCTION__),
            $this->getCacheTTL(),
            fn() => parent::findAll()
        );
    }

    public function create(array $data): Model
    {
        $model = parent::create($data);
        $this->clearCache();
        return $model;
    }

    public function update(Model $model, array $data): bool
    {
        $updated = parent::update($model, $data);
        if ($updated) {
            $this->clearCache();
        }
        return $updated;
    }

    public function delete(Model $model): bool
    {
        $deleted = parent::delete($model);
        if ($deleted) {
            $this->clearCache();
        }
        return $deleted;
    }

    public function getCacheKey(string $method, array $parameters = []): string
    {
        $key = $this->cachePrefix . class_basename($this) . '_' . $method;
        if (!empty($parameters)) {
            $key .= '_' . md5(serialize($parameters));
        }
        return $key;
    }

    public function getCacheTTL(): int
    {
        return $this->cacheTTL;
    }

    public function clearCache(): bool
    {
        return Cache::tags($this->cachePrefix . class_basename($this))->flush();
    }
}
