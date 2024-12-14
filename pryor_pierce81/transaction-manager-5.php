<?php

namespace App\Core\Transaction;

class TransactionManager implements TransactionInterface 
{
    private StateTracker $stateTracker;
    private IntegrityValidator $integrityValidator;
    private CompensationManager $compensationManager;
    private TransactionLogger $logger;
    private MetricsCollector $metrics;
    private AlertDispatcher $alerts;

    public function __construct(
        StateTracker $stateTracker,
        IntegrityValidator $integrityValidator,
        CompensationManager $compensationManager,
        TransactionLogger $logger,
        MetricsCollector $metrics,
        AlertDispatcher $alerts
    ) {
        $this->stateTracker = $stateTracker;
        $this->integrityValidator = $integrityValidator;
        $this->compensationManager = $compensationManager;
        $this->logger = $logger;
        $this->metrics = $metrics;
        $this->alerts = $alerts;
    }

    public function executeTransaction(TransactionRequest $request): TransactionResult 
    {
        $transactionId = $this->initializeTransaction($request);
        
        try {
            DB::beginTransaction();
            
            $this->validateRequest($request);
            $initialState = $this->stateTracker->captureState();
            
            $result = $this->processTransaction($request);
            
            $this->verifyTransactionIntegrity($initialState, $result);
            
            DB::commit();
            $this->finalizeTransaction($transactionId, $result);
            
            return $result;

        } catch (TransactionException $e) {
            DB::rollBack();
            $this->handleTransactionFailure($e, $transactionId);
            throw new CriticalTransactionException($e->getMessage(), $e);
        }
    }

    private function validateRequest(TransactionRequest $request): void
    {
        if (!$this->integrityValidator->validateRequest($request)) {
            throw new ValidationException('Transaction request validation failed');
        }
    }

    private function verifyTransactionIntegrity(
        SystemState $initialState,
        TransactionResult $result
    ): void {
        $finalState = $this->stateTracker->captureState();
        
        if (!$this->integrityValidator->verifyStateTransition($initialState, $finalState)) {
            $this->compensationManager->compensate($result);
            throw new IntegrityException('Transaction integrity verification failed');
        }
    }

    private function handleTransactionFailure(
        TransactionException $e,
        string $transactionId
    ): void {
        $this->logger->logFailure($e, $transactionId);
        
        $this->alerts->dispatch(
            new TransactionAlert(
                'Critical transaction failure',
                [
                    'transaction_id' => $transactionId,
                    'exception' => $e
                ]
            )
        );
        
        $this->metrics->recordFailure('transaction', [
            'transaction_id' => $transactionId,
            'error' => $e->getMessage()
        ]);
    }
}
