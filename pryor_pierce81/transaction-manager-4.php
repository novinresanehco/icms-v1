<?php

namespace App\Core\Database;

use Illuminate\Support\Facades\DB;
use App\Core\Interfaces\TransactionManagerInterface;
use App\Core\Services\{
    ValidationService,
    AuditService,
    MonitoringService
};

class CriticalTransactionManager implements TransactionManagerInterface
{
    private ValidationService $validator;
    private AuditService $auditor;
    private MonitoringService $monitor;

    public function __construct(
        ValidationService $validator,
        AuditService $auditor,
        MonitoringService $monitor
    ) {
        $this->validator = $validator;
        $this->auditor = $auditor;
        $this->monitor = $monitor;
    }

    public function executeTransaction(callable $operation, array $context): mixed
    {
        $monitoringId = $this->monitor->startTransaction($context);
        
        try {
            $this->validateContext($context);
            DB::beginTransaction();
            
            $result = $this->executeWithProtection($operation, $monitoringId);
            
            $this->validateResult($result);
            DB::commit();
            
            $this->auditor->logTransaction($context, $result);
            return $result;

        } catch (\Exception $e) {
            $this->handleTransactionFailure($e, $context);
            throw $e;
        } finally {
            $this->monitor->stopTransaction($monitoringId);
        }
    }

    private function validateContext(array $context): void
    {
        if (!$this->validator->validateTransactionContext($context)) {
            throw new ValidationException('Invalid transaction context');
        }
    }

    private function executeWithProtection(callable $operation, string $monitoringId): mixed
    {
        return $this->monitor->trackTransaction($monitoringId, function() use ($operation) {
            $result = $operation();
            
            if ($result === null) {
                throw new TransactionException('Operation returned null result');
            }
            
            return $result;
        });
    }

    private function validateResult($result): void
    {
        if (!$this->validator->validateTransactionResult($result)) {
            DB::rollBack();
            throw new ValidationException('Transaction result validation failed');
        }
    }

    private function handleTransactionFailure(\Exception $e, array $context): void
    {
        DB::rollBack();

        $this->auditor->logTransactionFailure($e, $context);

        if ($this->isSystemCritical($e)) {
            $this->executeEmergencyProtocol($e, $context);
        }
    }

    private function isSystemCritical(\Exception $e): bool
    {
        return $e instanceof SystemCriticalException || 
               $e instanceof DatabaseException ||
               $e instanceof IntegrityException;
    }

    private function executeEmergencyProtocol(\Exception $e, array $context): void
    {
        try {
            // Implement emergency procedures
            // Should be customized based on system requirements
        } catch (\Exception $emergencyError) {
            Log::emergency('Emergency protocol failed', [
                'original_error' => $e->getMessage(),
                'emergency_error' => $emergencyError->getMessage(),
                'context' => $context
            ]);
        }
    }
}
