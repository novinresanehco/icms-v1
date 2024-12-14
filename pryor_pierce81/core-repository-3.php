<?php

namespace App\Core\Repository;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\{Cache, DB};
use App\Core\Support\{ValidationService, CacheManager};
use App\Core\Exceptions\{ValidationException, RepositoryException};

abstract class BaseRepository implements RepositoryInterface 
{
    protected Model $model;
    protected CacheManager $cache;
    protected ValidationService $validator;

    public function __construct(
        Model $model,
        CacheManager $cache,
        ValidationService $validator
    ) {
        $this->model = $model;
        $this->cache = $cache;
        $this->validator = $validator;
    }

    public function find(int $id): ?Model
    {
        return $this->cache->remember(
            $this->getCacheKey('find', $id),
            config('cache.ttl'),
            fn() => $this->model->find($id)
        );
    }

    public function create(array $data): Model
    {
        return DB::transaction(function() use ($data) {
            // Validate
            $validated = $this->validateData($data, $this->createRules());

            // Create
            $model = $this->model->create($validated);

            // Clear relevant caches
            $this->clearModelCache($model);

            return $model;
        });
    }

    public function update(int $id, array $data): Model
    {
        return DB::transaction(function() use ($id, $data) {
            // Find model
            $model = $this->findOrFail($id);

            // Validate
            $validated = $this->validateData($data, $this->updateRules($id));

            // Update
            $model->update($validated);

            // Clear caches
            $this->clearModelCache($model);

            return $model->fresh();
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function() use ($id) {
            // Find model
            $model = $this->findOrFail($id);

            // Delete
            $deleted = $model->delete();

            // Clear caches
            $this->clearModelCache($model);

            return $deleted;
        });
    }

    protected function findOrFail(int $id): Model
    {
        $model = $this->find($id);

        if (!$model) {
            throw new RepositoryException(
                sprintf('Model [%s] not found with ID [%s]', 
                    class_basename($this->model), 
                    $id
                )
            );
        }

        return $model;
    }

    protected function validateData(array $data, array $rules): array
    {
        try {
            return $this->validator->validate($data, $rules);
        } catch (ValidationException $e) {
            throw new RepositoryException(
                'Validation failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    protected function clearModelCache(Model $model): void
    {
        $this->cache->tags($this->getCacheTags())
            ->flush();
    }

    protected function getCacheKey(string $operation, ...$params): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->model->getTable(),
            $operation,
            implode(':', $params)
        );
    }

    protected function getCacheTags(): array
    {
        return [
            $this->model->getTable()
        ];
    }

    abstract protected function createRules(): array;
    abstract protected function updateRules(int $id): array;
}
