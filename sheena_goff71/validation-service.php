<?php

namespace App\Core\Services;

use Illuminate\Support\Facades\{Hash, Validator, Log};
use App\Core\Exceptions\{ValidationException, SecurityException};
use App\Core\Models\ValidationRule;
use App\Core\Security\DataSanitizer;

class ValidationService
{
    private DataSanitizer $sanitizer;
    private array $securityRules;
    private const MAX_STRING_LENGTH = 65535;
    private const ALLOWED_TAGS = '<p><br><strong><em><ul><li><ol>';

    public function __construct(DataSanitizer $sanitizer, array $securityRules)
    {
        $this->sanitizer = $sanitizer;
        $this->securityRules = $securityRules;
    }

    public function validate(array $data, array $rules, array $messages = []): array
    {
        try {
            // Pre-validation sanitization
            $sanitizedData = $this->sanitizeInput($data);
            
            // Apply security rules
            $this->enforceSecurityRules($sanitizedData);
            
            // Validate against rules
            $validator = Validator::make($sanitizedData, $rules, $messages);
            
            if ($validator->fails()) {
                throw new ValidationException($validator->errors()->first());
            }
            
            // Post-validation security check
            $validatedData = $validator->validated();
            $this->performSecurityValidation($validatedData);
            
            // Final sanitization
            return $this->sanitizeOutput($validatedData);
            
        } catch (\Exception $e) {
            Log::error('Validation failed', [
                'data' => $data,
                'rules' => $rules,
                'error' => $e->getMessage()
            ]);
            throw new ValidationException('Validation failed: ' . $e->getMessage());
        }
    }

    public function validateWithContext(array $data, string $context): array
    {
        $rules = $this->loadContextRules($context);
        return $this->validate($data, $rules);
    }

    public function enforceSecurityRules(array $data): void
    {
        foreach ($this->securityRules as $rule => $validator) {
            if (!$validator($data)) {
                throw new SecurityException("Security rule violation: {$rule}");
            }
        }
    }

    public function sanitizeInput(array $data): array
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeInput($value);
            } else {
                $sanitized[$key] = $this->sanitizer->sanitize($value);
            }
        }
        
        return $sanitized;
    }

    public function sanitizeOutput(array $data): array
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeOutput($value);
            } else {
                $sanitized[$key] = $this->sanitizer->escapeOutput($value);
            }
        }
        
        return $sanitized;
    }

    public function validateHash(string $value, string $hash): bool
    {
        return Hash::check($value, $hash);
    }

    public function validateEncryption($data): bool
    {
        if (!is_array($data) || !isset($data['value'], $data['iv'], $data['tag'])) {
            return false;
        }

        return $this->sanitizer->verifyEncryption($data);
    }

    public function validateIntegrity($data): bool
    {
        if (!is_array($data) || !isset($data['value'], $data['hash'])) {
            return false;
        }

        $computedHash = hash_hmac('sha256', $data['value'], $this->securityRules['hash_key']);
        return hash_equals($computedHash, $data['hash']);
    }

    protected function performSecurityValidation(array $data): void
    {
        // Check for maximum lengths
        foreach ($data as $key => $value) {
            if (is_string($value) && strlen($value) > self::MAX_STRING_LENGTH) {
                throw new ValidationException("Field {$key} exceeds maximum length");
            }
        }

        // Check for malicious content
        foreach ($data as $value) {
            if (is_string($value)) {
                $this->detectMaliciousContent($value);
            }
        }

        // Validate nested structures
        foreach ($data as $value) {
            if (is_array($value)) {
                $this->performSecurityValidation($value);
            }
        }
    }

    protected function detectMaliciousContent(string $value): void
    {
        // Check for script tags
        if (preg_match('/<script\b[^>]*>(.*?)<\/script>/is', $value)) {
            throw new SecurityException('Script tags are not allowed');
        }

        // Check for SQL injection patterns
        if (preg_match('/\b(union|select|insert|update|delete|drop)\b/i', $value)) {
            throw new SecurityException('Potential SQL injection detected');
        }

        // Check for eval-like patterns
        if (preg_match('/(eval|exec|system)\s*\(/i', $value)) {
            throw new SecurityException('Potentially dangerous function call detected');
        }

        // Check for file inclusion attempts
        if (preg_match('/\.\.(\/|\\)/i', $value)) {
            throw new SecurityException('Directory traversal attempt detected');
        }
    }

    protected function loadContextRules(string $context): array
    {
        return ValidationRule::where('context', $context)
            ->get()
            ->mapWithKeys(function($rule) {
                return [$rule->field => $rule->validation_rules];
            })
            ->toArray();
    }

    protected function validateSpecialFields(array $data): void
    {
        foreach ($data as $key => $value) {
            switch ($key) {
                case 'email':
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        throw new ValidationException('Invalid email format');
                    }
                    break;
                    
                case 'url':
                    if (!filter_var($value, FILTER_VALIDATE_URL)) {
                        throw new ValidationException('Invalid URL format');
                    }
                    break;
                    
                case 'html':
                    $cleanHtml = strip_tags($value, self::ALLOWED_TAGS);
                    if ($cleanHtml !== $value) {
                        throw new ValidationException('Invalid HTML content');
                    }
                    break;
            }
        }
    }
}
