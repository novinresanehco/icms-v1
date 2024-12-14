<?php

namespace App\Core\Audit\Validators;

class AnalysisValidator
{
    private array $validators;
    private ValidatorContext $context;

    public function __construct(array $validators, ValidatorContext $context)
    {
        $this->validators = $validators;
        $this->context = $context;
    }

    public function validate(AnalysisRequest $request): ValidationResult
    {
        $errors = [];

        foreach ($this->validators as $validator) {
            if ($validator->supports($request)) {
                $result = $validator->validate($request, $this->context);
                if (!$result->isValid()) {
                    $errors = array_merge($errors, $result->getErrors());
                }
            }
        }

        return new ValidationResult(empty($errors), $errors);
    }
}

class DataValidator
{
    private array $rules;

    public function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    public function validate(array $data): ValidationResult
    {
        $errors = [];

        foreach ($this->rules as $field => $rules) {
            $value = $data[$field] ?? null;
            foreach ($rules as $rule) {
                if (!$this->validateRule($rule, $value)) {
                    $errors[] = sprintf(
                        "Field '%s' failed validation rule '%s'",
                        $field,
                        $rule
                    );
                }
            }
        }

        return new ValidationResult(empty($errors), $errors);
    }

    private function validateRule(string $rule, $value): bool
    {
        return match($rule) {
            'required' => !empty($value),
            'numeric' => is_numeric($value),
            'string' => is_string($value),
            'array' => is_array($value),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url' => filter_var($value, FILTER_VALIDATE_URL) !== false,
            default => true
        };
    }
}

class ConfigValidator
{
    private array $requiredFields;
    private array $typeRules;

    public function __construct(array $requiredFields, array $typeRules)
    {
        $this->requiredFields = $requiredFields;
        $this->typeRules = $typeRules;
    }

    public function validate(array $config): ValidationResult
    {
        $errors = [];

        // Check required fields
        foreach ($this->requiredFields as $field) {
            if (!isset($config[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }

        // Check types
        foreach ($this->typeRules as $field => $type) {
            if (isset($config[$field]) && !$this->validateType($config[$field], $type)) {
                $errors[] = "Invalid type for field {$field}, expected {$type}";
            }
        }

        return new ValidationResult(empty($errors), $errors);
    }

    private function validateType($value, string $type): bool
    {
        return match($type) {
            'string' => is_string($value),
            'int' => is_int($value),
            'float' => is_float($value),
            'bool' => is_bool($value),
            'array' => is_array($value),
            'callable' => is_callable($value),
            default => true
        };
    }
}

class ValidationResult
{
    private bool $isValid;
    private array $errors;

    public function __construct(bool $isValid, array $errors = [])
    {
        $this->isValid = $isValid;
        $this->errors = $errors;
    }

    public function isValid(): bool
    {
        return $this->isValid;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function addError(string $error): void
    {
        $this->errors[] = $error;
        $this->isValid = false;
    }

    public function merge(ValidationResult $other): self
    {
        return new self(
            $this->isValid && $other->isValid(),
            array_merge($this->errors, $other->getErrors())
        );
    }
}

class ValidatorContext
{
    private array $state = [];
    private array $metadata = [];

    public function setState(string $key, $value): void
    {
        $this->state[$key] = $value;
    }

    public function getState(string $key)
    {
        return $this->state[$key] ?? null;
    }

    public function setMetadata(string $key, $value): void
    {
        $this->metadata[$key] = $value;
    }

    public function getMetadata(string $key)
    {
        return $this->metadata[$key] ?? null;
    }
}
