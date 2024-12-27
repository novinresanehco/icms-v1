<?php

namespace App\Core\Repository;

use App\Core\Cache\CacheManager;
use App\Core\Validation\ValidationService;

abstract class BaseRepository implements RepositoryInterface
{
    protected Model $model;
    protected CacheManager $cache;
    protected ValidationService $validator;
    protected array $rules = [];

    public function find(int $id): ?Model
    {
        return $this->cache->remember(
            $this->getCacheKey('find', $id),
            fn() => $this->model->find($id)
        );
    }

    public function store(array $data): Model
    {
        $validated = $this->validator->validate($data, $this->rules);
        
        return DB::transaction(function() use ($validated) {
            $model = $this->model->create($validated);
            $this->cache->invalidate($this->getCacheKey('find', $model->id));
            return $model;
        });
    }

    public function update(int $id, array $data): Model
    {
        $validated = $this->validator->validate($data, $this->rules);
        
        return DB::transaction(function() use ($id, $validated) {
            $model = $this->model->findOrFail($id);
            $model->update($validated);
            $this->cache->invalidate($this->getCacheKey('find', $id));
            return $model;
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function() use ($id) {
            $result = $this->model->destroy($id);
            $this->cache->invalidate($this->getCacheKey('find', $id));
            return $result;
        });
    }

    protected function getCacheKey(string $operation, ...$params): string
    {
        return implode(':', array_merge(
            [$this->model->getTable(), $operation],
            $params
        ));
    }

    abstract protected function getRules(): array;
}
