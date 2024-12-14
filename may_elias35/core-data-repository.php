<?php

namespace App\Core\Repository;

use App\Core\Security\CoreSecurityManager;
use App\Core\Services\{CacheManager, ValidationService};
use Illuminate\Database\Eloquent\{Model, Builder};
use Illuminate\Support\Facades\DB;
use App\Core\Exceptions\{RepositoryException, SecurityException};

abstract class CoreRepository implements RepositoryInterface
{
    protected Model $model;
    protected CoreSecurityManager $security;
    protected CacheManager $cache;
    protected ValidationService $validator;
    protected array $allowedRelations = [];
    protected array $searchableFields = [];

    public function __construct(
        Model $model,
        CoreSecurityManager $security,
        CacheManager $cache,
        ValidationService $validator
    ) {
        $this->model = $model;
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
    }

    public function find(int $id, array $relations = []): ?Model
    {
        return $this->security->executeSecureOperation(
            function() use ($id, $relations) {
                $validatedRelations = $this->validateRelations($relations);
                
                return $this->cache->remember(
                    $this->getCacheKey($id, $validatedRelations),
                    function() use ($id, $validatedRelations) {
                        return $this->model->with($validatedRelations)->find($id);
                    }
                );
            },
            ['action' => 'read', 'resource' => $this->model->getTable()]
        );
    }

    public function create(array $data): Model
    {
        return $this->security->executeSecureOperation(
            function() use ($data) {
                $validatedData = $this->validateData($data);
                
                return DB::transaction(function() use ($validatedData) {
                    $model = $this->model->create($validatedData);
                    
                    if (isset($validatedData['relations'])) {
                        $this->handleRelations($model, $validatedData['relations']);
                    }
                    
                    $this->cache->tags($this->getCacheTags())->flush();
                    return $model->fresh();
                });
            },
            ['action' => 'create', 'resource' => $this->model->getTable()]
        );
    }

    public function update(int $id, array $data): Model
    {
        return $this->security->executeSecureOperation(
            function() use ($id, $data) {
                $validatedData = $this->validateData($data);
                $model = $this->findOrFail($id);
                
                return DB::transaction(function() use ($model, $validatedData) {
                    $model->update($validatedData);
                    
                    if (isset($validatedData['relations'])) {
                        $this->handleRelations($model, $validatedData['relations']);
                    }
                    
                    $this->cache->tags($this->getCacheTags())->flush();
                    return $model->fresh();
                });
            },
            ['action' => 'update', 'resource' => $this->model->getTable()]
        );
    }

    public function delete(int $id): bool
    {
        return $this->security->executeSecureOperation(
            function() use ($id) {
                return DB::transaction(function() use ($id) {
                    $model = $this->findOrFail($id);
                    $result = $model->delete();
                    $this->cache->tags($this->getCacheTags())->flush();
                    return $result;
                });
            },
            ['action' => 'delete', 'resource' => $this->model->getTable()]
        );
    }

    public function findWhere(array $conditions, array $relations = []): Collection
    {
        return $this->security->executeSecureOperation(
            function() use ($conditions, $relations) {
                $validatedRelations = $this->validateRelations($relations);
                $validatedConditions = $this->validateConditions($conditions);
                
                $cacheKey = $this->getCacheKey('where', $validatedConditions, $validatedRelations);
                
                return $this->cache->remember($cacheKey, function() use ($validatedConditions, $validatedRelations) {
                    return $this->buildQuery($validatedConditions)
                        ->with($validatedRelations)
                        ->get();
                });
            },
            ['action' => 'read', 'resource' => $this->model->getTable()]
        );
    }

    protected function findOrFail(int $id): Model
    {
        $model = $this->find($id);
        if (!$model) {
            throw new RepositoryException("Entity not found: {$id}");
        }
        return $model;
    }

    protected function validateData(array $data): array
    {
        return $this->validator->validate($data, $this->getValidationRules());
    }

    protected function validateRelations(array $relations): array
    {
        return array_intersect($relations, $this->allowedRelations);
    }

    protected function validateConditions(array $conditions): array
    {
        $validated = [];
        foreach ($conditions as $field => $value) {
            if (in_array($field, $this->searchableFields)) {
                $validated[$field] = $value;
            }
        }
        return $validated;
    }

    protected function handleRelations(Model $model, array $relations): void
    {
        foreach ($relations as $relation => $data) {
            if (method_exists($model, $relation)) {
                $this->updateRelation($model, $relation, $data);
            }
        }
    }

    protected function buildQuery(array $conditions): Builder
    {
        $query = $this->model->newQuery();
        foreach ($conditions as $field => $value) {
            if (is_array($value)) {
                $query->whereIn($field, $value);
            } else {
                $query->where($field, $value);
            }
        }
        return $query;
    }

    protected function getCacheKey(...$params): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->model->getTable(),
            $this->getCurrentCacheVersion(),
            md5(serialize($params))
        );
    }

    protected function getCacheTags(): array
    {
        return [$this->model->getTable()];
    }

    protected function getCurrentCacheVersion(): string
    {
        return $this->cache->get(
            "cache_version:{$this->model->getTable()}",
            fn() => time()
        );
    }

    protected function updateRelation(Model $model, string $relation, array $data): void
    {
        if (method_exists($model, $relation)) {
            $model->$relation()->sync($data);
        }
    }

    abstract protected function getValidationRules(): array;
}
