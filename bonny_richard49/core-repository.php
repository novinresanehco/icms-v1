<?php

namespace App\Core\Repository;

use App\Core\Contracts\{RepositoryInterface, CacheableInterface};
use App\Core\Security\SecurityManager;
use App\Core\Services\{ValidationService, AuditService, CacheManager};
use Illuminate\Database\Eloquent\{Model, Builder};
use Illuminate\Support\Facades\DB;
use App\Core\Exceptions\{RepositoryException, ValidationException};

abstract class BaseRepository implements RepositoryInterface, CacheableInterface 
{
    protected Model $model;
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected AuditService $auditor;
    protected CacheManager $cache;
    
    public function __construct(
        Model $model,
        SecurityManager $security,
        ValidationService $validator,
        AuditService $auditor,
        CacheManager $cache
    ) {
        $this->model = $model;
        $this->security = $security;
        $this->validator = $validator;
        $this->auditor = $auditor;
        $this->cache = $cache;
    }

    public function find(int $id, array $relations = []): ?Model
    {
        return $this->security->executeSecureOperation(
            function() use ($id, $relations) {
                return $this->cache->remember(
                    $this->getCacheKey('find', $id),
                    fn() => $this->model->with($relations)->find($id)
                );
            },
            ['action' => 'find', 'id' => $id]
        );
    }

    public function create(array $data): Model
    {
        return $this->security->executeSecureOperation(
            function() use ($data) {
                return DB::transaction(function() use ($data) {
                    $validated = $this->validateData($data);
                    $model = $this->model->create($validated);
                    $this->clearModelCache();
                    return $model;
                });
            },
            ['action' => 'create', 'data' => $data]
        );
    }

    public function update(int $id, array $data): Model
    {
        return $this->security->executeSecureOperation(
            function() use ($id, $data) {
                return DB::transaction(function() use ($id, $data) {
                    $model = $this->findOrFail($id);
                    $validated = $this->validateData($data);
                    $model->update($validated);
                    $this->clearModelCache($id);
                    return $model->fresh();
                });
            },
            ['action' => 'update', 'id' => $id, 'data' => $data]
        );
    }

    public function delete(int $id): bool
    {
        return $this->security->executeSecureOperation(
            function() use ($id) {
                return DB::transaction(function() use ($id) {
                    $model = $this->findOrFail($id);
                    $result = $model->delete();
                    $this->clearModelCache($id);
                    return $result;
                });
            },
            ['action' => 'delete', 'id' => $id]
        );
    }

    protected function findOrFail(int $id): Model
    {
        $model = $this->find($id);
        
        if (!$model) {
            throw new RepositoryException("Model not found with ID: {$id}");
        }
        
        return $model;
    }

    protected function validateData(array $data): array
    {
        $rules = $this->getValidationRules($data);
        
        if (!$this->validator->validate($data, $rules)) {
            throw new ValidationException('Data validation failed');
        }
        
        return $data;
    }

    protected function getQuery(): Builder
    {
        return $this->model->newQuery();
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

    protected function clearModelCache(int $id = null): void
    {
        if ($id) {
            $this->cache->forget($this->getCacheKey('find', $id));
        }
        
        $this->cache->forget($this->getCacheKey('all'));
        $this->cache->tags($this->model->getTable())->flush();
    }

    abstract protected function getValidationRules(array $data = []): array;
}