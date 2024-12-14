<?php

namespace App\Core\Validation;

use Illuminate\Support\Facades\{Cache, Log};
use App\Core\Security\SecurityManager;
use App\Core\Services\{SanitizerService, HashService};
use App\Core\Exceptions\{ValidationException, SecurityException};

class ValidationManager implements ValidationInterface
{
    private SecurityManager $security;
    private SanitizerService $sanitizer;
    private HashService $hash;
    
    private const CACHE_TTL = 3600;
    private const MAX_STRING_LENGTH = 65535;
    private const VALIDATION_TIMEOUT = 5;

    public function __construct(
        SecurityManager $security,
        SanitizerService $sanitizer,
        HashService $hash
    ) {
        $this->security = $security;
        $this->sanitizer = $sanitizer;
        $this->hash = $hash;
    }

    public function validate(array $data, array $rules): array
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeValidation($data, $rules),
            ['action' => 'validation.validate']
        );
    }

    protected function executeValidation(array $data, array $rules): array
    {
        set_time_limit(self::VALIDATION_TIMEOUT);

        try {
            // Pre-validation sanitization
            $sanitized = $this->sanitizeInput($data);
            
            // Validate each field
            $validated = [];
            foreach ($rules as $field => $ruleSet) {
                $value = $sanitized[$field] ?? null;
                $validated[$field] = $this->validateField($field, $value, $ruleSet);
            }

            // Validate cross-field rules if any
            $this->validateCrossFieldRules($validated, $rules);

            // Post-validation sanitization
            return $this->sanitizeOutput($validated);

        } catch (\Exception $e) {
            Log::error('Validation failed', [
                'data' => $this->hash->hashSensitiveData($data),
                'rules' => $rules,
                'error' => $e->getMessage()
            ]);
            throw new ValidationException('Validation failed: ' . $e->getMessage());
        }
    }

    protected function validateField(string $field, $value, array|string $rules): mixed
    {
        $rules = is_string($rules) ? explode('|', $rules) : $rules;
        
        foreach ($rules as $rule) {
            $value = $this->applySingleRule($field, $value, $rule);
        }

        return $value;
    }

    protected function applySingleRule(string $field, $value, string $rule): mixed
    {
        [$ruleName, $parameters] = $this->parseRule($rule);

        $cacheKey = "validation.{$ruleName}." . md5(serialize([$value, $parameters]));
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function() use ($ruleName, $value, $parameters, $field) {
            return match($ruleName) {
                'required' => $this->validateRequired($value, $field),
                'string' => $this->validateString($value),
                'email' => $this->validateEmail($value),
                'url' => $this->validateUrl($value),
                'numeric' => $this->validateNumeric($value),
                'array' => $this->validateArray($value),
                'max' => $this->validateMax($value, $parameters[0]),
                'min' => $this->validateMin($value, $parameters[0]),
                'in' => $this->validateIn($value, $parameters),
                'unique' => $this->validateUnique($value, $field, $parameters),
                'exists' => $this->validateExists($value, $parameters),
                default => throw new ValidationException("Unknown validation rule: {$ruleName}")
            };
        });
    }

    protected function validateRequired($value, string $field): mixed
    {
        if ($value === null || $value === '') {
            throw new ValidationException("{$field} is required");
        }
        return $value;
    }

    protected function validateString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new ValidationException("Value must be a string");
        }

        if (strlen($value) > self::MAX_STRING_LENGTH) {
            throw new ValidationException("String exceeds maximum length");
        }

        return $value;
    }

    protected function validateEmail($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException("Invalid email format");
        }

        return $value;
    }

    protected function validateUrl($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            throw new ValidationException("Invalid URL format");
        }

        return $value;
    }

    protected function validateNumeric($value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (!is_numeric($value)) {
            throw new ValidationException("Value must be numeric");
        }

        return $value;
    }

    protected function validateArray($value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value)) {
            throw new ValidationException("Value must be an array");
        }

        return $value;
    }

    protected function validateMax($value, int $max): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value) && $value > $max) {
            throw new ValidationException("Value exceeds maximum of {$max}");
        }

        if (is_string($value) && strlen($value) > $max) {
            throw new ValidationException("String length exceeds maximum of {$max}");
        }

        if (is_array($value) && count($value) > $max) {
            throw new ValidationException("Array length exceeds maximum of {$max}");
        }

        return $value;
    }

    protected function validateMin($value, int $min): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value) && $value < $min) {
            throw new ValidationException("Value below minimum of {$min}");
        }

        if (is_string($value) && strlen($value) < $min) {
            throw new ValidationException("String length below minimum of {$min}");
        }

        if (is_array($value) && count($value) < $min) {
            throw new ValidationException("Array length below minimum of {$min}");
        }

        return $value;
    }

    protected function validateIn($value, array $allowed): mixed
    {
        if ($value === null) {
            return null;
        }

        if (!in_array($value, $allowed, true)) {
            throw new ValidationException("Invalid value");
        }

        return $value;
    }

    protected function validateUnique($value, string $field, array $parameters): mixed
    {
        if ($value === null) {
            return null;
        }

        [$table, $column] = $parameters;
        
        $exists = \DB::table($table)
            ->where($column, $value)
            ->exists();

        if ($exists) {
            throw new ValidationException("{$field} must be unique");
        }

        return $value;
    }

    protected function validateExists($value, array $parameters): mixed
    {
        if ($value === null) {
            return null;
        }

        [$table, $column] = $parameters;
        
        $exists = \DB::table($table)
            ->where($column, $value)
            ->exists();

        if (!$exists) {
            throw new ValidationException("Referenced record does not exist");
        }

        return $value;
    }

    protected function sanitizeInput(array $data): array
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeInput($value);
            } else {
                $sanitized[$key] = $this->sanitizer->sanitizeInput($value);
            }
        }

        return $sanitized;
    }

    protected function sanitizeOutput(array $data): array
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeOutput($value);
            } else {
                $sanitized[$key] = $this->sanitizer->sanitizeOutput($value);
            }
        }

        return $sanitized;
    }

    protected function parseRule(string $rule): array
    {
        if (strpos($rule, ':') === false) {
            return [$rule, []];
        }

        [$ruleName, $parameters] = explode(':', $rule, 2);
        return [$ruleName, explode(',', $parameters)];
    }

    protected function validateCrossFieldRules(array $data, array $rules): void
    {
        foreach ($rules as $field => $ruleSet) {
            if (strpos($field, '*') !== false) {
                $this->validateWildcardRule($data, $field, $ruleSet);
            }
        }
    }

    protected function validateWildcardRule(array $data, string $pattern, $ruleSet): void
    {
        $fields = array_filter(
            array_keys($data),
            fn($field) => fnmatch($pattern, $field)
        );

        foreach ($fields as $field) {
            $this->validateField($field, $data[$field], $ruleSet);
        }
    }
}
