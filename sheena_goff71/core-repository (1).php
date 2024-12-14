<?php

namespace App\Core\Repository;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use App\Core\Services\{
    CacheManager,
    ValidationService,
    AuditService,
    SecurityService
};
use App\Core\Interfaces\RepositoryInterface;
use App\Core\Exceptions\{
    ValidationException,
    SecurityException,
    RepositoryException
};

abstract class BaseRepository implements RepositoryInterface
{
    protected Model $model;
    protected CacheManager $cache;
    protected ValidationService $validator;
    protected AuditService $audit;
    protected SecurityService $security;
    protected array $searchable = [];
    protected array $filterable = [];
    protected array $sortable = [];

    public function __construct(
        Model $model,
        CacheManager $cache,
        ValidationService $validator,
        AuditService $audit,
        SecurityService $security
    ) {
        $this->model = $model;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->security = $security;
    }

    public function find(int $id): ?Model
    {
        return $this->cache->remember(
            $this->getCacheKey('find', $id),
            config('cache.ttl'),
            fn() => $this->model->find($id)
        );
    }

    public function findOrFail(int $id): Model
    {
        if (!$model = $this->find($id)) {
            throw new RepositoryException("Resource not found with ID: {$id}");
        }
        return $model;
    }

    public function create(array $data): Model
    {
        DB::beginTransaction();
        
        try {
            // Validate input
            $validated = $this->validateData($data);
            
            // Security check
            $this->security->validateCreate($validated);
            
            // Create model
            $model = $this->model->create($validated);
            
            // Clear related cache
            $this->clearModelCache();
            
            // Log creation
            $this->audit->logCreation($model);
            
            DB::commit();
            return $model;
            
        } catch (Exception $e) {
            DB::rollBack();
            $this->handleError('create', $e, $data);
        }
    }

    public function update(Model $model, array $data): Model
    {
        DB::beginTransaction();
        
        try {
            // Validate input
            $validated = $this->validateData($data);
            
            // Security check
            $this->security->validateUpdate($model, $validated);
            
            // Update model
            $model->update($validated);
            
            // Clear related cache
            $this->clearModelCache($model->id);
            
            // Log update
            $this->audit->logUpdate($model, $data);
            
            DB::commit();
            return $model->fresh();
            
        } catch (Exception $e) {
            DB::rollBack();
            $this->handleError('update', $e, $data, $model);
        }
    }

    public function delete(Model $model): bool
    {
        DB::beginTransaction();
        
        try {
            // Security check
            $this->security->validateDelete($model);
            
            // Delete model
            $deleted = $model->delete();
            
            // Clear related cache
            $this->clearModelCache($model->id);
            
            // Log deletion
            $this->audit->logDeletion($model);
            
            DB::commit();
            return $deleted;
            
        } catch (Exception $e) {
            DB::rollBack();
            $this->handleError('delete', $e, [], $model);
        }
    }

    public function list(array $params = []): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey('list', $params),
            config('cache.ttl'),
            function() use ($params) {
                $query = $this->model->newQuery();
                
                // Apply search
                if (isset($params['search'])) {
                    $this->applySearch($query, $params['search']);
                }
                
                // Apply filters
                if (isset($params['filters'])) {
                    $this->applyFilters($query, $params['filters']);
                }
                
                // Apply sorting
                if (isset($params['sort'])) {
                    $this->applySort($query, $params['sort']);
                }
                
                // Apply pagination
                return $query->paginate($params['per_page'] ?? 15);
            }
        );
    }

    protected function validateData(array $data): array
    {
        return $this->validator->validate($data, $this->getValidationRules());
    }

    protected function handleError(string $operation, Exception $e, array $data, ?Model $model = null): void
    {
        $context = [
            'operation' => $operation,
            'data' => $data,
            'model' => $model ? $model->toArray() : null,
            'exception' => [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]
        ];

        $this->audit->logError($e, $context);

        throw new RepositoryException(
            "Repository {$operation} operation failed: " . $e->getMessage(),
            $e->getCode(),
            $e
        );
    }

    protected function clearModelCache(?int $id = null): void
    {
        $keys = ['list'];
        
        if ($id) {
            $keys[] = "find.{$id}";
        }

        $this->cache->invalidate($keys);
    }

    protected function applySearch(Builder $query, string $search): void
    {
        $query->where(function($query) use ($search) {
            foreach ($this->searchable as $field) {
                $query->orWhere($field, 'LIKE', "%{$search}%");
            }
        });
    }

    protected function applyFilters(Builder $query, array $filters): void
    {
        foreach ($filters as $field => $value) {
            if (in_array($field, $this->filterable)) {
                $query->where($field, $value);
            }
        }
    }

    protected function applySort(Builder $query, array $sort): void
    {
        foreach ($sort as $field => $direction) {
            if (in_array($field, $this->sortable)) {
                $query->orderBy($field, $direction);
            }
        }
    }

    abstract protected function getValidationRules(): array;
    
    abstract protected function getCacheKey(string $operation, mixed ...$params): string;
}
