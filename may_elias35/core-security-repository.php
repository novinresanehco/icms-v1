<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use App\Core\Interfaces\SecurityInterface;
use App\Core\Exceptions\SecurityException;

class CoreSecurityManager implements SecurityInterface 
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $logger;

    public function __construct(
        ValidationService $validator, 
        EncryptionService $encryption,
        AuditLogger $logger
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->logger = $logger;
    }

    public function executeCriticalOperation(callable $operation, array $context): mixed
    {
        // Pre-execution validation
        $this->validateContext($context);
        
        // Start transaction
        DB::beginTransaction();
        
        try {
            // Execute with monitoring
            $startTime = microtime(true);
            $result = $this->monitorExecution($operation);
            
            // Validate result
            $this->validateResult($result);
            
            // Log and commit
            $this->logger->logSuccess([
                'operation' => get_class($operation),
                'duration' => microtime(true) - $startTime,
                'context' => $context
            ]);
            
            DB::commit();
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw new SecurityException(
                'Operation failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    protected function validateContext(array $context): void 
    {
        if (!$this->validator->validate($context)) {
            throw new SecurityException('Invalid operation context');
        }
    }

    protected function monitorExecution(callable $operation): mixed
    {
        $result = $operation();
        
        if ($result === null) {
            throw new SecurityException('Operation returned null result');
        }
        
        return $result;
    }

    protected function validateResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new SecurityException('Result validation failed');
        }
    }

    protected function handleFailure(\Exception $e, array $context): void
    {
        $this->logger->logFailure([
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'context' => $context
        ]);
    }
}

abstract class CoreRepository
{
    protected $model;
    protected CoreSecurityManager $security;
    protected CacheManager $cache;

    public function find(int $id): ?Model
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->findWithCache($id),
            ['operation' => 'find', 'id' => $id]
        );
    }

    public function create(array $data): Model
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->model->create($this->validate($data)),
            ['operation' => 'create', 'data' => $data]
        );
    }

    public function update(int $id, array $data): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->model->findOrFail($id)->update($this->validate($data)),
            ['operation' => 'update', 'id' => $id, 'data' => $data]
        );
    }

    public function delete(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->model->findOrFail($id)->delete(),
            ['operation' => 'delete', 'id' => $id]
        );
    }

    protected function findWithCache(int $id): ?Model
    {
        $key = $this->getCacheKey($id);
        
        return $this->cache->remember($key, 3600, function() use ($id) {
            return $this->model->find($id);
        });
    }

    protected function validate(array $data): array
    {
        $validator = Validator::make($data, $this->rules());
        
        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }
        
        return $validator->validated();
    }

    abstract protected function rules(): array;
    
    abstract protected function getCacheKey(int $id): string;
}
