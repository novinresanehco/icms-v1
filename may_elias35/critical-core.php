<?php

namespace App\Core;

use App\Core\Interfaces\{
    SecurityManagerInterface,
    ValidationInterface,
    MonitoringInterface
};

final class CriticalOperationManager
{
    private SecurityManagerInterface $security;
    private ValidationInterface $validator;
    private MonitoringInterface $monitor;
    private TransactionManager $transaction;
    private AuditLogger $audit;

    private const CRITICAL_THRESHOLDS = [
        'response_time' => 200, // milliseconds
        'memory_limit' => 128, // MB
        'cpu_threshold' => 70, // percentage
        'error_tolerance' => 0
    ];

    public function __construct(
        SecurityManagerInterface $security,
        ValidationInterface $validator,
        MonitoringInterface $monitor,
        TransactionManager $transaction,
        AuditLogger $audit
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->monitor = $monitor;
        $this->transaction = $transaction;
        $this->audit = $audit;
    }

    public function executeOperation(CriticalOperation $operation): OperationResult
    {
        $operationId = $this->monitor->initializeOperation();
        
        try {
            $this->transaction->begin();
            
            $this->preExecutionValidation($operation);
            $result = $this->executeWithProtection($operation);
            $this->postExecutionValidation($result);
            
            $this->transaction->commit();
            $this->audit->logSuccess($operationId);
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            $this->handleFailure($e, $operationId);
            throw $e;
        } finally {
            $this->monitor->finalizeOperation($operationId);
        }
    }

    private function preExecutionValidation(CriticalOperation $operation): void
    {
        if (!$this->validator->validateOperation($operation)) {
            throw new ValidationException('Pre-execution validation failed');
        }

        if (!$this->security->verifyAccess($operation)) {
            throw new SecurityException('Security verification failed');
        }

        if (!$this->monitor->checkThresholds(self::CRITICAL_THRESHOLDS)) {
            throw new SystemException('System thresholds exceeded');
        }
    }

    private function executeWithProtection(CriticalOperation $operation): OperationResult
    {
        return $this->monitor->trackExecution(function() use ($operation) {
            return $operation->execute();
        });
    }

    private function postExecutionValidation(OperationResult $result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Result validation failed');
        }

        if (!$this->security->verifyIntegrity($result)) {
            throw new SecurityException('Result integrity check failed');
        }
    }

    private function handleFailure(\Throwable $e, string $operationId): void
    {
        $this->audit->logFailure($operationId, [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'metrics' => $this->monitor->getOperationMetrics($operationId)
        ]);

        $this->monitor->recordFailure($operationId);
        $this->security->handleSecurityFailure($e);
    }
}

abstract class CriticalOperation
{
    protected ValidationInterface $validator;
    protected SecurityContext $context;
    protected array $data;

    public function __construct(array $data, SecurityContext $context)
    {
        $this->data = $data;
        $this->context = $context;
        $this->validator = app(ValidationInterface::class);
    }

    abstract public function execute(): OperationResult;
    abstract public function getValidationRules(): array;
    abstract public function getRequiredPermissions(): array;
}

final class SecurityContext
{
    private string $userId;
    private array $permissions;
    private array $metadata;

    public function __construct(string $userId, array $permissions, array $metadata = [])
    {
        $this->userId = $userId;
        $this->permissions = $permissions;
        $this->metadata = $metadata;
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions);
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getMetadata(string $key = null)
    {
        if ($key === null) {
            return $this->metadata;
        }
        return $this->metadata[$key] ?? null;
    }
}

final class OperationResult
{
    private $data;
    private array $metadata;
    private bool $success;

    public function __construct($data, bool $success = true, array $metadata = [])
    {
        $this->data = $data;
        $this->success = $success;
        $this->metadata = $metadata;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getData()
    {
        return $this->data;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}

interface SecurityManagerInterface
{
    public function verifyAccess(CriticalOperation $operation): bool;
    public function verifyIntegrity(OperationResult $result): bool;
    public function handleSecurityFailure(\Throwable $e): void;
}

interface ValidationInterface
{
    public function validateOperation(CriticalOperation $operation): bool;
    public function validateResult(OperationResult $result): bool;
}

interface MonitoringInterface
{
    public function initializeOperation(): string;
    public function finalizeOperation(string $operationId): void;
    public function trackExecution(callable $operation);
    public function checkThresholds(array $thresholds): bool;
    public function getOperationMetrics(string $operationId): array;
    public function recordFailure(string $operationId): void;
}
