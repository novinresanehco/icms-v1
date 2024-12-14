<?php

namespace App\Core\Security;

use App\Core\Contracts\SecurityManagerInterface;
use App\Core\Protection\{ValidationService, AuditService, MonitoringService};
use App\Exceptions\{SecurityException, ValidationException};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SecurityManager implements SecurityManagerInterface 
{
    protected ValidationService $validator;
    protected AuditService $auditor;
    protected MonitoringService $monitor;

    public function __construct(
        ValidationService $validator,
        AuditService $auditor,
        MonitoringService $monitor
    ) {
        $this->validator = $validator;
        $this->auditor = $auditor;
        $this->monitor = $monitor;
    }

    public function validateOperation(array $context): void 
    {
        DB::beginTransaction();
        
        try {
            // Security validation
            $this->validateSecurity($context);
            
            // Business rule validation
            $this->validateBusinessRules($context);
            
            // Log successful validation
            $this->auditor->logValidation($context);
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleValidationFailure($e, $context);
            throw $e;
        }
    }

    public function executeSecureOperation(callable $operation, array $context): mixed
    {
        $monitoringId = $this->monitor->startOperation($context);
        
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation($context);
            
            // Execute with monitoring
            $result = $this->executeWithMonitoring($operation, $monitoringId);
            
            // Validate result
            $this->validateResult($result, $context);
            
            DB::commit();
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, $context, $monitoringId);
            throw $e;
        } finally {
            $this->monitor->stopOperation($monitoringId);
        }
    }

    protected function validateSecurity(array $context): void 
    {
        if (!$this->validator->validateSecurityContext($context)) {
            throw new SecurityException('Security validation failed');
        }
    }

    protected function validateBusinessRules(array $context): void
    {
        if (!$this->validator->validateBusinessRules($context)) {
            throw new ValidationException('Business rule validation failed');
        }
    }

    protected function executeWithMonitoring(callable $operation, string $monitoringId): mixed
    {
        return $this->monitor->track($monitoringId, function() use ($operation) {
            return $operation();
        });
    }

    protected function validateResult($result, array $context): void
    {
        if (!$this->validator->validateResult($result, $context)) {
            throw new ValidationException('Result validation failed');
        }
    }

    protected function handleValidationFailure(\Exception $e, array $context): void
    {
        $this->auditor->logValidationFailure($e, $context);
        Log::error('Validation failed', [
            'exception' => $e->getMessage(),
            'context' => $context,
            'trace' => $e->getTraceAsString()
        ]);
    }

    protected function handleOperationFailure(\Exception $e, array $context, string $monitoringId): void
    {
        $this->auditor->logOperationFailure($e, $context, $monitoringId);
        Log::error('Operation failed', [
            'exception' => $e->getMessage(),
            'context' => $context,
            'monitoring_id' => $monitoringId,
            'trace' => $e->getTraceAsString()
        ]);
    }
}
