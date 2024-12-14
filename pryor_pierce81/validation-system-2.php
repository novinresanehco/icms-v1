<?php

namespace App\Core\Validation;

use App\Core\Security\SecurityContext;
use App\Core\Exceptions\ValidationException;
use Illuminate\Support\Facades\Validator;

class ValidationManager implements ValidationInterface
{
    private SecurityManager $security;
    private SanitizerService $sanitizer;
    private array $config;

    public function __construct(
        SecurityManager $security,
        SanitizerService $sanitizer,
        array $config
    ) {
        $this->security = $security;
        $this->sanitizer = $sanitizer;
        $this->config = $config;
    }

    public function validate(array $data, array $rules, array $messages = []): array
    {
        try {
            // Pre-validation sanitization
            $sanitized = $this->sanitizer->sanitizeInput($data);
            
            // Security validation
            $this->validateSecurity($sanitized);
            
            // Core validation
            $validator = Validator::make($sanitized, $rules, $messages);
            
            if ($validator->fails()) {
                throw new ValidationException(
                    'Validation failed: ' . implode(', ', $validator->errors()->all())
                );
            }
            
            // Post-validation sanitization
            return $this->sanitizer->sanitizeOutput($validator->validated());
            
        } catch (\Exception $e) {
            $this->handleValidationFailure($e, $data, $rules);
            throw $e;
        }
    }

    public function validateField($value, string $rule): bool
    {
        $validator = Validator::make(
            ['field' => $value],
            ['field' => $rule]
        );

        return !$validator->fails();
    }

    public function validateBatch(array $items, array $rules): array
    {
        $results = [];
        $errors = [];

        foreach ($items as $key => $item) {
            try {
                $results[$key] = $this->validate($item, $rules);
            } catch (ValidationException $e) {
                $errors[$key] = $e->getMessage();
            }
        }

        if (!empty($errors)) {
            throw new BatchValidationException($errors);
        }

        return $results;
    }

    protected function validateSecurity(array $data): void
    {
        // Check for XSS attempts
        $this->validateXSS($data);
        
        // Check for SQL injection attempts
        $this->validateSQLInjection($data);
        
        // Check for command injection attempts
        $this->validateCommandInjection($data);
        
        // Check for file inclusion attempts
        $this->validateFileInclusion($data);
    }

    protected function validateXSS(array $data): void
    {
        $patterns = $this->config['xss_patterns'] ?? [];
        
        foreach ($this->flattenArray($data) as $value) {
            if (!is_string($value)) continue;
            
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $value)) {
                    throw new SecurityValidationException('XSS attempt detected');
                }
            }
        }
    }

    protected function validateSQLInjection(array $data): void
    {
        $patterns = $this->config['sql_injection_patterns'] ?? [];
        
        foreach ($this->flattenArray($data) as $value) {
            if (!is_string($value)) continue;
            
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $value)) {
                    throw new SecurityValidationException('SQL injection attempt detected');
                }
            }
        }
    }

    protected function validateCommandInjection(array $data): void
    {
        $patterns = $this->config['command_injection_patterns'] ?? [];
        
        foreach ($this->flattenArray($data) as $value) {
            if (!is_string($value)) continue;
            
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $value)) {
                    throw new SecurityValidationException('Command injection attempt detected');
                }
            }
        }
    }

    protected function validateFileInclusion(array $data): void
    {
        $patterns = $this->config['file_inclusion_patterns'] ?? [];
        
        foreach ($this->flattenArray($data) as $value) {
            if (!is_string($value)) continue;
            
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $value)) {
                    throw new SecurityValidationException('File inclusion attempt detected');
                }
            }
        }
    }

    protected function flattenArray(array $array, string $prefix = ''): array
    {
        $result = [];
        
        foreach ($array as $key => $value) {
            $newKey = $prefix ? "{$prefix}.{$key}" : $key;
            
            if (is_array($value)) {
                $result = array_merge($result, $this->flattenArray($value, $newKey));
            } else {
                $result[$newKey] = $value;
            }
        }
        
        return $result;
    }

    protected function handleValidationFailure(\Exception $e, array $data, array $rules): void
    {
        $this->security->logSecurityEvent(
            'validation.failure',
            [
                'message' => $e->getMessage(),
                'data' => $this->sanitizer->sanitizeForLog($data),
                'rules' => $rules
            ],
            SecurityLevel::WARNING
        );
    }
}
