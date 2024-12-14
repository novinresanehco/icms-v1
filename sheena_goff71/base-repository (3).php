<?php

namespace App\Core\Repository;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Interfaces\RepositoryInterface;

abstract class BaseRepository implements RepositoryInterface
{
    protected Model $model;
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected string $cachePrefix;
    protected int $cacheTtl;

    public function __construct(
        Model $model,
        SecurityManager $security,
        ValidationService $validator
    ) {
        $this->model = $model;
        $this->security = $security;
        $this->validator = $validator;
        $this->cachePrefix = $this->determineCachePrefix();
        $this->cacheTtl = config('cache.ttl', 3600);
    }

    public function find(int $id): ?Model
    {
        return Cache::remember(
            $this->getCacheKey('find', $id),
            $this->cacheTtl,
            fn() => $this->findModel($id)
        );
    }

    public function create(array $data): Model
    {
        // Validate input data against rules
        $validated = $this->validator->validate($data, $this->getValidationRules());
        
        // Execute in transaction with security check
        return $this->security->executeSecure(function() use ($validated) {
            $model = $this->model->create($validated);
            $this->clearModelCache();
            return $model;
        });
    }

    public function update(int $id, array $data): Model
    {
        $validated = $this->validator->validate($data, $this->getUpdateRules());

        return $this->security->executeSecure(function() use ($id, $validated) {
            $model = $this->findModel($id);
            $model->update($validated);
            $this->clearModelCache($id);
            return $model;
        });
    }

    public function delete(int $id): bool
    {
        return $this->security->executeSecure(function() use ($id) {
            $model = $this->findModel($id);
            $result = $model->delete();
            $this->clearModelCache($id);
            return $result;
        });
    }

    protected function findModel(int $id): Model
    {
        $model = $this->model->find($id);
        
        if (!$model) {
            throw new ModelNotFoundException("Model not found with ID: {$id}");
        }

        return $model;
    }

    protected function getCacheKey(string $operation, mixed ...$params): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->cachePrefix,
            $operation,
            implode(':', $params)
        );
    }

    protected function clearModelCache(int $id = null): void
    {
        if ($id) {
            Cache::forget($this->getCacheKey('find', $id));
        }
        
        // Clear any listing caches
        Cache::tags($this->cachePrefix)->flush();
    }

    protected function determineCachePrefix(): string
    {
        return strtolower(class_basename($this->model));
    }

    abstract protected function getValidationRules(): array;
    abstract protected function getUpdateRules(): array;
}
