<?php

namespace App\Core\Transaction;

class TransactionManager implements TransactionInterface
{
    private DBConnection $db;
    private SecurityManager $security;
    private AuditLogger $auditLogger;
    private MetricsCollector $metrics;
    private BackupManager $backup;

    public function __construct(
        DBConnection $db,
        SecurityManager $security,
        AuditLogger $auditLogger,
        MetricsCollector $metrics,
        BackupManager $backup
    ) {
        $this->db = $db;
        $this->security = $security;
        $this->auditLogger = $auditLogger;
        $this->metrics = $metrics;
        $this->backup = $backup;
    }

    public function executeTransaction(callable $operation, SecurityContext $context): mixed
    {
        $transactionId = $this->generateTransactionId();
        $backupId = $this->backup->createBackupPoint();
        $startTime = microtime(true);

        try {
            $this->db->beginTransaction();
            $this->logTransactionStart($transactionId, $context);
            
            $this->validateSystemState();
            $this->securityCheck($context);
            
            $result = $this->executeWithMonitoring($operation, $transactionId);
            
            $this->validateTransactionResult($result);
            $this->verifySystemIntegrity();
            
            $this->db->commit();
            $this->logTransactionSuccess($transactionId, $context);
            
            return $result;

        } catch (SecurityException $e) {
            $this->handleSecurityFailure($e, $transactionId, $context);
            throw $e;
        } catch (ValidationException $e) {
            $this->handleValidationFailure($e, $transactionId, $context);
            throw $e;
        } catch (\Exception $e) {
            $this->handleTransactionFailure($e, $transactionId, $context);
            throw new TransactionException('Transaction failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->recordMetrics($transactionId, microtime(true) - $startTime);
            $this->cleanup($backupId);
        }
    }

    private function executeWithMonitoring(callable $operation, string $transactionId): mixed
    {
        return $this->metrics->trackOperation($transactionId, function() use ($operation) {
            return $operation();
        });
    }

    private function validateSystemState(): void
    {
        if (!$this->security->verifySystemState()) {
            throw new SystemStateException('System state invalid for transaction');
        }
    }

    private function securityCheck(SecurityContext $context): void
    {
        if (!$this->security->validateContext($context)) {
            throw new SecurityException('Security context validation failed');
        }
    }

    private function validateTransactionResult($result): void
    {
        if (!$this->security->validateOperationResult($result)) {
            throw new ValidationException('Transaction result validation failed');
        }
    }

    private function verifySystemIntegrity(): void
    {
        if (!$this->security->verifySystemIntegrity()) {
            throw new IntegrityException('System integrity check failed');
        }
    }

    private function handleSecurityFailure(
        SecurityException $e,
        string $transactionId,
        SecurityContext $context
    ): void {
        $this->db->rollBack();
        $this->auditLogger->logSecurityFailure($e, $transactionId, $context);
        $this->metrics->recordSecurityFailure($transactionId);
        $this->security->handleSecurityIncident($e, $context);
    }

    private function handleValidationFailure(
        ValidationException $e,
        string $transactionId,
        SecurityContext $context
    ): void {
        $this->db->rollBack();
        $this->auditLogger->logValidationFailure($e, $transactionId, $context);
        $this->metrics->recordValidationFailure($transactionId);
    }

    private function handleTransactionFailure(
        \Exception $e,
        string $transactionId,
        SecurityContext $context
    ): void {
        $this->db->rollBack();
        $this->auditLogger->logTransactionFailure($e, $transactionId, $context);
        $this->metrics->recordTransactionFailure($transactionId);
    }

    private function generateTransactionId(): string
    {
        return $this->security->generateSecureIdentifier('transaction');
    }

    private function logTransactionStart(string $transactionId, SecurityContext $context): void
    {
        $this->auditLogger->logTransactionStart([
            'transaction_id' => $transactionId,
            'context' => $context,
            'timestamp' => microtime(true)
        ]);
    }

    private function logTransactionSuccess(string $transactionId, SecurityContext $context): void
    {
        $this->auditLogger->logTransactionSuccess([
            'transaction_id' => $transactionId,
            'context' => $context,
            'timestamp' => microtime(true)
        ]);
    }

    private function recordMetrics(string $transactionId, float $duration): void
    {
        $this->metrics->recordTransactionMetrics([
            'transaction_id' => $transactionId,
            'duration' => $duration,
            'memory_peak' => memory_get_peak_usage(true),
            'timestamp' => microtime(true)
        ]);
    }

    private function cleanup(string $backupId): void
    {
        try {
            $this->backup->cleanupBackupPoint($backupId);
        } catch (\Exception $e) {
            $this->auditLogger->logCleanupFailure($e);
        }
    }
}
