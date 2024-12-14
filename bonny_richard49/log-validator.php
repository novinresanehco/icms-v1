<?php

namespace App\Core\Logging\Validation;

class LogEntryValidator
{
    private array $rules;
    private array $levels;

    public function __construct(array $config = [])
    {
        $this->rules = $config['rules'] ?? [];
        $this->levels = $config['levels'] ?? [
            'emergency',
            'alert', 
            'critical',
            'error',
            'warning',
            'notice',
            'info',
            'debug'
        ];
    }

    public function validate(LogEntry $entry): ValidationResult
    {
        $errors = [];

        // Validate required fields
        if (!$this->validateRequiredFields($entry)) {
            $errors[] = 'Missing required fields';
        }

        // Validate log level
        if (!$this->validateLogLevel($entry->level)) {
            $errors[] = "Invalid log level: {$entry->level}";
        }

        // Validate message
        if (!$this->validateMessage($entry->message)) {
            $errors[] = 'Invalid message format';
        }

        // Validate context
        if (!$this->validateContext($entry->context)) {
            $errors[] = 'Invalid context format';
        }

        // Apply custom validation rules
        foreach ($this->rules as $rule) {
            if (!$rule->validate($entry)) {
                $errors[] = $rule->getMessage();
            }
        }

        return new ValidationResult(
            empty($errors),
            $errors
        );
    }

    protected function validateRequiredFields(LogEntry $entry): bool
    {
        return isset($entry->level) &&
               isset($entry->message) &&
               isset($entry->timestamp);
    }

    protected function validateLogLevel(string $level): bool
    {
        return in_array(strtolower($level), $this->levels);
    }

    protected function validateMessage(string $message): bool
    {
        return !empty($message) && 
               strlen($message) <= 1000 &&
               !preg_match('/[^\PC\s]/u', $message);
    }

    protected function validateContext(array $context): bool
    {
        try {
            json_encode($context, JSON_THROW_ON_ERROR);
            return true;
        } catch (\JsonException $e) {
            return false;
        }
    }
}

class ValidationResult
{
    private bool $valid;
    private array $errors;

    public function __construct(bool $valid, array $errors = [])
    {
        $this->valid = $valid;
        $this->errors = $errors;
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function addError(string $error): void
    {
        $this->errors[] = $error;
        $this->valid = false;
    }
}
