<?php

namespace App\Core\Validation;

use App\Core\Security\SecurityManager;
use App\Core\Monitoring\MonitoringService;
use App\Core\Exceptions\ValidationException;

class ValidationService
{
    private SecurityManager $security;
    private MonitoringService $monitor;
    
    private const VALIDATION_RULES = [
        'critical' => [
            'max_retries' => 0,
            'timeout' => 5,
            'security_level' => 'maximum'
        ],
        'standard' => [
            'max_retries' => 2,
            'timeout' => 30,
            'security_level' => 'high'
        ]
    ];

    public function validateRequest(array $data, string $level = 'critical'): array
    {
        $operationId = $this->monitor->startOperation('validation');
        
        try {
            // Security pre-validation
            $this->security->validateInput($data);
            
            // Apply validation rules
            $validated = $this->applyValidationRules($data, self::VALIDATION_RULES[$level]);
            
            // Verify integrity
            $this->verifyDataIntegrity($validated);
            
            // Security post-validation
            $this->security->validateOutput($validated);
            
            return $validated;
            
        } catch (\Throwable $e) {
            $this->handleValidationFailure($e, $data);
            throw new ValidationException('Validation failed: ' . $e->getMessage());
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    private function applyValidationRules(array $data, array $rules): array
    {
        foreach ($data as $key => $value) {
            if (!$this->validateField($value, $rules)) {
                throw new ValidationException("Validation failed for field: {$key}");
            }
        }
        
        return $data;
    }

    private function validateField($value, array $rules): bool
    {
        // Type validation
        if (!$this->validateType($value)) {
            return false;
        }

        // Security validation
        if (!$this->validateSecurity($value, $rules['security_level'])) {
            return false;
        }

        // Content validation
        if (!$this->validateContent($value)) {
            return false;
        }

        return true;
    }

    private function verifyDataIntegrity(array $data): void
    {
        $hash = hash('sha256', serialize($data));
        
        if (!$this->security->verifyHash($hash)) {
            throw new ValidationException('Data integrity check failed');
        }
    }

    private function validateType($value): bool
    {
        if (is_array($value)) {
            return $this->validateArrayType($value);
        }
        
        if (is_object($value)) {
            return $this->validateObjectType($value);
        }
        
        return $this->validateScalarType($value);
    }

    private function validateSecurity($value, string $level): bool
    {
        return $this->security->validateValue($value, [
            'level' => $level,
            'context' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]
        ]);
    }

    private function validateContent($value): bool
    {
        if (is_string($value)) {
            return $this->validateStringContent($value);
        }
        
        if (is_numeric($value)) {
            return $this->validateNumericContent($value);
        }
        
        return true;
    }

    private function validateArrayType(array $value): bool
    {
        foreach ($value as $item) {
            if (!$this->validateType($item)) {
                return false;
            }
        }
        return true;
    }

    private function validateObjectType(object $value): bool
    {
        return $value instanceof \Stringable || 
               $value instanceof \JsonSerializable;
    }

    private function validateScalarType($value): bool
    {
        return is_scalar($value) || is_null($value);
    }

    private function validateStringContent(string $value): bool
    {
        // Check for malicious content
        if ($this->security->detectMaliciousContent($value)) {
            return false;
        }

        // Validate encoding
        if (!mb_check_encoding($value, 'UTF-8')) {
            return false;
        }

        // Length validation
        if (strlen($value) > config('validation.max_string_length', 1000)) {
            return false;
        }

        return true;
    }

    private function validateNumericContent($value): bool
    {
        // Range validation
        if ($value < config('validation.min_numeric_value', PHP_INT_MIN) || 
            $value > config('validation.max_numeric_value', PHP_INT_MAX)) {
            return false;
        }

        // Precision validation for floats
        if (is_float($value) && 
            !$this->validateFloatPrecision($value)) {
            return false;
        }

        return true;
    }

    private function validateFloatPrecision(float $value): bool
    {
        $precision = strlen(substr(strrchr((string)$value, "."), 1));
        return $precision <= config('validation.max_float_precision', 10);
    }

    private function handleValidationFailure(\Throwable $e, array $data): void
    {
        $this->monitor->recordFailure('validation', [
            'error' => $e->getMessage(),
            'data' => $this->sanitizeData($data),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function sanitizeData(array $data): array
    {
        return array_map(function ($value) {
            if (is_array($value)) {
                return $this->sanitizeData($value);
            }
            return is_scalar($value) ? $value : gettype($value);
        }, $data);
    }
}
