<?php

namespace App\Core\Critical;

class CriticalOperationManager
{
    private SecurityValidator $validator;
    private TransactionHandler $transaction;
    private AuditManager $audit;
    private BackupService $backup;
    private MetricsCollector $metrics;
    private ErrorHandler $errors;

    public function __construct(
        SecurityValidator $validator,
        TransactionHandler $transaction,
        AuditManager $audit,
        BackupService $backup,
        MetricsCollector $metrics,
        ErrorHandler $errors
    ) {
        $this->validator = $validator;
        $this->transaction = $transaction;
        $this->audit = $audit;
        $this->backup = $backup;
        $this->metrics = $metrics;
        $this->errors = $errors;
    }

    public function execute(CriticalOperation $operation): OperationResult 
    {
        $backupId = $this->backup->createSnapshot();
        $metrics = $this->metrics->start();

        try {
            $this->validator->validateOperation($operation);
            
            return $this->transaction->execute(function() use ($operation) {
                $result = $operation->execute();
                $this->validator->validateResult($result);
                return $result;
            });

        } catch (\Throwable $e) {
            $this->handleFailure($e, $operation, $backupId);
            throw $e;
            
        } finally {
            $this->metrics->end($metrics);
            $this->audit->recordOperation($operation);
        }
    }

    private function handleFailure(
        \Throwable $e, 
        CriticalOperation $operation,
        string $backupId
    ): void {
        $this->errors->handle($e);
        $this->audit->recordFailure($e, $operation);
        $this->backup->restore($backupId);
        $this->metrics->recordFailure($e);
    }
}

abstract class CriticalOperation
{
    private array $validationRules = [];
    private array $requiredPermissions = [];
    private array $securityChecks = [];

    abstract public function execute(): OperationResult;
    abstract public function rollback(): void;
    abstract protected function validate(): void;
}

class TransactionHandler
{
    private DatabaseManager $db;
    private LockManager $locks;

    public function execute(callable $operation)
    {
        $this->db->beginTransaction();
        $this->locks->acquire();

        try {
            $result = $operation();
            $this->db->commit();
            return $result;
            
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
            
        } finally {
            $this->locks->release();
        }
    }
}

class SecurityValidator
{
    private array $securityRules;
    private PermissionChecker $permissions;
    private IntegrityChecker $integrity;

    public function validateOperation(CriticalOperation $operation): void
    {
        if (!$this->permissions->verify($operation->getRequiredPermissions())) {
            throw new SecurityException('Insufficient permissions');
        