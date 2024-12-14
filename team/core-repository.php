<?php

namespace App\Core\Repository;

abstract class BaseRepository implements RepositoryInterface
{
    protected Model $model;
    protected CacheManager $cache;
    protected ValidationService $validator;
    protected SecurityManager $security;
    protected MetricsCollector $metrics;
    protected array $criteria = [];

    public function __construct(
        Model $model,
        CacheManager $cache,
        ValidationService $validator,
        SecurityManager $security,
        MetricsCollector $metrics
    ) {
        $this->model = $model;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->security = $security;
        $this->metrics = $metrics;
    }

    public function find(int $id): ?Model
    {
        return $this->cache->remember(
            $this->getCacheKey('find', $id),
            fn() => $this->secureFind($id)
        );
    }

    public function create(array $data): Model
    {
        DB::beginTransaction();
        try {
            $data = $this->validateData($data, $this->getCreateRules());
            $model = $this->model->create($data);
            
            $this->security->validateAccess('create', $model);
            $this->logOperation('create', $model);
            
            DB::commit();
            $this->cache->flush($this->getCacheKey('list'));
            
            return $model;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError($e, 'create', $data);
            throw $e;
        }
    }

    public function update(int $id, array $data): Model
    {
        DB::beginTransaction();
        try {
            $model = $this->secureFind($id);
            $data = $this->validateData($data, $this->getUpdateRules($id));
            
            $this->security->validateAccess('update', $model);
            $model->update($data);
            
            $this->logOperation('update', $model);
            DB::commit();
            
            $this->cache->forget($this->getCacheKey('find', $id));
            $this->cache->flush($this->getCacheKey('list'));
            
            return $model;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError($e, 'update', ['id' => $id, 'data' => $data]);
            throw $e;
        }
    }

    public function delete(int $id): bool
    {
        DB::beginTransaction();
        try {
            $model = $this->secureFind($id);
            $this->security->validateAccess('delete', $model);
            
            $result = $model->delete();
            $this->logOperation('delete', $model);
            
            DB::commit();
            $this->cache->forget($this->getCacheKey('find', $id));
            $this->cache->flush($this->getCacheKey('list'));
            
            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError($e, 'delete', ['id' => $id]);
            throw $e;
        }
    }

    public function list(array $criteria = []): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey('list', $criteria),
            fn() => $this->secureFetch($criteria)
        );
    }

    protected function secureFind(int $id): ?Model
    {
        $model = $this->model->find($id);
        
        if (!$model) {
            throw new ModelNotFoundException("Model not found: {$id}");
        }
        
        $this->security->validateAccess('read', $model);
        return $model;
    }

    protected function secureFetch(array $criteria): Collection
    {
        $query = $this->model->newQuery();
        
        foreach ($criteria as $key => $value) {
            $query->where($key, $value);
        }
        
        $results = $query->get();
        $this->security->validateAccess('list', $results);
        
        return $results;
    }

    protected function validateData(array $data, array $rules): array
    {
        return $this->validator->validate($data, $rules);
    }

    protected function logOperation(string $operation, Model $model): void
    {
        $this->metrics->record("repository_operation", [
            'operation' => $operation,
            'model' => get_class($model),
            'id' => $model->id,
            'timestamp' => now()
        ]);
    }

    protected function handleError(\Exception $e, string $operation, array $context): void
    {
        $this->metrics->increment("repository_error", [
            'operation' => $operation,
            'error' => get_class($e),
            'message' => $e->getMessage()
        ]);
    }

    protected function getCacheKey(string $operation, mixed ...$params): string
    {
        return sprintf(
            '%s:%s:%s',
            get_class($this->model),
            $operation,
            md5(serialize($params))
        );
    }

    abstract protected function getCreateRules(): array;
    abstract protected function getUpdateRules(int $id): array;
}
