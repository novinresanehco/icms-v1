<?php

namespace App\Core\Validation;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Audit\AuditLogger;
use App\Core\Exceptions\ValidationException;
use Illuminate\Support\Facades\DB;

class ValidationService implements ValidationInterface
{
    private SecurityManagerInterface $security;
    private AuditLogger $auditLogger;
    private array $sanitizers;
    private array $validators;

    public function __construct(
        SecurityManagerInterface $security,
        AuditLogger $auditLogger
    ) {
        $this->security = $security;
        $this->auditLogger = $auditLogger;
        $this->initializeSanitizers();
        $this->initializeValidators();
    }

    public function validate(array $data, array $rules): array
    {
        DB::beginTransaction();
        
        try {
            $sanitized = $this->sanitizeInput($data);
            $validated = $this->validateData($sanitized, $rules);
            $verified = $this->verifyIntegrity($validated);
            
            $this->auditLogger->logValidation(
                'data_validation',
                $validated,
                true
            );
            
            DB::commit();
            return $verified;
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            $this->auditLogger->logValidationFailure(
                'data_validation',
                $data,
                $e->getMessage()
            );
            
            throw new ValidationException(
                'Validation failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    private function sanitizeInput(array $data): array
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeInput($value);
                continue;
            }
            
            $sanitized[$key] = $this->applySanitizers($value);
        }
        
        return $sanitized;
    }

    private function validateData(array $data, array $rules): array
    {
        $validated = [];
        
        foreach ($rules as $field => $fieldRules) {
            if (!isset($data[$field]) && $this->isRequired($fieldRules)) {
                throw new ValidationException("Field {$field} is required");
            }
            
            $value = $data[$field] ?? null;
            
            foreach ($fieldRules as $rule) {
                if (!$this->applyValidator($rule, $value)) {
                    throw new ValidationException("Validation failed for {$field}: {$rule}");
                }
            }
            
            $validated[$field] = $value;
        }
        
        return $validated;
    }

    private function verifyIntegrity(array $data): array
    {
        $hash = $this->security->generateDataHash($data);
        
        if (!$this->security->verifyDataHash($data, $hash)) {
            throw new ValidationException('Data integrity check failed');
        }
        
        return $data;
    }

    private function applySanitizers($value)
    {
        foreach ($this->sanitizers as $sanitizer) {
            $value = $sanitizer($value);
        }
        return $value;
    }

    private function applyValidator(string $rule, $value): bool
    {
        if (!isset($this->validators[$rule])) {
            throw new ValidationException("Unknown validation rule: {$rule}");
        }
        
        return $this->validators[$rule]($value);
    }

    private function isRequired(array $rules): bool
    {
        return in_array('required', $rules);
    }

    private function initializeSanitizers(): void
    {
        $this->sanitizers = [
            // HTML Special Chars
            fn($value) => htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            
            // Remove NULL bytes
            fn($value) => str_replace(chr(0), '', $value),
            
            // Normalize line endings
            fn($value) => str_replace(["\r\n", "\r"], "\n", $value),
            
            // Remove invisible characters
            fn($value) => preg_replace('/[\x00-\x1F\x7F]/u', '', $value),
            
            // Trim whitespace
            fn($value) => trim($value)
        ];
    }

    private function initializeValidators(): void
    {
        $this->validators = [
            'required' => fn($value) => !empty($value),
            
            'string' => fn($value) => is_string($value),
            
            'numeric' => fn($value) => is_numeric($value),
            
            'email' => fn($value) => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            
            'url' => fn($value) => filter_var($value, FILTER_VALIDATE_URL) !== false,
            
            'alpha' => fn($value) => ctype_alpha($value),
            
            'alphanumeric' => fn($value) => ctype_alnum($value),
            
            'boolean' => fn($value) => is_bool($value) || in_array($value, [0, 1, '0', '1', true, false], true),
            
            'json' => fn($value) => is_string($value) && 
                is_array(json_decode($value, true)) && 
                (json_last_error() === JSON_ERROR_NONE)
        ];
    }
}
