<?php

namespace App\Core\Service;

use Illuminate\Support\Facades\DB;
use App\Core\Interfaces\{
    RepositoryInterface,
    ValidationInterface,
    SecurityInterface,
    CacheInterface,
    EventInterface
};
use App\Core\Exceptions\{
    ValidationException,
    SecurityException,
    ServiceException
};
use App\Core\Support\Result;

abstract class BaseService
{
    protected RepositoryInterface $repository;
    protected ValidationInterface $validator;
    protected SecurityInterface $security;
    protected CacheInterface $cache;
    protected EventInterface $events;
    protected array $cacheKeys = [];

    public function __construct(
        RepositoryInterface $repository,
        ValidationInterface $validator,
        SecurityInterface $security,
        CacheInterface $cache,
        EventInterface $events
    ) {
        $this->repository = $repository;
        $this->validator = $validator;
        $this->security = $security;
        $this->cache = $cache;
        $this->events = $events;
    }

    protected function executeOperation(string $operation, array $data, array $context = []): Result
    {
        DB::beginTransaction();
        $startTime = microtime(true);

        try {
            // Pre-execution hooks
            $this->beforeOperation($operation, $data, $context);
            
            // Validate operation data
            $validated = $this->validateOperation($operation, $data);
            
            // Security checks
            $this->verifyPermissions($operation, $context);
            
            // Execute core operation
            $result = $this->processOperation($operation, $validated, $context);
            
            // Verify result
            $this->verifyResult($operation, $result);
            
            // Post-execution processing
            $this->afterOperation($operation, $result, $context);
            
            // Clear relevant cache
            $this->clearOperationCache($operation);
            
            // Commit transaction
            DB::commit();
            
            // Log success
            $this->logSuccess($operation, $data, $result, $startTime);
            
            return new Result(true, $result);
            
        } catch (ValidationException | SecurityException $e) {
            DB::rollBack();
            $this->logFailure($operation, $e, $data, $startTime);
            throw $e;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logFailure($operation, $e, $data, $startTime);
            throw new ServiceException(
                "Service operation '{$operation}' failed: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    protected function validateOperation(string $operation, array $data): array
    {
        $rules = $this->getValidationRules($operation);
        return $this->validator->validate($data, $rules);
    }

    protected function verifyPermissions(string $operation, array $context): void
    {
        $permissions = $this->getRequiredPermissions($operation);
        
        if (!$this->security->checkPermissions($permissions, $context)) {
            throw new SecurityException("Insufficient permissions for operation: {$operation}");
        }
    }

    protected function verifyResult(string $operation, $result): void
    {
        if (!$this->isValidResult($operation, $result)) {
            throw new ServiceException("Invalid result for operation: {$operation}");
        }
    }

    protected function clearOperationCache(string $operation): void
    {
        if (isset($this->cacheKeys[$operation])) {
            $this->cache->invalidate($this->cacheKeys[$operation]);
        }
    }

    protected function logSuccess(string $operation, array $data, $result, float $startTime): void
    {
        $this->events->dispatch("service.{$operation}.success", [
            'operation' => $operation,
            'data' => $data,
            'result' => $result,
            'execution_time' => microtime(true) - $startTime
        ]);
    }

    protected function logFailure(string $operation, \Exception $e, array $data, float $startTime): void
    {
        $this->events->dispatch("service.{$operation}.failure", [
            'operation' => $operation,
            'data' => $data,
            'exception' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ],
            'execution_time' => microtime(true) - $startTime
        ]);
    }

    protected function beforeOperation(string $operation, array $data, array $context): void
    {
        $this->events->dispatch("service.{$operation}.before", [
            'operation' => $operation,
            'data' => $data,
            'context' => $context
        ]);
    }

    protected function afterOperation(string $operation, $result, array $context): void
    {
        $this->events->dispatch("service.{$operation}.after", [
            'operation' => $operation,
            'result' => $result,
            'context' => $context
        ]);
    }

    abstract protected function processOperation(string $operation, array $data, array $context);
    
    abstract protected function getValidationRules(string $operation): array;
    
    abstract protected function getRequiredPermissions(string $operation): array;
    
    abstract protected function isValidResult(string $operation, $result): bool;
}
