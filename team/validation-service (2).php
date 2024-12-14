<?php

namespace App\Core\Security\Validation;

use Illuminate\Support\Facades\Cache;
use App\Core\Security\Encryption\EncryptionService;
use App\Core\Audit\AuditLogger;
use App\Exceptions\ValidationException;

class ValidationService implements ValidationInterface 
{
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private array $config;

    private const VALIDATION_CACHE_TTL = 300;
    private const MAX_STRING_LENGTH = 65535;
    private const SANITIZE_PATTERNS = [
        'script' => '/<script\b[^>]*>(.*?)<\/script>/is',
        'iframe' => '/<iframe\b[^>]*>(.*?)<\/iframe>/is',
        'eval' => '/eval\s*\((.*?)\)/is',
        'onload' => '/\bon\w+\s*=\s*"/is',
        'sql' => '/(\bSELECT\b|\bUNION\b|\bDELETE\b|\bDROP\b|\bUPDATE\b|\bINSERT\b)/is'
    ];

    public function validateData(array $data, array $rules): ValidationResult 
    {
        try {
            $validatedData = [];
            $errors = [];

            foreach ($rules as $field => $fieldRules) {
                try {
                    $value = $data[$field] ?? null;
                    $validatedData[$field] = $this->validateField($field, $value, $fieldRules);
                } catch (ValidationException $e) {
                    $errors[$field] = $e->getMessage();
                }
            }

            if (!empty($errors)) {
                throw new ValidationException(
                    'Validation failed: ' . json_encode($errors),
                    $errors
                );
            }

            return new ValidationResult($validatedData);

        } catch (\Throwable $e) {
            $this->handleValidationFailure('data_validation', $e, $data);
            throw $e;
        }
    }

    public function validateOperation(string $operation, array $context): bool 
    {
        try {
            // Check operation whitelist
            if (!$this->isOperationAllowed($operation)) {
                return false;
            }

            // Validate context requirements
            if (!$this->validateContext($operation, $context)) {
                return false;
            }

            // Check rate limits
            if (!$this->checkRateLimit($operation, $context)) {
                return false;
            }

            // Verify system state
            if (!$this->verifySystemState()) {
                return false;
            }

            return true;

        } catch (\Throwable $e) {
            $this->handleValidationFailure('operation_validation', $e, [
                'operation' => $operation,
                'context' => $context
            ]);
            throw $e;
        }
    }

    private function validateField(string $field, $value, array $rules): mixed
    {
        // Required check
        if (in_array('required', $rules) && $value === null) {
            throw new ValidationException("Field '$field' is required");
        }

        // Null handling
        if ($value === null && !in_array('required', $rules)) {
            return null;
        }

        // Type validation
        $value = $this->validateType($field, $value, $rules);

        // Format validation
        $value = $this->validateFormat($field, $value, $rules);

        // Length/Size validation
        $this->validateLength($field, $value, $rules);

        // Range validation
        $this->validateRange($field, $value, $rules);

        // Pattern validation
        $this->validatePattern($field, $value, $rules);

        // Custom validation rules
        $value = $this->applyCustomValidation($field, $value, $rules);

        // Security validation
        $value = $this->validateSecurity($field, $value, $rules);

        return $value;
    }

    private function validateType(string $field, $value, array $rules): mixed 
    {
        $type = $this->extractTypeRule($rules);
        if (!$type) {
            return $value;
        }

        switch ($type) {
            case 'string':
                return $this->validateString($field, $value);
            case 'integer':
                return $this->validateInteger($field, $value);
            case 'float':
                return $this->validateFloat($field, $value);
            case 'boolean':
                return $this->validateBoolean($field, $value);
            case 'array':
                return $this->validateArray($field, $value);
            case 'date':
                return $this->validateDate($field, $value);
            case 'email':
                return $this->validateEmail($field, $value);
            default:
                throw new ValidationException("Unknown type '$type' for field '$field'");
        }
    }

    private function validateSecurity(string $field, $value, array $rules): mixed 
    {
        // XSS prevention
        if (is_string($value)) {
            $value = $this->sanitizeString($value);
        }

        // SQL injection prevention
        if ($this->containsSqlInjection($value)) {
            throw new ValidationException("Invalid characters in field '$field'");
        }

        // Command injection prevention
        if ($this->containsCommandInjection($value)) {
            throw new ValidationException("Invalid characters in field '$field'");
        }

        // Sensitive data handling
        if (in_array('sensitive', $rules)) {
            $value = $this->encryption->encryptData($value);
        }

        return $value;
    }

    private function sanitizeString(string $value): string 
    {
        // Length check
        if (strlen($value) > self::MAX_STRING_LENGTH) {
            throw new ValidationException('Input exceeds maximum length');
        }

        // Apply sanitization patterns
        foreach (self::SANITIZE_PATTERNS as $pattern) {
            $value = preg_replace($pattern, '', $value);
        }

        // HTML encoding
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5);

        return $value;
    }

    private function isOperationAllowed(string $operation): bool 
    {
        return Cache::remember(
            'operation_allowed:' . $operation,
            self::VALIDATION_CACHE_TTL,
            function() use ($operation) {
                return in_array(
                    $operation,
                    $this->config['allowed_operations'] ?? []
                );
            }
        );
    }

    private function verifySystemState(): bool 
    {
        $metrics = Cache::remember('system_state', 60, function() {
            return [
                'memory' => memory_get_usage(true),
                'cpu' => sys_getloadavg()[0],
                'connections' => $this->getActiveConnections(),
                'storage' => disk_free_space('/')
            ];
        });

        foreach ($metrics as $metric => $value) {
            if (!$this->isMetricWithinLimits($metric, $value)) {
                return false;
            }
        }

        return true;
    }

    private function handleValidationFailure(string $type, \Throwable $e, array $context): void 
    {
        $this->auditLogger->logSecurityEvent(
            'validation_failure',
            [
                'type' => $type,
                'error' => $e->getMessage(),
                'context' => $context
            ],
            4 // High severity
        );
    }
}
