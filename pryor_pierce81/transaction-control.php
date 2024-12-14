<?php

namespace App\Core\Transaction;

class TransactionController implements TransactionControlInterface
{
    private TransactionManager $manager;
    private IntegrityValidator $validator;
    private RollbackHandler $rollbackHandler;
    private TransactionLogger $logger;
    private EmergencyProtocol $emergency;
    private AlertSystem $alerts;

    public function __construct(
        TransactionManager $manager,
        IntegrityValidator $validator,
        RollbackHandler $rollbackHandler,
        TransactionLogger $logger,
        EmergencyProtocol $emergency,
        AlertSystem $alerts
    ) {
        $this->manager = $manager;
        $this->validator = $validator;
        $this->rollbackHandler = $rollbackHandler;
        $this->logger = $logger;
        $this->emergency = $emergency;
        $this->alerts = $alerts;
    }

    public function executeTransaction(TransactionContext $context): TransactionResult
    {
        $transactionId = $this->initializeTransaction($context);
        
        try {
            $this->validatePreConditions($context);
            DB::beginTransaction();

            $operationResult = $this->executeOperations($context);
            $this->validateOperationResults($operationResult);

            $integrityResult = $this->validateIntegrity($operationResult);
            $this->verifyTransactionState($integrityResult);

            $result = new TransactionResult([
                'transactionId' => $transactionId,
                'operations' => $operationResult,
                'integrity' => $integrityResult,
                'metrics' => $this->collectMetrics(),
                'timestamp' => now()
            ]);

            DB::commit();
            return $result;

        } catch (TransactionException $e) {
            DB::rollBack();
            $this->handleTransactionFailure($e, $transactionId);
            throw new CriticalTransactionException($e->getMessage(), $e);
        }
    }

    private function validatePreConditions(TransactionContext $context): void
    {
        $violations = $this->validator->validatePreConditions($context);
        
        if (!empty($violations)) {
            throw new PreConditionException(
                'Transaction pre-conditions not met',
                ['violations' => $violations]
            );
        }
    }

    private function executeOperations(TransactionContext $context): OperationResult
    {
        $result = $this->manager->executeOperations($context);
        
        if (!$result->isSuccessful()) {
            $this->rollbackHandler->initiateRollback($result);
            throw new OperationException('Transaction operations failed');
        }
        
        return $result;
    }

    private function validateIntegrity(OperationResult $result): IntegrityResult
    {
        $integrityResult = $this->validator->validateIntegrity($result);
        
        if (!$integrityResult->isPassed()) {
            $this->emergency->handleIntegrityViolation($integrityResult);
            throw new IntegrityException('Transaction integrity validation failed');
        }
        
        return $integrityResult;
    }

    private function handleTransactionFailure(
        TransactionException $e,
        string $transactionId
    ): void {
        $this->logger->logFailure($e, $transactionId);
        
        if ($e->isCritical()) {
            $this->emergency->handleCriticalFailure($e);
            $this->alerts->dispatchCriticalAlert(
                new TransactionFailureAlert($e, $transactionId)
            );
        }
    }
}
