<?php

namespace App\Core\Repository;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exceptions\{DataException, ValidationException};
use Illuminate\Database\Eloquent\{Model, Builder};
use Illuminate\Support\Facades\{DB, Log};

abstract class BaseRepository implements RepositoryInterface
{
    protected Model $model;
    protected SecurityManagerInterface $security;
    protected ValidationService $validator;
    protected CacheManager $cache;
    protected array $searchable = [];
    protected array $filterable = [];
    protected array $sortable = [];

    public function __construct(
        Model $model,
        SecurityManagerInterface $security,
        ValidationService $validator,
        CacheManager $cache
    ) {
        $this->model = $model;
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
    }

    public function create(array $data): Model
    {
        return DB::transaction(function() use ($data) {
            $validated = $this->validateData($data);
            $secureData = $this->security->encryptSensitiveData($validated);
            
            $entity = $this->model->create($secureData);
            $this->cache->invalidatePattern($this->getCachePattern());
            
            return $entity;
        });
    }

    public function update(int $id, array $data): Model
    {
        return DB::transaction(function() use ($id, $data) {
            $entity = $this->findOrFail($id);
            
            $validated = $this->validateData($data, $id);
            $secureData = $this->security->encryptSensitiveData($validated);
            
            $entity->update($secureData);
            $this->cache->invalidate($this->getCacheKey($id));
            
            return $entity->fresh();
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function() use ($id) {
            $entity = $this->findOrFail($id);
            
            $result = $entity->delete();
            $this->cache->invalidate($this->getCacheKey($id));
            
            return $result;
        });
    }

    public function find(int $id, array $relations = []): ?Model
    {
        return $this->cache->remember(
            $this->getCacheKey($id),
            fn() => $this->findQuery($id, $relations)->first()
        );
    }

    public function findOrFail(int $id, array $relations = []): Model
    {
        $entity = $this->find($id, $relations);
        
        if (!$entity) {
            throw new DataException("Entity not found: {$id}");
        }
        
        return $entity;
    }

    public function findByField(string $field, $value, array $relations = []): ?Model
    {
        return $this->cache->remember(
            $this->getCacheKey("{$field}:{$value}"),
            fn() => $this->model
                ->with($relations)
                ->where($field, $value)
                ->first()
        );
    }

    public function paginate(
        array $criteria = [],
        int $perPage = 15,
        array $relations = [],
        string $sortField = 'id',
        string $sortOrder = 'desc'
    ): LengthAwarePaginator {
        $query = $this->model->with($relations);
        
        // Apply search criteria
        if (!empty($criteria['search'])) {
            $query = $this->applySearch($query, $criteria['search']);
        }
        
        // Apply filters
        if (!empty($criteria['filters'])) {
            $query = $this->applyFilters($query, $criteria['filters']);
        }
        
        // Apply sorting
        if (in_array($sortField, $this->sortable)) {
            $query = $query->orderBy($sortField, $sortOrder);
        }
        
        // Execute paginated query
        return $query->paginate($perPage);
    }

    protected function findQuery(int $id, array $relations): Builder
    {
        return $this->model
            ->with($relations)
            ->where('id', $id);
    }

    protected function validateData(array $data, ?int $id = null): array
    {
        $rules = $this->getValidationRules($id);
        
        if (!$this->validator->validate($data, $rules)) {
            throw new ValidationException('Invalid data provided');
        }
        
        return $data;
    }

    protected function applySearch(Builder $query, string $search): Builder
    {
        return $query->where(function($query) use ($search) {
            foreach ($this->searchable as $field) {
                $query->orWhere($field, 'LIKE', "%{$search}%");
            }
        });
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        foreach ($filters as $field => $value) {
            if (in_array($field, $this->filterable)) {
                if (is_array($value)) {
                    $query->whereIn($field, $value);
                } else {
                    $query->where($field, $value);
                }
            }
        }
        
        return $query;
    }

    protected function getCacheKey(string $identifier): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->model->getTable(),
            $this->getCacheVersion(),
            $identifier
        );
    }

    protected function getCachePattern(): string
    {
        return sprintf(
            '%s:%s:*',
            $this->model->getTable(),
            $this->getCacheVersion()
        );
    }

    protected function getCacheVersion(): string
    {
        return config(
            sprintf('cache.versions.%s', $this->model->getTable()),
            'v1'
        );
    }

    abstract protected function getValidationRules(?int $id = null): array;
}
