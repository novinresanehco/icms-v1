<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManagerInterface;
use App\Core\Logging\AuditLoggerInterface;
use App\Core\Validation\ValidationInterface;

abstract class BaseCmsService implements CmsServiceInterface
{
    protected SecurityManagerInterface $security;
    protected CacheManagerInterface $cache;
    protected AuditLoggerInterface $logger;
    protected ValidationInterface $validator;

    public function __construct(
        SecurityManagerInterface $security,
        CacheManagerInterface $cache,
        AuditLoggerInterface $logger,
        ValidationInterface $validator
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->logger = $logger;
        $this->validator = $validator;
    }

    protected function executeCriticalOperation(
        string $operation,
        array $data,
        array $context
    ): OperationResult {
        $operationId = uniqid('cms_op_', true);

        try {
            // Pre-execution validation
            $this->validateOperation($operation, $data, $context);
            $this->security->validateContext($context);

            // Execute operation with monitoring
            $result = $this->executeWithMonitoring(
                $operation,
                $data,
                $context,
                $operationId
            );

            // Verify result
            $this->verifyResult($result, $context);

            // Log success
            $this->logSuccess($operation, $result, $context);

            return new OperationResult(true, $result);

        } catch (\Throwable $e) {
            $this->handleOperationFailure($e, $operation, $context, $operationId);
            throw new CmsOperationException(
                "CMS operation failed: {$operation}",
                previous: $e
            );
        }
    }

    protected function validateOperation(
        string $operation,
        array $data,
        array $context
    ): void {
        // Validate operation type
        if (!$this->validator->validateOperation($operation)) {
            throw new ValidationException('Invalid operation type');
        }

        // Validate input data
        if (!$this->validator->validateData($data, $this->getValidationRules())) {
            throw new ValidationException('Invalid operation data');
        }

        // Validate operation context
        if (!$this->validator->validateContext($context)) {
            throw new ValidationException('Invalid operation context');
        }
    }

    protected function executeWithMonitoring(
        string $operation,
        array $data,
        array $context,
        string $operationId
    ) {
        // Start performance monitoring
        $this->logger->startOperation($operationId, [
            'operation' => $operation,
            'context' => $context
        ]);

        try {
            // Execute core operation
            $result = $this->executeCoreOperation($operation, $data, $context);

            // Handle caching
            $this->handleCaching($operation, $result, $context);

            return $result;

        } finally {
            // End monitoring
            $this->logger->endOperation($operationId);
        }
    }

    protected function executeCoreOperation(
        string $operation,
        array $data,
        array $context
    ) {
        switch ($operation) {
            case 'create':
                return $this->executeCreate($data, $context);
            case 'update':
                return $this->executeUpdate($data, $context);
            case 'delete':
                return $this->executeDelete($data, $context);
            default:
                throw new CmsOperationException("Unsupported operation: {$operation}");
        }
    }

    protected function verifyResult($result, array $context): void
    {
        if (!$this->validator->verifyResult($result, $context)) {
            throw new ValidationException('Result verification failed');
        }
    }

    protected function handleCaching(
        string $operation,
        $result,
        array $context
    ): void {
        $cacheKey = $this->generateCacheKey($operation, $context);

        if ($operation === 'create' || $operation === 'update') {
            $this->cache->set($cacheKey, $result);
        } elseif ($operation === 'delete') {
            $this->cache->delete($cacheKey);
        }
    }

    protected function logSuccess(
        string $operation,
        $result,
        array $context
    ): void {
        $this->logger->logSuccess([
            'type' => 'cms_operation',
            'operation' => $operation,
            'context' => $context,
            'result' => $result
        ]);
    }

    protected function handleOperationFailure(
        \Throwable $e,
        string $operation,
        array $context,
        string $operationId
    ): void {
        $this->logger->logFailure([
            'type' => 'cms_operation_failure',
            'operation' => $operation,
            'context' => $context,
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        if ($this->isCriticalFailure($e)) {
            $this->handleCriticalFailure($e, $operation, $context);
        }
    }

    protected function isCriticalFailure(\Throwable $e): bool
    {
        return $e instanceof SecurityException ||
               $e instanceof DataCorruptionException ||
               $e instanceof SystemFailureException;
    }

    protected function handleCriticalFailure(
        \Throwable $e,
        string $operation,
        array $context
    ): void {
        $this->logger->logCritical([
            'type' => 'critical_cms_failure',
            'operation' => $operation,
            'context' => $context,
            'error' => $e->getMessage(),
            'severity' => 'CRITICAL'
        ]);

        $this->notifyAdministrators($e, $operation, $context);
    }

    abstract protected function getValidationRules(): array;
    abstract protected function generateCacheKey(string $operation, array $context): string;
    abstract protected function executeCreate(array $data, array $context);
    abstract protected function executeUpdate(array $data, array $context);
    abstract protected function executeDelete(array $data, array $context);
    abstract protected function notifyAdministrators(\Throwable $e, string $operation, array $context): void;
}
