<?php

namespace App\Core\Repository;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\{Cache, DB};
use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;

abstract class BaseRepository implements RepositoryInterface
{
    protected Model $model;
    protected SecurityManager $security;
    protected CacheManager $cache;
    protected array $defaultCriteria = [];
    protected int $cacheTtl;

    public function __construct(
        Model $model,
        SecurityManager $security,
        CacheManager $cache
    ) {
        $this->model = $model;
        $this->security = $security;
        $this->cache = $cache;
        $this->cacheTtl = config('cache.ttl', 3600);
        $this->boot();
    }

    public function findSecure(int $id, array $relations = []): ?Model
    {
        return $this->security->executeSecureOperation(
            fn() => $this->find($id, $relations),
            new OperationContext('find', compact('id', 'relations'))
        );
    }

    public function find(int $id, array $relations = []): ?Model
    {
        return $this->cache->remember(
            $this->getCacheKey('find', $id, $relations),
            $this->cacheTtl,
            fn() => $this->findInDatabase($id, $relations)
        );
    }

    public function createSecure(array $data): Model
    {
        return $this->security->executeSecureOperation(
            fn() => $this->create($data),
            new OperationContext('create', compact('data'))
        );
    }

    public function create(array $data): Model
    {
        DB::beginTransaction();
        try {
            $model = $this->model->create($data);
            $this->processRelations($model, $data);
            DB::commit();
            
            $this->clearModelCache();
            return $model;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function updateSecure(int $id, array $data): Model
    {
        return $this->security->executeSecureOperation(
            fn() => $this->update($id, $data),
            new OperationContext('update', compact('id', 'data'))
        );
    }

    public function update(int $id, array $data): Model
    {
        DB::beginTransaction();
        try {
            $model = $this->findOrFail($id);
            $model->update($data);
            $this->processRelations($model, $data);
            DB::commit();
            
            $this->clearModelCache($id);
            return $model;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function deleteSecure(int $id): bool
    {
        return $this->security->executeSecureOperation(
            fn() => $this->delete($id),
            new OperationContext('delete', compact('id'))
        );
    }

    public function delete(int $id): bool
    {
        DB::beginTransaction();
        try {
            $model = $this->findOrFail($id);
            $deleted = $model->delete();
            DB::commit();
            
            $this->clearModelCache($id);
            return $deleted;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function findInDatabase(int $id, array $relations = []): ?Model
    {
        $query = $this->model->newQuery();
        
        if (!empty($relations)) {
            $query->with($relations);
        }

        foreach ($this->getCriteria() as $criteria) {
            $criteria->apply($query);
        }

        return $query->find($id);
    }

    protected function findOrFail(int $id): Model
    {
        $model = $this->find($id);
        
        if (!$model) {
            throw new ModelNotFoundException(
                "Model {$this->model->getTable()} not found with ID {$id}"
            );
        }

        return $model;
    }

    protected function processRelations(Model $model, array $data): void
    {
        if (method_exists($this, 'withRelations')) {
            $this->withRelations($model, $data);
        }
    }

    protected function getCacheKey(string $operation, ...$params): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->model->getTable(),
            $operation,
            md5(serialize($params))
        );
    }

    protected function clearModelCache(int $id = null): void
    {
        $tags = [$this->model->getTable()];
        
        if ($id !== null) {
            $tags[] = $this->getCacheKey('find', $id);
        }

        $this->cache->tags($tags)->flush();
    }

    protected function getCriteria(): array
    {
        return $this->defaultCriteria;
    }

    protected function boot(): void
    {
        if (method_exists($this, 'initialize')) {
            $this->initialize();
        }
    }
}
