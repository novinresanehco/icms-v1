<?php

namespace App\Core\Repositories;

use Illuminate\Database\Eloquent\Model;
use App\Core\Contracts\{
    RepositoryInterface,
    CacheManagerInterface,
    ValidationServiceInterface
};
use App\Core\Security\CoreSecurityService;

abstract class BaseRepository implements RepositoryInterface
{
    protected Model $model;
    protected CacheManagerInterface $cache;
    protected ValidationServiceInterface $validator;
    protected CoreSecurityService $security;

    public function __construct(
        Model $model,
        CacheManagerInterface $cache,
        ValidationServiceInterface $validator,
        CoreSecurityService $security
    ) {
        $this->model = $model;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->security = $security;
    }

    public function find(int $id): ?Model
    {
        return $this->security->executeSecureOperation(
            fn() => $this->findSecure($id),
            ['operation' => 'find', 'model' => $this->model->getTable(), 'id' => $id]
        );
    }

    public function create(array $data): Model
    {
        return $this->security->executeSecureOperation(
            fn() => $this->createSecure($data),
            ['operation' => 'create', 'model' => $this->model->getTable()]
        );
    }

    public function update(int $id, array $data): Model
    {
        return $this->security->executeSecureOperation(
            fn() => $this->updateSecure($id, $data),
            ['operation' => 'update', 'model' => $this->model->getTable(), 'id' => $id]
        );
    }

    public function delete(int $id): bool
    {
        return $this->security->executeSecureOperation(
            fn() => $this->deleteSecure($id),
            ['operation' => 'delete', 'model' => $this->model->getTable(), 'id' => $id]
        );
    }

    protected function findSecure(int $id): ?Model
    {
        return $this->cache->remember(
            $this->getCacheKey('find', $id),
            function() use ($id) {
                return $this->model->find($id);
            }
        );
    }

    protected function createSecure(array $data): Model
    {
        $validated = $this->validator->validate($data, $this->getValidationRules());
        
        $model = $this->model->create($validated);
        
        $this->cache->invalidatePattern(
            $this->getCachePattern($model->getTable())
        );
        
        return $model;
    }

    protected function updateSecure(int $id, array $data): Model
    {
        $validated = $this->validator->validate($data, $this->getValidationRules());
        
        $model = $this->model->findOrFail($id);
        $model->update($validated);
        
        $this->cache->invalidatePattern(
            $this->getCachePattern($model->getTable())
        );
        
        return $model;
    }

    protected function deleteSecure(int $id): bool
    {
        $model = $this->model->findOrFail($id);
        $deleted = $model->delete();
        
        if ($deleted) {
            $this->cache->invalidatePattern(
                $this->getCachePattern($model->getTable())
            );
        }
        
        return $deleted;
    }

    abstract protected function getValidationRules(): array;
    
    protected function getCacheKey(string $operation, ...$params): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->model->getTable(),
            $operation,
            implode(':', $params)
        );
    }
    
    protected function getCachePattern(string $table): string
    {
        return sprintf('%s:*', $table);
    }
}
