<?php

namespace App\Core\Validation;

use Illuminate\Support\Facades\{DB, Log};
use App\Core\Security\SecurityManager;
use App\Core\Exceptions\ValidationException;

class ValidationService
{
    protected SecurityManager $security;
    protected array $rules = [];
    protected array $sanitizers = [];

    public function __construct(
        SecurityManager $security,
        array $rules = [],
        array $sanitizers = []
    ) {
        $this->security = $security;
        $this->rules = $rules;
        $this->sanitizers = $sanitizers;
    }

    public function validate(array $data, string $context): array
    {
        DB::beginTransaction();
        
        try {
            $sanitized = $this->sanitize($data);
            
            $this->validateStructure($sanitized, $context);
            $this->validateSecurity($sanitized, $context);
            $this->validateBusiness($sanitized, $context);
            
            DB::commit();
            return $sanitized;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ValidationException(
                'Validation failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    protected function sanitize(array $data): array
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            $sanitized[$key] = match(true) {
                is_array($value) => $this->sanitize($value),
                is_string($value) => $this->sanitizeString($value),
                default => $value
            };
        }
        
        return $sanitized;
    }

    protected function sanitizeString(string $value): string
    {
        $sanitized = trim($value);
        
        foreach ($this->sanitizers as $sanitizer) {
            $sanitized = $sanitizer->sanitize($sanitized);
        }
        
        return $sanitized;
    }

    protected function validateStructure(array $data, string $context): void
    {
        $rules = $this->getRules($context);
        
        foreach ($rules as $field => $constraints) {
            if (!$this->validateField($data[$field] ?? null, $constraints)) {
                throw new ValidationException("Invalid field: {$field}");
            }
        }
    }

    protected function validateSecurity(array $data, string $context): void
    {
        // Validate against injection
        foreach ($data as $value) {
            if (is_string($value) && $this->containsSuspiciousPatterns($value)) {
                throw new ValidationException('Potentially malicious input detected');
            }
        }

        // Validate against overflow
        foreach ($data as $value) {
            if (is_string($value) && strlen($value) > config('validation.max_length')) {
                throw new ValidationException('Input exceeds maximum length');
            }
        }

        // Context-specific security validation
        $this->security->validateDataSecurity($data, $context);
    }

    protected function validateBusiness(array $data, string $context): void
    {
        $validator = $this->getBusinessValidator($context);
        
        if (!$validator->validate($data)) {
            throw new ValidationException(
                'Business validation failed: ' . $validator->getError()
            );
        }
    }

    protected function validateField($value, array $constraints): bool
    {
        foreach ($constraints as $constraint) {
            if (!$constraint->validate($value)) {
                Log::warning('Field validation failed', [
                    'value' => $value,
                    'constraint' => get_class($constraint)
                ]);
                return false;
            }
        }
        
        return true;
    }

    protected function containsSuspiciousPatterns(string $value): bool
    {
        $patterns = config('security.suspicious_patterns', []);
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }
        
        return false;
    }

    protected function getRules(string $context): array
    {
        return $this->rules[$context] ?? throw new ValidationException(
            "No validation rules found for context: {$context}"
        );
    }

    protected function getBusinessValidator(string $context): BusinessValidatorInterface
    {
        $validator = config("validation.business.{$context}");
        
        if (!$validator || !class_exists($validator)) {
            throw new ValidationException(
                "No business validator found for context: {$context}"
            );
        }
        
        return app($validator);
    }
}
