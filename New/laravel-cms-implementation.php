<?php

namespace App\Core;

/**
 * Security manager handling core security operations with comprehensive monitoring
 */
class SecurityManager implements SecurityInterface
{
    protected ValidationService $validator;
    protected EncryptionService $encryption; 
    protected AuditLogger $auditLogger;
    protected MetricsCollector $metrics;
    
    public function validateOperation(Operation $operation, Context $context): ValidationResult
    {
        DB::beginTransaction();
        try {
            // Pre-execution validation
            $this->validator->validateContext($context);
            $this->validator->validateOperation($operation);
            
            // Execute with monitoring
            $result = $this->executeWithMonitoring($operation);
            
            // Verify result
            $this->validator->validateResult($result);
            
            DB::commit();
            return new ValidationResult(true);
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw $e;
        }
    }
    
    protected function executeWithMonitoring(Operation $operation): OperationResult 
    {
        $startTime = microtime(true);
        try {
            $result = $operation->execute();
            $this->metrics->recordSuccess($operation, microtime(true) - $startTime);
            return $result;
        } catch (\Exception $e) {
            $this->metrics->recordFailure($operation, $e);
            throw $e;
        }
    }
}

/**
 * Base repository with caching and validation
 */
class BaseRepository implements RepositoryInterface 
{
    protected Model $model;
    protected CacheManager $cache;
    protected ValidationService $validator;

    public function find(int $id): ?Model
    {
        return $this->cache->remember(
            $this->getCacheKey('find', $id),
            fn() => $this->model->find($id)
        );
    }
    
    public function store(array $data): Model
    {
        $validated = $this->validator->validate($data);
        return DB::transaction(function () use ($validated) {
            $model = $this->model->create($validated);
            $this->cache->invalidate($this->getCacheKey('find', $model->id));
            return $model;
        });
    }
}

/**
 * Critical operation base class with monitoring
 */
abstract class CriticalOperation
{
    protected SecurityManager $security;
    protected AuditLogger $logger;

    public function execute(Context $context): OperationResult
    {
        try {
            $this->logger->logOperation($this, $context);
            $result = $this->security->validateOperation($this, $context);
            $this->logger->logSuccess($this, $context, $result);
            return $result;
        } catch (\Exception $e) {
            $this->logger->logFailure($this, $context, $e);
            throw $e;
        }
    }

    abstract protected function executeOperation(Context $context): OperationResult;
}
