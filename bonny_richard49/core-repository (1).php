<?php

namespace App\Core\Repository;

abstract class CriticalRepository implements RepositoryInterface 
{
    protected ValidationChain $validator;
    protected CacheService $cache;
    protected AuditLogger $logger;
    protected Model $model;

    public function executeQuery(Operation $operation): Result
    {
        return DB::transaction(function() use ($operation) {
            $this->validateOperation($operation);
            
            $result = $this->model->newQuery()
                ->where($operation->getCriteria())
                ->when($operation->getIncludes(), 
                    fn($q, $includes) => $q->with($includes))
                ->when($operation->getFilters(),
                    fn($q, $filters) => $this->applyFilters($q, $filters))
                ->get();

            $this->validateResult($result);
            $this->cacheResult($operation, $result);
            
            return $result;
        });
    }

    protected function validateOperation(Operation $operation): void
    {
        $this->validator->validateChain([
            new OperationValidator($operation),
            new SecurityValidator($operation),
            new BusinessRuleValidator($operation)
        ]);
    }

    protected function applyFilters($query, array $filters): void
    {
        foreach($filters as $field => $value) {
            $query->where($field, $value);
        }
    }

    protected function validateResult($result): void
    {
        $this->validator->validateChain([
            new ResultIntegrityValidator($result),
            new DataConsistencyValidator($result)
        ]);
    }

    protected function cacheResult(Operation $operation, $result): void
    {
        $this->cache->remember(
            $this->getCacheKey($operation),
            $result,
            config('cache.ttl')
        );
    }

    abstract protected function getCacheKey(Operation $operation): string;
}