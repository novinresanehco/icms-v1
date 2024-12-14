<?php

namespace App\Core\ErrorManagement;

class ErrorManagementSystem implements ErrorManagementInterface
{
    private ExceptionHandler $handler;
    private ErrorClassifier $classifier;
    private RecoveryManager $recovery;
    private StateValidator $validator;
    private EmergencyControl $emergency;

    public function __construct(
        ExceptionHandler $handler,
        ErrorClassifier $classifier,
        RecoveryManager $recovery,
        StateValidator $validator,
        EmergencyControl $emergency
    ) {
        $this->handler = $handler;
        $this->classifier = $classifier;
        $this->recovery = $recovery;
        $this->validator = $validator;
        $this->emergency = $emergency;
    }

    public function handleCriticalError(\Throwable $error): ErrorHandlingResult
    {
        $handlingId = $this->initializeErrorHandling();
        DB::beginTransaction();

        try {
            // Classify error
            $classification = $this->classifier->classifyError($error);
            if ($classification->isCatastrophic()) {
                $this->handleCatastrophicError($error, $classification);
            }

            // Create recovery plan
            $recoveryPlan = $this->recovery->createRecoveryPlan(
                $error,
                $classification
            );

            // Execute error handling
            $result = $this->executeErrorHandling(
                $error,
                $recoveryPlan,
                $handlingId
            );

            // Verify system state
            $this->verifySystemState($result);

            $this->logErrorHandling($handlingId, $result);
            DB::commit();

            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleHandlingFailure($handlingId, $error, $e);
            throw new ErrorHandlingException(
                'Error handling failed',
                previous: $e
            );
        }
    }

    private function executeErrorHandling(
        \Throwable $error,
        RecoveryPlan $plan,
        string $handlingId
    ): ErrorHandlingResult {
        // Execute pre-handling procedures
        $this->handler->executePreHandling($error);

        // Execute recovery steps
        $recoveryResult = $this->recovery->executeRecovery($plan);
        if (!$recoveryResult->isSuccessful()) {
            throw new RecoveryException('Error recovery failed');
        }

        // Validate recovery
        $validation = $this->validator->validateRecovery($recoveryResult);
        if (!$validation->isValid()) {
            throw new ValidationException('Recovery validation failed');
        }

        // Execute post-handling procedures
        $this->handler->executePostHandling($recoveryResult);

        return new ErrorHandlingResult(
            success: true,
            handlingId: $handlingId,
            recovery: $recoveryResult,
            classification: $this->classifier->getClassification($error)
        );
    }

    private function handleCatastrophicError(
        \Throwable $error,
        ErrorClassification $classification
    ): void {
        $this->emergency->initiateCatastrophicProtocol([
            'error' => $error,
            'classification' => $classification,
            'timestamp' => now()
        ]);
    }

    private function verifySystemState(ErrorHandlingResult $result): void
    {
        // Verify system integrity
        if (!$this->validator->verifySystemIntegrity()) {
            throw new IntegrityException('System integrity compromised');
        }

        // Verify operational state
        if (!$this->validator->verifyOperationalState()) {
            throw new StateException('System operational state invalid');
        }

        // Verify error containment
        if (!$this->validator->verifyErrorContainment($result)) {
            throw new ContainmentException('Error containment verification failed');
        }
    }

    private function handleHandlingFailure(
        string $handlingId,
        \Throwable $originalError,
        \Exception $handlingError
    ): void {
        Log::emergency('Error handling failure', [
            'handling_id' => $handlingId,
            'original_error' => [
                'message' => $originalError->getMessage(),
                'trace' => $originalError->getTraceAsString()
            ],
            'handling_error' => [
                'message' => $handlingError->getMessage(),
                'trace' => $handlingError->getTraceAsString()
            ]
        ]);

        $this->emergency->handleCriticalFailure(
            $handlingId,
            $originalError,
            $handlingError
        );
    }

    private function logErrorHandling(
        string $handlingId,
        ErrorHandlingResult $result
    ): void {
        Log::critical('Error handled', [
            'handling_id' => $handlingId,
            'result' => $result->toArray(),
            'timestamp' => now()
        ]);
    }

    private function initializeErrorHandling(): string
    {
        return Str::uuid();
    }

    public function getErrorStatus(string $handlingId): ErrorStatus
    {
        try {
            $status = $this->handler->getStatus($handlingId);
            
            if ($status->isUnresolved()) {
                $this->handleUnresolvedError($status);
            }

            return $status;

        } catch (\Exception $e) {
            $this->handleStatusCheckFailure($handlingId, $e);
            throw new StatusException(
                'Error status check failed',
                previous: $e
            );
        }
    }

    private function handleUnresolvedError(ErrorStatus $status): void
    {
        $this->emergency->handleUnresolvedError(
            $status->getHandlingId(),
            $status->getError()
        );
    }

    private function handleStatusCheckFailure(
        string $handlingId,
        \Exception $e
    ): void {
        $this->emergency->handleStatusCheckFailure([
            'handling_id' => $handlingId,
            'error' => $e->getMessage(),
            'timestamp' => now()
        ]);
    }
}
