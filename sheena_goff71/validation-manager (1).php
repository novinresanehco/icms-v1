<?php

namespace App\Core\Validation;

use App\Core\Security\SecurityCore;
use App\Exceptions\ValidationException;

class ValidationManager implements ValidationInterface
{
    private SecurityCore $security;
    private array $config;
    private array $rules;

    public function __construct(
        SecurityCore $security,
        array $config,
        array $rules
    ) {
        $this->security = $security;
        $this->config = $config;
        $this->rules = $rules;
    }

    public function validateData(array $data): array
    {
        // Check structure
        if (!$this->validateStructure($data)) {
            throw new ValidationException('Invalid data structure');
        }

        // Apply validation rules
        $validated = [];
        foreach ($data as $key => $value) {
            if (!isset($this->rules[$key])) {
                throw new ValidationException("Unknown field: $key");
            }

            $validated[$key] = $this->validateField($key, $value);
        }

        // Check required fields
        foreach ($this->rules as $field => $rule) {
            if ($rule['required'] && !isset($validated[$field])) {
                throw new ValidationException("Missing required field: $field");
            }
        }

        return $validated;
    }

    public function verifyIntegrity($data): bool
    {
        // Verify data structure
        if (!$this->isValidStructure($data)) {
            return false;
        }

        // Check checksums
        if (!$this->verifyChecksums($data)) {
            return false;
        }

        // Verify metadata
        if (!$this->verifyMetadata($data)) {
            return false;
        }

        return true;
    }

    public function validateConstraints(array $data, array $constraints): bool
    {
        foreach ($constraints as $field => $constraint) {
            if (!$this->validateConstraint($data[$field] ?? null, $constraint)) {
                return false;
            }
        }

        return true;
    }

    private function validateField(string $field, $value): mixed
    {
        $rules = $this->rules[$field];

        // Type validation
        if (!$this->validateType($value, $rules['type'])) {
            throw new ValidationException("Invalid type for field: $field");
        }

        // Format validation
        if (isset($rules['format']) && !$this->validateFormat($value, $rules['format'])) {
            throw new ValidationException("Invalid format for field: $field");
        }

        // Range validation
        if (isset($rules['range'])) {
            if (!$this->validateRange($value, $rules['range'])) {
                throw new ValidationException("Value out of range for field: $field");
            }
        }

        // Custom validation
        if (isset($rules['custom'])) {
            if (!$this->executeCustomValidation($value, $rules['custom'])) {
                throw new ValidationException("Custom validation failed for field: $field");
            }
        }

        return $this->sanitizeValue($value, $rules);
    }

    private function validateStructure(array $data): bool
    {
        return isset($data['type']) && 
               is_array($data['data'] ?? null) &&
               $this->isValidType($data['type']);
    }

    private function validateType($value, string $expectedType): bool
    {
        return match($expectedType) {
            'string' => is_string($value),
            'int' => is_int($value),
            'float' => is_float($value),
            'bool' => is_bool($value),
            'array' => is_array($value),
            'date' => $this->isValidDate($value),
            default => false
        };
    }

    private function validateFormat($value, string $format): bool
    {
        return match($format) {
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url' => filter_var($value, FILTER_VALIDATE_URL) !== false,
            'ip' => filter_var($value, FILTER_VALIDATE_IP) !== false,
            'json' => $this->isValidJson($value),
            'base64' => $this->isValidBase64($value),
            default => false
        };
    }

    private function validateRange($value, array $range): bool
    {
        if (isset($range['min']) && $value < $range['min']) {
            return false;
        }

        if (isset($range['max']) && $value > $range['max']) {
            return false;
        }

        return true;
    }

    private function executeCustomValidation($value, callable $validator): bool
    {
        try {
            return $validator($value);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function sanitizeValue($value, array $rules): mixed
    {
        if (isset($rules['sanitize'])) {
            return match($rules['sanitize']) {
                'trim' => trim($value),
                'lowercase' => strtolower($value),
                'uppercase' => strtoupper($value),
                'int' => (int)$value,
                'float' => (float)$value,
                'bool' => (bool)$value,
                default => $value
            };
        }

        return $value;
    }

    private function isValidDate($value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        try {
            new \DateTime($value);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function isValidJson($value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        try {
            json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            return true;
        } catch (\JsonException $e) {
            return false;
        }
    }

    private function isValidBase64($value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $decoded = base64_decode($value, true);
        return $decoded !== false && base64_encode($decoded) === $value;
    }
}
