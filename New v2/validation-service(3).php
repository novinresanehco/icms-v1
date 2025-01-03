<?php

namespace App\Core\Validation;

use App\Core\Security\SecurityContext;
use App\Core\Exceptions\ValidationException;
use Illuminate\Support\Facades\Log;

/**
 * Core validation service for critical CMS operations
 */
class ValidationService implements ValidationInterface
{
    private SecurityManager $security;
    private MetricsCollector $metrics;
    
    public function __construct(
        SecurityManager $security,
        MetricsCollector $metrics
    ) {
        $this->security = $security;
        $this->metrics = $metrics;
    }

    /**
     * Validates critical operation with comprehensive monitoring
     */
    public function validateCriticalOperation(
        Operation $operation,
        SecurityContext $context
    ): ValidationResult {
        $startTime = microtime(true);
        
        try {
            // Pre-operation validation
            $this->validateOperationRequirements($operation);
            $this->validateSecurityContext($context);
            
            // Business rule validation
            $this->validateBusinessRules($operation);
            
            // Log success and metrics
            $this->logSuccess($operation, $context);
            $this->recordMetrics($operation, $startTime);
            
            return new ValidationResult(true);
            
        } catch (\Exception $e) {
            $this->handleValidationFailure($e, $operation, $context);
            throw $e;
        }
    }

    /**
     * Validates critical data with type safety
     */
    public function validateCriticalData(array $data, array $rules): array 
    {
        foreach ($rules as $field => $rule) {
            if (!isset($data[$field])) {
                throw new ValidationException("Required field missing: {$field}");
            }

            $value = $data[$field];
            
            if (!$this->validateDataType($value, $rule['type'])) {
                throw new ValidationException("Invalid type for field: {$field}");
            }

            if (isset($rule['constraints'])) {
                $this->validateConstraints($value, $rule['constraints'], $field);
            }
        }

        return $data;
    }

    protected function validateOperationRequirements(Operation $operation): void
    {
        $requirements = $operation->getRequirements();
        
        if (!$this->validateDependencies($requirements['dependencies'])) {
            throw new ValidationException('Operation dependencies not met');
        }

        if (!$this->validateResources($requirements['resources'])) {
            throw new ValidationException('Insufficient resources for operation');
        }
    }

    protected function validateSecurityContext(SecurityContext $context): void
    {
        if (!$this->security->validateContext($context)) {
            throw new ValidationException('Invalid security context');
        }
    }

    protected function validateBusinessRules(Operation $operation): void
    {
        $rules = $operation->getBusinessRules();
        
        foreach ($rules as $rule) {
            if (!$this->evaluateBusinessRule($rule, $operation)) {
                throw new ValidationException("Business rule violation: {$rule->getName()}");
            }
        }
    }

    protected function validateDataType($value, string $type): bool
    {
        return match($type) {
            'string' => is_string($value),
            'int' => is_int($value),
            'float' => is_float($value),
            'bool' => is_bool($value),
            'array' => is_array($value),
            default => false
        };
    }

    protected function validateConstraints($value, array $constraints, string $field): void
    {
        foreach ($constraints as $constraint => $params) {
            if (!$this->evaluateConstraint($value, $constraint, $params)) {
                throw new ValidationException("Constraint violation for {$field}: {$constraint}");
            }
        }
    }

    protected function evaluateConstraint($value, string $constraint, $params): bool
    {
        return match($constraint) {
            'min' => is_numeric($value) && $value >= $params,
            'max' => is_numeric($value) && $value <= $params,
            'length' => is_string($value) && strlen($value) <= $params,
            'pattern' => is_string($value) && preg_match($params, $value),
            default => true
        };
    }

    protected function logSuccess(Operation $operation, SecurityContext $context): void
    {
        Log::info('Validation successful', [
            'operation' => get_class($operation),
            'context' => $context->toArray(),
            'timestamp' => microtime(true)
        ]);
    }

    protected function recordMetrics(Operation $operation, float $startTime): void
    {
        $this->metrics->record('validation.time', microtime(true) - $startTime, [
            'operation' => get_class($operation)
        ]);
    }

    protected function handleValidationFailure(
        \Exception $e,
        Operation $operation,
        SecurityContext $context
    ): void {
        Log::error('Validation failed', [
            'exception' => $e->getMessage(),
            'operation' => get_class($operation),
            'context' => $context->toArray(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->metrics->increment('validation.failure', [
            'operation' => get_class($operation),
            'reason' => get_class($e)
        ]);
    }
}
