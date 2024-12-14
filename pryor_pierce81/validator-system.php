<?php

namespace App\Core\Validation;

class Validator
{
    private array $rules = [];
    private array $messages = [];
    private array $customRules = [];
    private array $data = [];
    private array $errors = [];

    public function make(array $data, array $rules, array $messages = []): ValidationResult
    {
        $this->data = $data;
        $this->rules = $rules;
        $this->messages = $messages;
        $this->errors = [];

        foreach ($rules as $field => $fieldRules) {
            $this->validateField($field, $fieldRules);
        }

        return new ValidationResult(empty($this->errors), $this->errors);
    }

    private function validateField(string $field, array|string $rules): void
    {
        $rules = is_string($rules) ? explode('|', $rules) : $rules;

        foreach ($rules as $rule) {
            $this->processRule($field, $rule);
        }
    }

    private function processRule(string $field, string $rule): void
    {
        [$ruleName, $parameters] = $this->parseRule($rule);
        $value = $this->getValue($field);

        if (isset($this->customRules[$ruleName])) {
            $valid = $this->customRules[$ruleName]($value, $parameters, $this->data);
        } else {
            $method = 'validate' . ucfirst($ruleName);
            if (!method_exists($this, $method)) {
                throw new ValidationException("Unknown validation rule: $ruleName");
            }
            $valid = $this->$method($value, $parameters);
        }

        if (!$valid) {
            $this->addError($field, $ruleName, $parameters);
        }
    }

    private function parseRule(string $rule): array
    {
        $segments = explode(':', $rule);
        $parameters = [];

        if (isset($segments[1])) {
            $parameters = explode(',', $segments[1]);
        }

        return [$segments[0], $parameters];
    }

    private function getValue(string $field)
    {
        return data_get($this->data, $field);
    }

    private function addError(string $field, string $rule, array $parameters = []): void
    {
        $message = $this->getMessage($field, $rule);
        $this->errors[$field][] = $this->replaceParameters($message, $parameters);
    }

    private function getMessage(string $field, string $rule): string
    {
        $key = "$field.$rule";
        if (isset($this->messages[$key])) {
            return $this->messages[$key];
        }

        return "Validation failed for $field using rule $rule";
    }

    private function replaceParameters(string $message, array $parameters): string
    {
        foreach ($parameters as $index => $parameter) {
            $message = str_replace(":{$index}", $parameter, $message);
        }
        return $message;
    }

    public function extend(string $rule, callable $callback): void
    {
        $this->customRules[$rule] = $callback;
    }

    protected function validateRequired($value): bool
    {
        return !is_null($value) && $value !== '';
    }

    protected function validateEmail($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    protected function validateMin($value, array $parameters): bool
    {
        $min = (int) $parameters[0];
        return is_numeric($value) ? $value >= $min : strlen($value) >= $min;
    }

    protected function validateMax($value, array $parameters): bool
    {
        $max = (int) $parameters[0];
        return is_numeric($value) ? $value <= $max : strlen($value) <= $max;
    }

    protected function validateRegex($value, array $parameters): bool
    {
        return preg_match($parameters[0], $value) > 0;
    }

    protected function validateArray($value): bool
    {
        return is_array($value);
    }

    protected function validateBoolean($value): bool
    {
        $valid = [true, false, 0, 1, '0', '1'];
        return in_array($value, $valid, true);
    }

    protected function validateDate($value): bool
    {
        return strtotime($value) !== false;
    }

    protected function validateInArray($value, array $parameters): bool
    {
        return in_array($value, $parameters);
    }

    protected function validateNumeric($value): bool
    {
        return is_numeric($value);
    }

    protected function validateInteger($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    protected function validateString($value): bool
    {
        return is_string($value);
    }

    protected function validateUrl($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    protected function validateIp($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }
}

class ValidationResult
{
    private bool $passes;
    private array $errors;

    public function __construct(bool $passes, array $errors = [])
    {
        $this->passes = $passes;
        $this->errors = $errors;
    }

    public function passes(): bool
    {
        return $this->passes;
    }

    public function fails(): bool
    {
        return !$this->passes;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getFirstError(): ?string
    {
        return !empty($this->errors) ? reset($this->errors)[0] : null;
    }
}

class ValidationException extends \Exception {}
