<?php

namespace App\Core\Repository;

use Illuminate\Database\Eloquent\{Model, Builder, Collection};
use Illuminate\Support\Facades\{DB, Cache};
use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Cache\CacheManager;

abstract class BaseRepository implements RepositoryInterface
{
    protected Model $model;
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected CacheManager $cache;
    protected array $config;
    
    public function __construct(
        Model $model,
        SecurityManager $security,
        ValidationService $validator,
        CacheManager $cache,
        array $config
    ) {
        $this->model = $model;
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function find(int $id): ?Model
    {
        return $this->cache->remember(
            $this->getCacheKey('find', $id),
            fn() => $this->model->find($id)
        );
    }

    public function findOrFail(int $id): Model
    {
        return $this->cache->remember(
            $this->getCacheKey('find', $id),
            fn() => $this->model->findOrFail($id)
        );
    }

    public function findBy(array $criteria, array $relations = []): Collection
    {
        $cacheKey = $this->getCacheKey('findBy', $criteria, $relations);
        
        return $this->cache->remember($cacheKey, function() use ($criteria, $relations) {
            $query = $this->model->newQuery();
            
            foreach ($criteria as $field => $value) {
                $query->where($field, $value);
            }
            
            if (!empty($relations)) {
                $query->with($relations);
            }
            
            return $query->get();
        });
    }

    public function create(array $data): Model
    {
        return $this->security->executeCriticalOperation(function() use ($data) {
            return DB::transaction(function() use ($data) {
                $validatedData = $this->validator->validate($data, $this->getValidationRules());
                $model = $this->model->create($validatedData);
                $this->cache->flush($this->getCacheTags());
                return $model;
            });
        });
    }

    public function update(int $id, array $data): Model
    {
        return $this->security->executeCriticalOperation(function() use ($id, $data) {
            return DB::transaction(function() use ($id, $data) {
                $model = $this->findOrFail($id);
                $validatedData = $this->validator->validate($data, $this->getValidationRules($id));
                $model->update($validatedData);
                $this->cache->flush($this->getCacheTags($id));
                return $model->fresh();
            });
        });
    }

    public function delete(int $id): bool
    {
        return $this->security->executeCriticalOperation(function() use ($id) {
            return DB::transaction(function() use ($id) {
                $model = $this->findOrFail($id);
                $result = $model->delete();
                $this->cache->flush($this->getCacheTags($id));
                return $result;
            });
        });
    }

    public function count(array $criteria = []): int
    {
        $cacheKey = $this->getCacheKey('count', $criteria);
        
        return $this->cache->remember($cacheKey, function() use ($criteria) {
            $query = $this->model->newQuery();
            
            foreach ($criteria as $field => $value) {
                $query->where($field, $value);
            }
            
            return $query->count();
        });
    }

    protected function newQuery(): Builder
    {
        return $this->model->newQuery();
    }

    protected function getCacheKey(string $operation, ...$params): string
    {
        $modelName = class_basename($this->model);
        $paramString = md5(serialize($params));
        return strtolower("${modelName}.${operation}.${paramString}");
    }

    protected function getCacheTags(int $id = null): array
    {
        $modelName = class_basename($this->model);
        $tags = [strtolower($modelName)];
        
        if ($id !== null) {
            $tags[] = strtolower("${modelName}.${id}");
        }
        
        return $tags;
    }

    abstract protected function getValidationRules(int $id = null): array;
}
