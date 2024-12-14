<?php

namespace App\Core\Repository;

use App\Core\Interfaces\{
    CriticalRepositoryInterface,
    ValidationServiceInterface,
    CacheManagerInterface,
    AuditLoggerInterface
};
use App\Core\Models\{
    OperationResult,
    ValidationResult
};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Critical base repository implementing core protection protocols
 */
abstract class CriticalBaseRepository implements CriticalRepositoryInterface
{
    protected Model $model;
    protected CacheManagerInterface $cache;
    protected ValidationServiceInterface $validator;
    protected AuditLoggerInterface $logger;
    protected array $securityRules;

    public function __construct(
        Model $model,
        CacheManagerInterface $cache,
        ValidationServiceInterface $validator,
        AuditLoggerInterface $logger
    ) {
        $this->model = $model;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->logger = $logger;
        $this->securityRules = $this->getSecurityRules();
    }

    /**
     * Executes critical operation with comprehensive protection
     *
     * @throws RepositoryException
     */
    protected function executeCriticalOperation(
        string $operation,
        callable $action,
        array $context = []
    ): OperationResult {
        // Start monitoring
        $operationId = $this->startOperationMonitoring($operation, $context);

        DB::beginTransaction();

        try {
            // Pre-execution validation
            $this->validateOperation($operation, $context);

            // Execute with protection
            $result = $action();

            // Verify result
            $this->verifyResult($result, $context);

            // Commit transaction
            DB::commit();

            // Log success
            $this->logSuccess($operation, $result, $context);

            return new OperationResult(true, $result);

        } catch (\Throwable $e) {
            // Rollback transaction
            DB::rollBack();

            // Handle failure
            $this->handleOperationFailure($e, $operation, $context);

            throw new RepositoryException(
                "Critical operation failed: {$operation}",
                previous: $e
            );
        } finally {
            // Stop monitoring
            $this->stopOperationMonitoring($operationId);
        }
    }

    /**
     * Core protected find operation
     */
    public function find(int $id): ?Model
    {
        return $this->executeCriticalOperation(
            'find',
            fn() => $this->cache->remember(
                $this->getCacheKey('find', $id),
                fn() => $this->model->find($id)
            ),
            ['id' => $id]
        )->getData();
    }

    /**
     * Core protected create operation
     */
    public function create(array $data): Model
    {
        return $this->executeCriticalOperation(
            'create',
            fn() => $this->model->create($this->validateData($data)),
            ['data' => $data]
        )->getData();
    }

    /**
     * Core protected update operation 
     */
    public function update(int $id, array $data): Model
    {
        return $this->executeCriticalOperation(
            'update',
            function() use ($id, $data) {
                $model = $this->model->findOrFail($id);
                $model->update($this->validateData($data));
                return $model->fresh();
            },
            ['id' => $id, 'data' => $data]
        )->getData();
    }

    /**
     * Core protected delete operation
     */
    public function delete(int $id): bool
    {
        return $this->executeCriticalOperation(
            'delete',
            function() use ($id) {
                $model = $this->model->findOrFail($id);
                return $model->delete();
            },
            ['id' => $id]
        )->getData();
    }

    /**
     * Validates operation data against security rules
     */
    protected function validateData(array $data): array
    {
        $validationResult = $this->validator->validate($data, $this->securityRules);

        if (!$validationResult->isValid()) {
            throw new ValidationException(
                'Data validation failed: ' . $validationResult->getErrors()
            );
        }

        return $validationResult->getData();
    }

    /**
     * Verifies operation result
     */
    protected function verifyResult($result, array $context): void
    {
        if (!$this->validator->verifyResult($result, $context)) {
            throw new ValidationException('Result verification failed');
        }
    }

    /**
     * Start operation monitoring
     */
    protected function startOperationMonitoring(string $operation, array $context): string
    {
        return $this->logger->startOperation([
            'operation' => $operation,
            'repository' => static::class,
            'context' => $context
        ]);
    }

    /**
     * Stop operation monitoring
     */
    protected function stopOperationMonitoring(string $operationId): void
    {
        $this->logger->stopOperation($operationId);
    }

    /**
     * Handles operation failure with logging
     */
    protected function handleOperationFailure(
        \Throwable $e,
        string $operation,
        array $context
    ): void {
        $this->logger->logFailure([
            'operation' => $operation,
            'repository' => static::class,
            'context' => $context,
            'exception' => $e
        ]);
    }

    /**
     * Logs successful operation
     */
    protected function logSuccess(string $operation, $result, array $context): void
    {
        $this->logger->logSuccess([
            'operation' => $operation,
            'repository' => static::class,
            'context' => $context,
            'result' => $result
        ]);
    }

    /**
     * Gets cache key for operation
     */
    abstract protected function getCacheKey(string $operation, ...$params): string;

    /**
     * Gets security rules for repository
     */
    abstract protected function getSecurityRules(): array;
}
