<?php

namespace App\Core\Error;

class ErrorManager implements ErrorManagerInterface
{
    private SecurityManager $security;
    private RecoveryService $recovery;
    private StateManager $state;
    private BackupService $backup;
    private AuditLogger $logger;
    private AlertSystem $alerts;

    public function __construct(
        SecurityManager $security,
        RecoveryService $recovery,
        StateManager $state,
        BackupService $backup,
        AuditLogger $logger,
        AlertSystem $alerts
    ) {
        $this->security = $security;
        $this->recovery = $recovery;
        $this->state = $state;
        $this->backup = $backup;
        $this->logger = $logger;
        $this->alerts = $alerts;
    }

    public function handleCriticalError(\Throwable $error, array $context): void
    {
        $errorId = uniqid('err_', true);
        
        try {
            $this->state->captureSystemState();
            $this->security->validateSecurityState();
            
            $this->logError($errorId, $error, $context);
            $this->analyzeError($error);
            
            if ($this->isRecoverable($error)) {
                $this->initiateRecovery($errorId, $error);
            } else {
                $this->handleUnrecoverableError($errorId, $error);
            }
            
        } catch (\Exception $e) {
            $this->handleCatastrophicFailure($e, $error);
        }
    }

    private function logError(string $errorId, \Throwable $error, array $context): void
    {
        $this->logger->logCriticalError([
            'error_id' => $errorId,
            'type' => get_class($error),
            'message' => $error->getMessage(),
            'code' => $error->getCode(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'trace' => $error->getTraceAsString(),
            'context' => $context,
            'system_state' => $this->state->getCurrentState(),
            'timestamp' => now()
        ]);
    }

    private function analyzeError(\Throwable $error): ErrorAnalysis
    {
        $analysis = new ErrorAnalysis();
        
        $analysis->severity = $this->determineSeverity($error);
        $analysis->impact = $this->assessImpact($error);
        $analysis->recoveryPath = $this->determineRecoveryPath($error);
        
        $this->logger->logErrorAnalysis($analysis);
        
        return $analysis;
    }

    private function determineSeverity(\Throwable $error): string
    {
        if ($error instanceof SecurityException) {
            return 'CRITICAL';
        }
        
        if ($error instanceof DataCorruptionException) {
            return 'SEVERE';
        }
        
        if ($error instanceof ValidationException) {
            return 'MODERATE';
        }
        
        return 'UNKNOWN';
    }

    private function assessImpact(\Throwable $error): array
    {
        return [
            'data_integrity' => $this->checkDataIntegrity($error),
            'system_stability' => $this->checkSystemStability($error),
            'security_status' => $this->checkSecurityStatus($error),
            'service_availability' => $this->checkServiceAvailability($error)
        ];
    }

    private function determineRecoveryPath(\Throwable $error): ?RecoveryPath
    {
        if ($error instanceof DatabaseException) {
            return new DatabaseRecoveryPath($error);
        }
        
        if ($error instanceof FileSystemException) {
            return new FileSystemRecoveryPath($error);
        }
        
        if ($error instanceof CacheException) {
            return new CacheRecoveryPath($error);
        }
        
        return null;
    }

    private function isRecoverable(\Throwable $error): bool
    {
        if ($error instanceof UnrecoverableException) {
            return false;
        }
        
        if ($error instanceof SecurityException && $error->isCritical()) {
            return false;
        }
        
        return $this->recovery->canRecover($error);
    }

    private function initiateRecovery(string $errorId, \Throwable $error): void
    {
        try {
            $this->backup->createRecoveryPoint();
            $this->alerts->notifyRecoveryStart($errorId);
            
            $recoveryPlan = $this->recovery->createRecoveryPlan($error);
            $recoveryResult = $this->recovery->executeRecoveryPlan($recoveryPlan);
            
            if ($recoveryResult->isSuccessful()) {
                $this->handleSuccessfulRecovery($errorId, $recoveryResult);
            } else {
                $this->handleFailedRecovery($errorId, $recoveryResult);
            }
            
        } catch (\Exception $e) {
            $this->handleRecoveryFailure($errorId, $e);
        }
    }

    private function handleSuccessfulRecovery(string $errorId, RecoveryResult $result): void
    {
        $this->logger->logRecoverySuccess([
            'error_id' => $errorId,
            'recovery_steps' => $result->getSteps(),
            'system_state' => $this->state->getCurrentState(),
            'timestamp' => now()
        ]);
        
        $this->alerts->notifyRecoverySuccess($errorId);
    }

    private function handleFailedRecovery(string $errorId, RecoveryResult $result): void
    {
        $this->logger->logRecoveryFailure([
            'error_id' => $errorId,
            'failure_reason' => $result->getFailureReason(),
            'failed_step' => $result->getFailedStep(),
            'system_state' => $this->state->getCurrentState(),
            'timestamp' => now()
        ]);
        
        $this->backup->restoreLastRecoveryPoint();
        $this->alerts->notifyRecoveryFailure($errorId);
    }

    private function handleUnrecoverableError(string $errorId, \Throwable $error): void
    {
        $this->logger->logUnrecoverableError([
            'error_id' => $errorId,
            'error' => $error,
            'system_state' => $this->state->getCurrentState(),
            'timestamp' => now()
        ]);
        
        $this->security->initiateEmergencyProtocols();
        $this->alerts->notifyCriticalError($errorId);
    }

    private function handleCatastrophicFailure(\Exception $e, \Throwable $originalError): void
    {
        $this->logger->logCatastrophicFailure([
            'error_manager_exception' => $e,
            'original_error' => $originalError,
            'system_state' => $this->state->getLastKnownGoodState(),
            'timestamp' => now()
        ]);
        
        $this->security->systemShutdown();
        $this->alerts->notifyCatastrophicFailure();
    }
}
