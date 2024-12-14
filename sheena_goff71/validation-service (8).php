<?php

namespace App\Core\Validation;

use Illuminate\Support\Facades\Log;
use App\Core\Security\SecurityManagerInterface;
use App\Core\Monitoring\MonitoringServiceInterface;
use App\Exceptions\ValidationException;
use App\Exceptions\SecurityViolationException;

class ValidationService implements ValidationServiceInterface
{
    private SecurityManagerInterface $security;
    private MonitoringServiceInterface $monitor;
    private array $validationRules;
    private array $securityConstraints;

    public function __construct(
        SecurityManagerInterface $security,
        MonitoringServiceInterface $monitor,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->validationRules = $config['validation_rules'];
        $this->securityConstraints = $config['security_constraints'];
    }

    /**
     * Validate critical operation with complete security checks
     */
    public function validateOperation(string $operationType, array $data, array $context): void
    {
        $operationId = $this->monitor->startOperation('validation');

        try {
            // Security validation
            $this->validateSecurity($operationType, $data, $context);

            // Data validation
            $this->validateData($operationType, $data);

            // Business rules validation
            $this->validateBusinessRules($operationType, $data, $context);

            // Resource constraints validation
            $this->validateResourceConstraints($operationType, $data);

            // Log successful validation
            $this->logValidation($operationId, $operationType, true);

        } catch (\Exception $e) {
            // Log validation failure
            $this->logValidation($operationId, $operationType, false, $e);
            throw $e;
        } finally {
            $this->monitor->stopOperation($operationId);
        }
    }

    /**
     * Validate data integrity with security checks
     */
    public function validateData(string $operationType, array $data): void
    {
        if (!isset($this->validationRules[$operationType])) {
            throw new ValidationException("No validation rules defined for operation: $operationType");
        }

        $rules = $this->validationRules[$operationType];

        foreach ($rules as $field => $fieldRules) {
            if (!$this->validateField($data[$field] ?? null, $fieldRules)) {
                throw new ValidationException("Validation failed for field: $field");
            }
        }

        // Validate data relationships and integrity
        $this->validateDataIntegrity($data, $rules);
    }

    /**
     * Validate security constraints with zero-tolerance
     */
    private function validateSecurity(string $operationType, array $data, array $context): void
    {
        // Verify operation permissions
        if (!$this->security->hasPermission($context['user'], $operationType)) {
            throw new SecurityViolationException('Insufficient permissions for operation');
        }

        // Verify security constraints
        foreach ($this->securityConstraints[$operationType] ?? [] as $constraint) {
            if (!$this->validateSecurityConstraint($constraint, $data, $context)) {
                throw new SecurityViolationException("Security constraint violation: $constraint");
            }
        }

        // Validate input sanitization
        $this->validateInputSecurity($data);
    }

    /**
     * Validate business rules with context
     */
    private function validateBusinessRules(string $operationType, array $data, array $context): void
    {
        $rules = $this->getBusinessRules($operationType);

        foreach ($rules as $rule) {
            if (!$this->evaluateBusinessRule($rule, $data, $context)) {
                throw new ValidationException("Business rule violation: {$rule['message']}");
            }
        }
    }

    /**
     * Validate resource constraints
     */
    private function validateResourceConstraints(string $operationType, array $data): void
    {
        // Check data size limits
        if ($this->calculateDataSize($data) > $this->validationRules['max_data_size']) {
            throw new ValidationException('Data size exceeds maximum limit');
        }

        // Check resource impact
        $impact = $this->calculateResourceImpact($operationType, $data);
        if ($impact > $this->validationRules['max_resource_impact']) {
            throw new ValidationException('Operation exceeds resource impact limits');
        }
    }

    private function validateField($value, array $rules): bool
    {
        foreach ($rules as $rule => $parameters) {
            if (!$this->evaluateRule($rule, $value, $parameters)) {
                $this->monitor->recordMetric('validation.failure', [
                    'rule' => $rule,
                    'value' => $value
                ]);
                return false;
            }
        }
        return true;
    }

    private function validateDataIntegrity(array $data, array $rules): void
    {
        // Check referential integrity
        $this->checkReferentialIntegrity($data);

        // Validate data consistency
        $this->validateDataConsistency($data);

        // Check for data corruption
        if (!$this->verifyDataChecksum($data)) {
            throw new ValidationException('Data integrity check failed');
        }
    }

    private function validateInputSecurity(array $data): void
    {
        foreach ($data as $key => $value) {
            // Check for injection attempts
            if ($this->detectInjectionPattern($value)) {
                throw new SecurityViolationException('Potential injection detected');
            }

            // Validate input encoding
            if (!$this->validateEncoding($value)) {
                throw new ValidationException('Invalid input encoding detected');
            }

            // Check for malicious patterns
            if ($this->detectMaliciousPattern($value)) {
                throw new SecurityViolationException('Malicious input pattern detected');
            }
        }
    }

    private function logValidation(string $operationId, string $operationType, bool $success, ?\Exception $error = null): void
    {
        $context = [
            'operation_id' => $operationId,
            'operation_type' => $operationType,
            'success' => $success
        ];

        if (!$success) {
            $context['error'] = [
                'message' => $error->getMessage(),
                'type' => get_class($error)
            ];
        }

        Log::info('Validation result', $context);
        $this->monitor->recordMetric('validation.result', $context);
    }
}
