<?php

namespace App\Core\Data;

class DataManager implements DataManagerInterface
{
    private Repository $repository;
    private CacheManager $cache;
    private ValidationService $validator;
    private SecurityManager $security;
    private MetricsCollector $metrics;

    public function __construct(
        Repository $repository,
        CacheManager $cache,
        ValidationService $validator,
        SecurityManager $security,
        MetricsCollector $metrics
    ) {
        $this->repository = $repository;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->security = $security;
        $this->metrics = $metrics;
    }

    public function executeDataOperation(DataOperation $operation): OperationResult
    {
        $startTime = microtime(true);
        DB::beginTransaction();

        try {
            $validatedData = $this->validator->validateData(
                $operation->getData(),
                $operation->getValidationRules()
            );

            $securityContext = new SecurityContext(
                $operation->getUser(),
                $operation->getResourceType(),
                $validatedData
            );
            
            $this->security->validateAccess($securityContext);

            $result = match($operation->getType()) {
                'create' => $this->handleCreate($validatedData),
                'update' => $this->handleUpdate($operation->getId(), $validatedData),
                'delete' => $this->handleDelete($operation->getId()),
                'retrieve' => $this->handleRetrieve($operation->getId()),
                default => throw new InvalidOperationException(),
            };

            DB::commit();
            $this->logSuccess($operation, $result);
            $this->invalidateCache($operation);
            
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($operation, $e);
            throw $e;
        } finally {
            $this->recordMetrics($operation, microtime(true) - $startTime);
        }
    }

    private function handleCreate(array $data): OperationResult 
    {
        $result = $this->repository->create($data);
        $this->cache->invalidateType($result->getResourceType());
        return $result;
    }

    private function handleUpdate(int $id, array $data): OperationResult
    {
        $this->cache->invalidate($id);
        return $this->repository->update($id, $data);
    }

    private function handleDelete(int $id): OperationResult
    {
        $this->cache->invalidate($id);
        return $this->repository->delete($id);
    }

    private function handleRetrieve(int $id): OperationResult
    {
        return $this->cache->remember("data.$id", function() use ($id) {
            return $this->repository->find($id);
        });
    }

    private function invalidateCache(DataOperation $operation): void
    {
        match($operation->getType()) {
            'create' => $this->cache->invalidateType($operation->getResourceType()),
            'update', 'delete' => $this->cache->invalidate($operation->getId()),
            default => null,
        };
    }

    private function handleFailure(DataOperation $operation, \Exception $e): void
    {
        $this->metrics->incrementFailureCount(
            $operation->getType(),
            $e->getCode()
        );

        Log::error('Data operation failed', [
            'operation' => $operation->getType(),
            'resource' => $operation->getResourceType(),
            'id' => $operation->getId(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function recordMetrics(DataOperation $operation, float $executionTime): void
    {
        $this->metrics->record([
            'operation' => $operation->getType(),
            'resource' => $operation->getResourceType(),
            'execution_time' => $executionTime,
            'memory_usage' => memory_get_peak_usage(true)
        ]);
    }

    private function logSuccess(DataOperation $operation, OperationResult $result): void
    {
        Log::info('Data operation successful', [
            'operation' => $operation->getType(),
            'resource' => $operation->getResourceType(),
            'id' => $operation->getId(),
            'result' => $result->toArray()
        ]);
    }
}
