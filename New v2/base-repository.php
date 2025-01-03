<?php

namespace App\Core\Repository;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use App\Core\Cache\CacheManager;
use App\Core\Security\SecurityContext;

abstract class BaseRepository implements RepositoryInterface
{
    protected Model $model;
    protected CacheManager $cache;
    protected SecurityContext $context;

    public function findById(int $id, array $relations = []): ?Model
    {
        return $this->cache->remember(
            $this->getCacheKey('find', $id),
            fn() => $this->model->with($relations)->findOrFail($id)
        );
    }

    public function findWhere(array $criteria, array $relations = []): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey('where', md5(serialize($criteria))),
            fn() => $this->model->with($relations)->where($criteria)->get()
        );
    }

    public function create(array $data): Model
    {
        $model = $this->model->create($data);
        $this->clearModelCache();
        return $model;
    }

    public function update(int $id, array $data): Model
    {
        $model = $this->findById($id);
        $model->update($data);
        $this->clearModelCache();
        return $model;
    }

    public function delete(int $id): bool
    {
        $result = $this->model->findOrFail($id)->delete();
        $this->clearModelCache();
        return $result;
    }

    protected function clearModelCache(): void
    {
        $this->cache->tags($this->getCacheTags())->flush();
    }

    abstract protected function getCacheKey(string $operation, ...$params): string;
    
    protected function getCacheTags(): array
    {
        return [$this->model->getTable()];
    }
}
