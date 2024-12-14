<?php

namespace App\Core\Repository;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\{Cache, DB, Log};
use App\Core\Services\{ValidationService, SecurityService};
use App\Core\Exceptions\{ValidationException, SecurityException, RepositoryException};

abstract class BaseRepository
{
    protected Model $model;
    protected ValidationService $validator;
    protected SecurityService $security;
    protected array $criteria = [];
    
    // Maximum time for cache
    protected const MAX_CACHE_TTL = 3600;

    public function __construct(Model $model, ValidationService $validator, SecurityService $security)
    {
        $this->model = $model;
        $this->validator = $validator;
        $this->security = $security;
    }

    /**
     * Execute critical database operation with protection
     */
    protected function executeSecure(callable $operation)
    {
        DB::beginTransaction();
        
        try {
            $this->security->validateContext();
            $result = $operation();
            $this->validator->validateResult($result);
            
            DB::commit();
            $this->invalidateCache();
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::critical('Repository operation failed', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new RepositoryException('Operation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Find record by ID with security validation
     */
    public function find(int $id, array $columns = ['*']): ?Model
    {
        return Cache::remember($this->getCacheKey($id), self::MAX_CACHE_TTL, function() use ($id, $columns) {
            $result = $this->model->find($id, $columns);
            $this->security->validateAccess($result);
            return $result;
        });
    }

    /**
     * Create new record with validation
     */
    public function create(array $data): Model
    {
        return $this->executeSecure(function() use ($data) {
            $validated = $this->validator->validate($data, $this->getValidationRules());
            return $this->model->create($validated);
        });
    }

    /**
     * Update record with security check
     */
    public function update(int $id, array $data): Model
    {
        return $this->executeSecure(function() use ($id, $data) {
            $model = $this->find($id);
            
            if (!$model) {
                throw new RepositoryException("Record not found: {$id}");
            }
            
            $validated = $this->validator->validate($data, $this->getValidationRules());
            $model->update($validated);
            
            return $model;
        });
    }

    /**
     * Delete record with security verification 
     */
    public function delete(int $id): bool
    {
        return $this->executeSecure(function() use ($id) {
            $model = $this->find($id);
            
            if (!$model) {
                throw new RepositoryException("Record not found: {$id}");
            }
            
            return $model->delete();
        });
    }

    /**
     * Get models with criteria and security
     */
    public function get(array $columns = ['*']): Collection
    {
        return $this->executeCriteria()->get($columns);
    }

    /**
     * Add criteria to query
     */
    public function pushCriteria($criteria): self
    {
        $this->criteria[] = $criteria;
        return $this;
    }

    /**
     * Clear all criteria
     */
    public function resetCriteria(): self
    {
        $this->criteria = [];
        return $this;
    }

    /**
     * Execute all criteria on model
     */
    protected function executeCriteria(): Model
    {
        $model = $this->model;
        
        foreach ($this->criteria as $criteria) {
            $model = $criteria->apply($model);
        }
        
        $this->resetCriteria();
        return $model;
    }

    /**
     * Get cache key for model
     */
    protected function getCacheKey(int $id): string
    {
        return sprintf(
            '%s.%s.%d',
            $this->model->getTable(),
            'id',
            $id
        );
    }

    /**
     * Invalidate cache for model
     */
    protected function invalidateCache(): void
    {
        // Clear cache tags if available
        if (method_exists(Cache::getStore(), 'tags')) {
            Cache::tags($this->model->getTable())->flush();
        }
    }

    /**
     * Get validation rules for model
     */
    abstract protected function getValidationRules(): array;
}
