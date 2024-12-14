<?php

namespace App\Core\Validation;

use App\Core\Security\SecurityContext;
use App\Core\Monitoring\PerformanceMonitor;
use App\Core\Audit\AuditLogger;

class ValidationService implements ValidationInterface
{
    private PerformanceMonitor $monitor;
    private AuditLogger $audit;
    private array $validationRules;

    public function __construct(
        PerformanceMonitor $monitor,
        AuditLogger $audit,
        array $validationRules
    ) {
        $this->monitor = $monitor;
        $this->audit = $audit;
        $this->validationRules = $validationRules;
    }

    public function validateSecurityOperation(
        SecurityOperation $operation,
        SecurityContext $context
    ): ValidationResult {
        DB::beginTransaction();
        
        try {
            // Input validation
            $this->validateInputData($operation->getData());
            
            // Security context validation
            $this->validateSecurityContext($context);
            
            // Business rules validation
            $this->validateBusinessRules($operation);
            
            // System state validation
            $this->validateSystemState();
            
            DB::commit();
            
            return new ValidationResult(true);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleValidationFailure($e, $operation);
            throw $e;
        }
    }

    public function validateContentOperation(
        ContentOperation $operation
    ): ValidationResult {
        $monitoringId = $this->monitor->startValidation();
        
        try {
            // Content validation
            $this->validateContent($operation->getContent());
            
            // Metadata validation
            $this->validateMetadata($operation->getMetadata());
            
            // Permission validation
            $this->validatePermissions($operation->getPermissions());
            
            $this->monitor->endValidation($monitoringId, true);
            return new ValidationResult(true);
            
        } catch (\Exception $e) {
            $this->monitor->endValidation($monitoringId, false);
            $this->handleValidationFailure($e, $operation);
            throw $e;
        }
    }

    public function validateSystemState(): ValidationResult
    {
        // Resource validation
        $this->validateResourceUsage();
        
        // Performance validation
        $this->validatePerformanceMetrics();
        
        // Security validation
        $this->validateSecurityState();
        
        return new ValidationResult(true);
    }

    private function validateInputData(array $data): void
    {
        foreach ($this->validationRules as $field => $rules) {
            if (!$this->validateField($data[$field], $rules)) {
                throw new ValidationException("Invalid field: {$field}");
            }
        }
    }

    private function validateSecurityContext(SecurityContext $context): void
    {
        if (!$context->isValid()) {
            throw new SecurityValidationException('Invalid security context');
        }

        if (!$context->hasRequiredPermissions()) {
            throw new SecurityValidationException('Insufficient permissions');
        }
    }

    private function validateBusinessRules(Operation $operation): void
    {
        if (!$this->validateOperationRules($operation)) {
            throw new ValidationException('Business rule validation failed');
        }
    }

    private function validateResourceUsage(): void
    {
        $usage = $this->monitor->getResourceUsage();
        
        if ($usage->exceedsThresholds()) {
            throw new SystemValidationException('Resource usage exceeds limits');
        }
    }

    private function validatePerformanceMetrics(): void
    {
        $metrics = $this->monitor->getPerformanceMetrics();
        
        if ($metrics->belowThresholds()) {
            throw new SystemValidationException('Performance below required levels');
        }
    }

    private function validateSecurityState(): void
    {
        $state = $this->monitor->getSecurityState();
        
        if (!$state->isSecure()) {
            throw new SecurityStateException('System security state invalid');
        }
    }

    private function validateField($value, array $rules): bool
    {
        foreach ($rules as $rule) {
            if (!$this->evaluateRule($value, $rule)) {
                return false;
            }
        }
        return true;
    }

    private function evaluateRule($value, ValidationRule $rule): bool
    {
        return $rule->validate($value);
    }

    private function handleValidationFailure(\Exception $e, Operation $operation): void
    {
        $this->audit->logValidationFailure($e, [
            'operation' => $operation->getId(),
            'timestamp' => now(),
            'context' => $operation->getContext()
        ]);
    }
}
