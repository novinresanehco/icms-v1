<?php

namespace App\Core\Transaction;

class TransactionManager implements TransactionInterface
{
    private DatabaseManager $database;
    private SecurityManager $security;
    private ValidationService $validator;
    private StateManager $state;
    private AuditLogger $logger;
    private LockManager $locks;

    public function __construct(
        DatabaseManager $database,
        SecurityManager $security,
        ValidationService $validator,
        StateManager $state,
        AuditLogger $logger,
        LockManager $locks
    ) {
        $this->database = $database;
        $this->security = $security;
        $this->validator = $validator;
        $this->state = $state;
        $this->logger = $logger;
        $this->locks = $locks;
    }

    public function executeTransaction(callable $operation, array $context): TransactionResult
    {
        $transactionId = uniqid('txn_', true);
        $this->state->initializeTransaction($transactionId);
        
        try {
            $this->validateTransactionContext($context);
            $this->acquireRequiredLocks($context);
            
            $this->database->beginTransaction();
            $this->security->validateSecurityContext();
            
            $startState = $this->state->captureState();
            $result = $this->executeWithProtection($operation, $transactionId);
            
            $this->validateTransactionResult($result);
            $this->verifySystemState($startState);
            
            $this->database->commit();
            $this->logTransactionSuccess($transactionId, $result);
            
            return new TransactionResult($result, true);
            
        } catch (\Exception $e) {
            return $this->handleTransactionFailure($transactionId, $e);
        } finally {
            $this->cleanup($transactionId);
        }
    }

    private function validateTransactionContext(array $context): void
    {
        if (!$this->validator->validateContext($context)) {
            throw new InvalidContextException('Invalid transaction context');
        }

        if (!$this->security->validateTransactionSecurity($context)) {
            throw new SecurityException('Transaction security validation failed');
        }
    }

    private function acquireRequiredLocks(array $context): void
    {
        $resources = $context['resources'] ?? [];
        
        foreach ($resources as $resource) {
            if (!$this->locks->acquireLock($resource)) {
                throw new ResourceLockException("Failed to acquire lock: {$resource}");
            }
        }
    }

    private function executeWithProtection(callable $operation, string $transactionId): mixed
    {
        $this->state->setCheckpoint('pre_execution');
        
        try {
            $result = $operation();
            $this->state->setCheckpoint('post_execution');
            
            return $result;
            
        } catch (\Exception $e) {
            $this->state->rollbackToCheckpoint('pre_execution');
            throw $e;
        }
    }

    private function validateTransactionResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Transaction result validation failed');
        }
    }

    private function verifySystemState(array $startState): void
    {
        $currentState = $this->state->captureState();
        
        if (!$this->state->verifyStateTransition($startState, $currentState)) {
            throw new StateTransitionException('Invalid system state transition');
        }
    }

    private function handleTransactionFailure(string $transactionId, \Exception $e): TransactionResult
    {
        $this->database->rollBack();
        $this->state->rollbackTransaction($transactionId);
        
        $this->logTransactionFailure($transactionId, $e);
        
        if ($e instanceof SecurityException) {
            $this->security->handleSecurityIncident($transactionId, $e);
        }
        
        if ($this->isRecoverable($e)) {
            return $this->attemptRecovery($transactionId, $e);
        }
        
        return new TransactionResult(null, false, $e);
    }

    private function cleanup(string $transactionId): void
    {
        try {
            $this->releaseLocks();
            $this->state->cleanupTransaction($transactionId);
            $this->logger->logCleanup($transactionId);
            
        } catch (\Exception $e) {
            $this->logger->logCleanupFailure($transactionId, $e);
        }
    }

    private function releaseLocks(): void
    {
        foreach ($this->locks->getAcquiredLocks() as $resource => $lock) {
            $this->locks->releaseLock($resource);
        }
    }

    private function isRecoverable(\Exception $e): bool
    {
        return !($e instanceof UnrecoverableException) &&
               !($e instanceof SecurityException) &&
               !($e instanceof DataCorruptionException);
    }

    private function attemptRecovery(string $transactionId, \Exception $e): TransactionResult
    {
        try {
            $recoveryPlan = $this->createRecoveryPlan($e);
            $recoveryResult = $this->executeRecovery($recoveryPlan);
            
            $this->logRecoveryAttempt($transactionId, $recoveryResult);
            
            return new TransactionResult(
                $recoveryResult->getData(),
                $recoveryResult->isSuccessful(),
                $recoveryResult->getError()
            );
            
        } catch (\Exception $recoveryError) {
            $this->logRecoveryFailure($transactionId, $recoveryError);
            return new TransactionResult(null, false, $recoveryError);
        }
    }

    private function logTransactionSuccess(string $transactionId, $result): void
    {
        $this->logger->logTransaction([
            'transaction_id' => $transactionId,
            'status' => 'success',
            'timestamp' => now(),
            'result' => $result
        ]);
    }

    private function logTransactionFailure(string $transactionId, \Exception $e): void
    {
        $this->logger->logTransaction([
            'transaction_id' => $transactionId,
            'status' => 'failure',
            'timestamp' => now(),
            'error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ]
        ]);
    }
}
