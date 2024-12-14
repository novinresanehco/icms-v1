<?php

namespace App\Core\Repository;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use App\Core\Security\ValidationService;
use App\Core\Security\AuditService;
use App\Core\Exceptions\ValidationException;
use App\Core\Exceptions\SecurityException;

abstract class BaseRepository implements RepositoryInterface
{
    protected Model $model;
    protected ValidationService $validator;
    protected AuditService $auditor;
    protected array $rules = [];
    protected int $cacheTtl = 3600;

    public function __construct(
        Model $model,
        ValidationService $validator,
        AuditService $auditor
    ) {
        $this->model = $model;
        $this->validator = $validator;
        $this->auditor = $auditor;
    }

    /**
     * Find entity by ID with security validation and caching
     */
    public function find(int $id): ?Model
    {
        $cacheKey = $this->getCacheKey('find', $id);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($id) {
            $entity = $this->model->find($id);
            $this->validateAccess($entity, 'read');
            return $entity;
        });
    }

    /**
     * Create new entity with validation and security checks
     */
    public function create(array $data): Model
    {
        $this->validateOperation($data, 'create');

        return DB::transaction(function () use ($data) {
            $entity = $this->model->create($data);
            
            $this->auditor->logCreation(
                $this->model->getTable(),
                $entity->id,
                $data
            );
            
            $this->invalidateCache();
            return $entity;
        });
    }

    /**
     * Update entity with validation and security checks
     */
    public function update(int $id, array $data): Model
    {
        $entity = $this->find($id);
        $this->validateOperation($data, 'update');
        $this->validateAccess($entity, 'update');

        return DB::transaction(function () use ($entity, $data) {
            $entity->update($data);
            
            $this->auditor->logUpdate(
                $this->model->getTable(),
                $entity->id,
                $data
            );
            
            $this->invalidateCache();
            return $entity;
        });
    }

    /**
     * Delete entity with security validation
     */
    public function delete(int $id): bool
    {
        $entity = $this->find($id);
        $this->validateAccess($entity, 'delete');

        return DB::transaction(function () use ($entity, $id) {
            $result = $entity->delete();
            
            $this->auditor->logDeletion(
                $this->model->getTable(),
                $id
            );
            
            $this->invalidateCache();
            return $result;
        });
    }

    /**
     * Validate operation data against rules
     */
    protected function validateOperation(array $data, string $operation): void
    {
        $rules = $this->getRulesForOperation($operation);
        
        if (!$this->validator->validate($data, $rules)) {
            throw new ValidationException(
                "Validation failed for {$operation} operation"
            );
        }
    }

    /**
     * Validate access to entity
     */
    protected function validateAccess(?Model $entity, string $operation): void
    {
        if ($entity && !$this->validator->validateAccess($entity, $operation)) {
            throw new SecurityException(
                "Access denied for {$operation} operation"
            );
        }
    }

    /**
     * Get cache key for operation
     */
    protected function getCacheKey(string $operation, ...$params): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->model->getTable(),
            $operation,
            implode(':', $params)
        );
    }

    /**
     * Invalidate cache for repository
     */
    protected function invalidateCache(): void
    {
        Cache::tags($this->model->getTable())->flush();
    }

    /**
     * Get validation rules for operation
     */
    abstract protected function getRulesForOperation(string $operation): array;
}
