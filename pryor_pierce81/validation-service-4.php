<?php

namespace App\Core\Validation;

use Illuminate\Support\Facades\Cache;
use App\Core\Interfaces\ValidationInterface;
use App\Core\Security\EncryptionService;
use App\Core\Monitoring\MetricsCollector;

class ValidationService implements ValidationInterface
{
    private EncryptionService $encryption;
    private MetricsCollector $metrics;
    private array $rules;
    private array $customValidators;

    public function __construct(
        EncryptionService $encryption,
        MetricsCollector $metrics,
        array $rules = []
    ) {
        $this->encryption = $encryption;
        $this->metrics = $metrics;
        $this->rules = $rules;
        $this->initializeValidators();
    }

    public function validate(array $data, array $rules = []): ValidationResult
    {
        $startTime = microtime(true);
        $rules = $rules ?: $this->rules;

        try {
            // Input sanitization
            $sanitizedData = $this->sanitizeInput($data);

            // Schema validation
            $this->validateSchema($sanitizedData, $rules);

            // Business rules validation
            $this->validateBusinessRules($sanitizedData);

            // Data integrity check
            $this->verifyDataIntegrity($sanitizedData);

            // Security validation
            $this->validateSecurity($sanitizedData);

            $this->recordMetrics('validation_success', $startTime);
            
            return new ValidationResult(true, $sanitizedData);

        } catch (ValidationException $e) {
            $this->recordMetrics('validation_failure', $startTime);
            throw $e;
        }
    }

    public function validateWithContext(array $data, ValidationContext $context): ValidationResult 
    {
        $rules = $this->determineContextRules($context);
        return $this->validate($data, $rules);
    }

    private function sanitizeInput(array $data): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            $sanitized[$key] = match (true) {
                is_string($value) => $this->sanitizeString($value),
                is_array($value) => $this->sanitizeInput($value),
                default => $value
            };
        }
        return $sanitized;
    }

    private function validateSchema(array $data, array $rules): void
    {
        foreach ($rules as $field => $fieldRules) {
            if (!isset($data[$field]) && $this->isRequired($fieldRules)) {
                throw new ValidationException("Required field missing: {$field}");
            }

            if (isset($data[$field])) {
                $this->validateField($field, $data[$field], $fieldRules);
            }
        }
    }

    private function validateBusinessRules(array $data): void
    {
        foreach ($this->customValidators as $validator) {
            $result = $validator->validate($data);
            if (!$result->isValid()) {
                throw new ValidationException($result->getError());
            }
        }
    }

    private function verifyDataIntegrity(array $data): void
    {
        if (!$this->encryption->verifyIntegrity($data)) {
            throw new IntegrityException('Data integrity check failed');
        }
    }

    private function validateSecurity(array $data): void
    {
        // XSS prevention
        $this->validateXSS($data);

        // SQL injection prevention
        $this->validateSQL($data);

        // Special character validation
        $this->validateSpecialChars($data);
    }

    private function validateField(string $field, $value, array $rules): void
    {
        foreach ($rules as $rule => $parameter) {
            $validator = $this->getValidator($rule);
            if (!$validator->validate($value, $parameter)) {
                throw new ValidationException(
                    "Validation failed for {$field}: {$validator->getMessage()}"
                );
            }
        }
    }

    private function sanitizeString(string $value): string
    {
        // HTML special chars encoding
        $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        
        // Remove control characters
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
        
        return trim($value);
    }

    private function validateXSS(array $data): void
    {
        array_walk_recursive($data, function($value) {
            if (is_string($value) && $this->detectXSS($value)) {
                throw new SecurityValidationException('Potential XSS detected');
            }
        });
    }

    private function validateSQL(array $data): void
    {
        array_walk_recursive($data, function($value) {
            if (is_string($value) && $this->detectSQLInjection($value)) {
                throw new SecurityValidationException('Potential SQL injection detected');
            }
        });
    }

    private function validateSpecialChars(array $data): void
    {
        array_walk_recursive($data, function($value) {
            if (is_string($value) && $this->hasInvalidChars($value)) {
                throw new ValidationException('Invalid characters detected');
            }
        });
    }

    private function detectXSS(string $value): bool
    {
        $patterns = [
            '/<script\b[^>]*>(.*?)<\/script>/is',
            '/javascript:/i',
            '/onclick/i',
            '/onerror/i',
            '/onload/i'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }
        return false;
    }

    private function detectSQLInjection(string $value): bool
    {
        $patterns = [
            '/\bUNION\b/i',
            '/\bSELECT\b/i',
            '/\bDROP\b/i',
            '/\bDELETE\b/i',
            '/\bUPDATE\b/i'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }
        return false;
    }

    private function recordMetrics(string $type, float $startTime): void
    {
        $this->metrics->record('validation', [
            'type' => $type,
            'duration' => microtime(true) - $startTime,
            'memory' => memory_get_peak_usage(true)
        ]);
    }

    private function initializeValidators(): void
    {
        $this->customValidators = [
            new BusinessRuleValidator(),
            new SecurityValidator(),
            new IntegrityValidator()
        ];
    }

    private function determineContextRules(ValidationContext $context): array
    {
        return array_merge(
            $this->rules,
            $context->getAdditionalRules(),
            $this->getEnvironmentSpecificRules()
        );
    }

    private function getEnvironmentSpecificRules(): array
    {
        $cacheKey = 'validation_rules:' . app()->environment();
        
        return Cache::remember($cacheKey, 3600, function() {
            return config('validation.environment_rules', []);
        });
    }
}
