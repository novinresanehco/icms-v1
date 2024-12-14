<?php

namespace App\Core\Repository;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Core\Security\SecurityContext;
use App\Core\Validation\ValidationService;
use App\Core\Audit\AuditLogger;

abstract class BaseRepository implements RepositoryInterface 
{
    protected Model $model;
    protected ValidationService $validator;
    protected AuditLogger $auditLogger;
    protected array $validationRules;
    protected int $cacheTimeout = 3600;
    
    public function __construct(
        Model $model,
        ValidationService $validator,
        AuditLogger $auditLogger
    ) {
        $this->model = $model;
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
    }

    public function find(int $id, SecurityContext $context): ?Model 
    {
        return Cache::remember(
            $this->getCacheKey('find', $id),
            $this->cacheTimeout,
            function() use ($id, $context) {
                return DB::transaction(function() use ($id, $context) {
                    $result = $this->model->find($id);
                    $this->validateAccess($result, $context);
                    $this->auditLogger->logAccess($context, 'find', $id);
                    return $result;
                });
            }
        );
    }

    public function create(array $data, SecurityContext $context): Model 
    {
        return DB::transaction(function() use ($data, $context) {
            $validated = $this->validator->validateInput($data, $this->validationRules);
            $this->validateAccess(null, $context, 'create');
            
            $result = $this->model->create($validated);
            $this->clearRelatedCache();
            $this->auditLogger->logOperation($context, 'create', $result->id);
            
            return $result;
        });
    }

    public function update(int $id, array $data, SecurityContext $context): Model 
    {
        return DB::transaction(function() use ($id, $data, $context) {
            $model = $this->find($id, $context);
            $validated = $this->validator->validateInput($data, $this->validationRules);
            $this->validateAccess($model, $context, 'update');
            
            $model->update($validated);
            $this->clearModelCache($id);
            $this->auditLogger->logOperation($context, 'update', $id);
            
            return $model->fresh();
        });
    }

    public function delete(int $id, SecurityContext $context): bool 
    {
        return DB::transaction(function() use ($id, $context) {
            $model = $this->find($id, $context);
            $this->validateAccess($model, $context, 'delete');
            
            $result = $model->delete();
            $this->clearModelCache($id);
            $this->auditLogger->logOperation($context, 'delete', $id);
            
            return $result;
        });
    }

    public function findWhere(array $criteria, SecurityContext $context): Collection 
    {
        $cacheKey = $this->getCacheKey('findWhere', $criteria);
        
        return Cache::remember(
            $cacheKey,
            $this->cacheTimeout,
            function() use ($criteria, $context) {
                return DB::transaction(function() use ($criteria, $context) {
                    $query = $this->model->query();
                    
                    foreach ($criteria as $field => $value) {
                        $query->where($field, $value);
                    }
                    
                    $results = $query->get();
                    $this->validateBulkAccess($results, $context);
                    $this->auditLogger->logAccess($context, 'findWhere', $criteria);
                    
                    return $results;
                });
            }
        );
    }

    protected function validateAccess(?Model $model, SecurityContext $context, string $operation = 'view'): void 
    {
        if (!$this->checkAccess($model, $context, $operation)) {
            throw new AccessDeniedException("Access denied for operation: $operation");
        }
    }

    protected function validateBulkAccess(Collection $models, SecurityContext $context): void 
    {
        foreach ($models as $model) {
            $this->validateAccess($model, $context);
        }
    }

    protected function clearModelCache(int $id): void 
    {
        Cache::forget($this->getCacheKey('find', $id));
        $this->clearRelatedCache();
    }

    protected function clearRelatedCache(): void 
    {
        Cache::tags($this->getCacheTags())->flush();
    }

    protected function getCacheKey(string $operation, ...$params): string 
    {
        return sprintf(
            '%s.%s.%s',
            $this->model->getTable(),
            $operation,
            md5(serialize($params))
        );
    }

    protected function getCacheTags(): array 
    {
        return [$this->model->getTable()];
    }

    abstract protected function checkAccess(?Model $model, SecurityContext $context, string $operation): bool;
}
