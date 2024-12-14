<?php

namespace App\Core\Validation;

use App\Core\Exceptions\{ValidationException, SecurityException, ComplianceException};
use App\Core\Interfaces\{ValidationInterface, SecurityInterface, AuditInterface};
use Illuminate\Support\Facades\{DB, Log};

class CriticalValidationFramework implements ValidationInterface 
{
    protected SecurityService $security;
    protected AuditService $audit;
    protected ComplianceService $compliance;
    
    public function __construct(
        SecurityService $security,
        AuditService $audit,
        ComplianceService $compliance
    ) {
        $this->security = $security;
        $this->audit = $audit;
        $this->compliance = $compliance;
    }

    public function validateCriticalOperation(Operation $operation): ValidationResult
    {
        DB::beginTransaction();
        
        try {
            // Architecture compliance check
            $this->validateArchitecture($operation);
            
            // Security protocol validation
            $this->validateSecurity($operation);
            
            // Quality metrics verification
            $this->validateQuality($operation);
            
            // Performance check
            $this->validatePerformance($operation);
            
            DB::commit();
            $this->audit->logSuccess('Validation successful', $operation);
            
            return new ValidationResult(true);
            
        } catch (ValidationException $e) {
            DB::rollBack();
            $this->handleValidationFailure($e, $operation);
            throw $e;
        }
    }

    protected function validateArchitecture(Operation $operation): void
    {
        if (!$this->compliance->verifyArchitecture($operation)) {
            $this->audit->logViolation('Architecture validation failed', $operation);
            throw new ValidationException('Operation violates architectural constraints');
        }
    }

    protected function validateSecurity(Operation $operation): void
    {
        if (!$this->security->validateOperation($operation)) {
            $this->audit->logSecurityIssue('Security validation failed', $operation);
            throw new SecurityException('Operation failed security validation');
        }
    }

    protected function validateQuality(Operation $operation): void
    {
        $qualityResult = $this->compliance->checkQualityMetrics($operation);
        if (!$qualityResult->isValid()) {
            $this->audit->logQualityIssue('Quality check failed', $operation);
            throw new ValidationException('Operation failed quality standards');
        }
    }

    protected function validatePerformance(Operation $operation): void
    {
        $performanceResult = $this->compliance->checkPerformance($operation);
        if (!$performanceResult->meetsThreshold()) {
            $this->audit->logPerformanceIssue('Performance check failed', $operation);
            throw new ValidationException('Operation does not meet performance requirements');
        }
    }

    protected function handleValidationFailure(\Exception $e, Operation $operation): void
    {
        Log::critical('Validation failure', [
            'operation' => $operation->identifier(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->audit->logFailure('Validation failed', $operation, [
            'error' => $e->getMessage(),
            'type' => get_class($e)
        ]);

        // Notify relevant teams based on failure type
        $this->notifyFailure($e, $operation);
    }

    protected function notifyFailure(\Exception $e, Operation $operation): void
    {
        if ($e instanceof SecurityException) {
            $this->security->notifySecurityTeam($e, $operation);
        }
        
        if ($e instanceof ComplianceException) {
            $this->compliance->notifyComplianceTeam($e, $operation);
        }
    }
}
