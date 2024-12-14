<?php

namespace App\Core\Validation;

use App\Core\Exception\ValidationException;
use App\Core\Monitoring\ValidationMonitorInterface;

class ValidationService implements ValidationServiceInterface 
{
    private ValidationMonitorInterface $monitor;
    private array $rules;

    public function __construct(
        ValidationMonitorInterface $monitor,
        array $rules = []
    ) {
        $this->monitor = $monitor;
        $this->rules = $rules;
    }

    public function validateData(array $data, string $context): array
    {
        $operationId = $this->monitor->startValidation($context);

        try {
            $rules = $this->getRules($context);
            $validated = [];

            foreach ($rules as $field => $rule) {
                if (!isset($data[$field]) && $rule['required']) {
                    throw new ValidationException("Required field missing: $field");
                }

                $value = $data[$field] ?? null;
                $validated[$field] = $this->validateField($value, $rule);
            }

            $this->monitor->validationSuccess($operationId);
            return $validated;

        } catch (\Exception $e) {
            $this->monitor->validationFailure($operationId, $e);
            throw $e;
        }
    }

    private function validateField($value, array $rule): mixed
    {
        if ($value === null && !$rule['required']) {
            return null;
        }

        $type = $rule['type'];
        $constraints = $rule['constraints'] ?? [];

        if (!$this->validateType($value, $type)) {
            throw new ValidationException("Invalid type for field");
        }

        foreach ($constraints as $constraint => $params) {
            if (!$this->validateConstraint($value, $constraint, $params)) {
                throw new ValidationException("Constraint failed: $constraint");
            }
        }

        return $this->sanitizeValue($value, $type);
    }

    private function validateType($value, string $type): bool
    {
        return match($type) {
            'string' => is_string($value),
            'int' => is_int($value) || ctype_digit($value),
            'float' => is_float($value) || is_numeric($value),
            'bool' => is_bool($value) || in_array($value, [0, 1, '0', '1']),
            'array' => is_array($value),
            default => false
        };
    }

    private function validateConstraint($value, string $constraint, array $params): bool
    {
        return match($constraint) {
            'min' => $value >= $params['value'],
            'max' => $value <= $params['value'],
            'length' => strlen($value) <= $params['value'],
            'pattern' => preg_match($params['value'], $value),
            'enum' => in_array($value, $params['values']),
            default => false
        };
    }

    private function sanitizeValue($value, string $type): mixed
    {
        return match($type) {
            'int' => (int)$value,
            'float' => (float)$value,
            'bool' => (bool)$value,
            'string' => htmlspecialchars($value, ENT_QUOTES),
            'array' => array_map(fn($v) => $this->sanitizeValue($v, 'string'), $value),
            default => $value
        };
    }

    private function getRules(string $context): array
    {
        if (!isset($this->rules[$context])) {
            throw new ValidationException("No validation rules for context: $context");
        }
        return $this->rules[$context];
    }
}
