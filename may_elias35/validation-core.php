<?php

namespace App\Core\Validation;

class ValidationManager implements ValidationInterface
{
    private EncryptionService $encryption;
    private SecurityManager $security;
    private IntegrityChecker $integrity;
    private AuditLogger $logger;
    private MetricsCollector $metrics;

    public function __construct(
        EncryptionService $encryption,
        SecurityManager $security,
        IntegrityChecker $integrity,
        AuditLogger $logger,
        MetricsCollector $metrics
    ) {
        $this->encryption = $encryption;
        $this->security = $security;
        $this->integrity = $integrity;
        $this->logger = $logger;
        $this->metrics = $metrics;
    }

    public function validateCriticalData(array $data, array $rules): ValidationResult
    {
        $operationId = uniqid('val_', true);
        $startTime = microtime(true);

        try {
            $this->security->validateContext();
            $this->validateStructure($data, $rules);
            
            $sanitizedData = $this->sanitizeData($data);
            $validatedData = $this->applyValidationRules($sanitizedData, $rules);
            
            $this->verifyDataIntegrity($validatedData);
            $this->enforceSecurityConstraints($validatedData);
            
            $encryptedData = $this->encryptSensitiveData($validatedData);
            
            $this->logSuccess($operationId, $data);
            $this->recordMetrics($operationId, $startTime);
            
            return new ValidationResult(true, $encryptedData);
            
        } catch (\Exception $e) {
            $this->handleValidationFailure($operationId, $data, $e);
            throw new ValidationException('Validation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function validateStructure(array $data, array $rules): void
    {
        foreach ($rules as $field => $rule) {
            if (isset($rule['required']) && $rule['required']) {
                if (!isset($data[$field])) {
                    throw new ValidationException("Required field missing: {$field}");
                }
            }

            if (isset($data[$field])) {
                $this->validateFieldType($data[$field], $rule);
            }
        }
    }

    private function validateFieldType($value, array $rule): void
    {
        switch ($rule['type']) {
            case 'string':
                if (!is_string($value)) {
                    throw new ValidationException('Invalid string value');
                }
                if (isset($rule['max_length']) && strlen($value) > $rule['max_length']) {
                    throw new ValidationException('String exceeds maximum length');
                }
                break;
                
            case 'integer':
                if (!is_int($value)) {
                    throw new ValidationException('Invalid integer value');
                }
                if (isset($rule['min']) && $value < $rule['min']) {
                    throw new ValidationException('Value below minimum');
                }
                if (isset($rule['max']) && $value > $rule['max']) {
                    throw new ValidationException('Value exceeds maximum');
                }
                break;
                
            case 'array':
                if (!is_array($value)) {
                    throw new ValidationException('Invalid array value');
                }
                if (isset($rule['min_items']) && count($value) < $rule['min_items']) {
                    throw new ValidationException('Array below minimum items');
                }
                break;
                
            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    throw new ValidationException('Invalid email format');
                }
                break;
                
            default:
                throw new ValidationException('Unsupported data type');
        }
    }

    private function sanitizeData(array $data): array
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $sanitized[$key] = $this->security->sanitizeString($value);
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeData($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }

    private function applyValidationRules(array $data, array $rules): array
    {
        $validated = [];
        
        foreach ($data as $field => $value) {
            if (!isset($rules[$field])) {
                throw new ValidationException("Unexpected field: {$field}");
            }

            $rule = $rules[$field];
            $validated[$field] = $this->validateField($value, $rule);
        }
        
        return $validated;
    }

    private function validateField($value, array $rule): mixed
    {
        if (isset($rule['custom_validation'])) {
            $value = $this->executeCustomValidation($value, $rule['custom_validation']);
        }

        if (isset($rule['transform'])) {
            $value = $this->transformValue($value, $rule['transform']);
        }

        return $value;
    }

    private function verifyDataIntegrity(array $data): void
    {
        $checksum = $this->integrity->calculateChecksum($data);
        
        if (!$this->integrity->verifyChecksum($data, $checksum)) {
            throw new IntegrityException('Data integrity check failed');
        }
    }

    private function enforceSecurityConstraints(array $data): void
    {
        foreach ($this->security->getSecurityConstraints() as $constraint) {
            if (!$this->security->validateConstraint($data, $constraint)) {
                throw new SecurityConstraintException("Security constraint failed: {$constraint}");
            }
        }
    }

    private function encryptSensitiveData(array $data): array
    {
        $encrypted = [];
        
        foreach ($data as $field => $value) {
            if ($this->isSensitiveField($field)) {
                $encrypted[$field] = $this->encryption->encrypt($value);
            } else {
                $encrypted[$field] = $value;
            }
        }
        
        return $encrypted;
    }

    private function isSensitiveField(string $field): bool
    {
        return in_array($field, config('security.sensitive_fields'));
    }

    private function logSuccess(string $operationId, array $data): void
    {
        $this->logger->logValidation([
            'operation_id' => $operationId,
            'timestamp' => now(),
            'status' => 'success',
            'fields_validated' => array_keys($data)
        ]);
    }

    private function recordMetrics(string $operationId, float $startTime): void
    {
        $this->metrics->record([
            'operation_id' => $operationId,
            'type' => 'validation',
            'duration' => microtime(true) - $startTime,
            'timestamp' => now()
        ]);
    }

    private function handleValidationFailure(string $operationId, array $data, \Exception $e): void
    {
        $this->logger->logValidationFailure([
            'operation_id' => $operationId,
            'timestamp' => now(),
            'error' => $e->getMessage(),
            'fields' => array_keys($data)
        ]);

        if ($e instanceof SecurityException) {
            $this->security->handleSecurityIncident($operationId, $e);
        }
    }
}
