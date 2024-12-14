<?php

namespace App\Core\Foundation;

/**
 * Critical system event interface for mandatory logging and monitoring
 */
interface SystemEventInterface 
{
    public function getTimestamp(): int;
    public function getSeverity(): string;
    public function getContext(): array;
    public function getStackTrace(): string;
}

/**
 * Base exception class with mandatory logging and alert capabilities
 */
abstract class CoreSystemException extends \Exception
{
    protected LoggerInterface $logger;
    protected AlertManager $alerts;
    protected array $context;

    public function __construct(
        string $message,
        int $code,
        array $context,
        LoggerInterface $logger,
        AlertManager $alerts
    ) {
        parent::__construct($message, $code);
        $this->context = $context;
        $this->logger = $logger;
        $this->alerts = $alerts;
        
        $this->logException();
        $this->triggerAlerts();
    }

    abstract protected function getSeverityLevel(): string;
    
    protected function logException(): void
    {
        $this->logger->log($this->getSeverityLevel(), $this->message, [
            'exception' => get_class($this),
            'code' => $this->code,
            'file' => $this->file,
            'line' => $this->line,
            'trace' => $this->getTraceAsString(),
            'context' => $this->context
        ]);
    }

    protected function triggerAlerts(): void
    {
        $this->alerts->trigger(new SystemAlert(
            type: 'exception',
            severity: $this->getSeverityLevel(),
            message: $this->message,
            context: $this->context
        ));
    }
}

/**
 * Core validation service with strict type checking and sanitization
 */
class CoreValidationService
{
    private SecurityService $security;
    private array $validationRules;

    public function __construct(SecurityService $security, array $validationRules) 
    {
        $this->security = $security;
        $this->validationRules = $validationRules;
    }

    /**
     * Validates and sanitizes input data with strict type checking
     *
     * @throws ValidationException
     */
    public function validate(array $data, array $rules): array
    {
        $validated = [];
        $errors = [];

        foreach ($rules as $field => $constraints) {
            if (!isset($data[$field]) && $this->isRequired($constraints)) {
                $errors[$field][] = "The {$field} field is required";
                continue;
            }

            try {
                $value = $data[$field] ?? null;
                $validated[$field] = $this->validateField($field, $value, $constraints);
            } catch (ValidationException $e) {
                $errors[$field][] = $e->getMessage();
            }
        }

        if (!empty($errors)) {
            throw new ValidationException('Validation failed', $errors);
        }

        return $validated;
    }

    /**
     * Validates individual field with type checking and sanitization
     * 
     * @throws ValidationException
     */
    private function validateField(string $field, mixed $value, array $constraints): mixed
    {
        // Type validation
        if (isset($constraints['type'])) {
            $value = $this->validateType($value, $constraints['type']);
        }

        // Security sanitization
        $value = $this->security->sanitize($value);

        // Custom validation rules
        foreach ($constraints as $rule => $parameter) {
            if (method_exists($this, "validate{$rule}")) {
                $value = $this->{"validate{$rule}"}($value, $parameter);
            }
        }

        return $value;
    }

    /**
     * Strict type validation with support for complex types
     *
     * @throws ValidationException
     */
    private function validateType(mixed $value, string $type): mixed
    {
        return match($type) {
            'string' => $this->validateString($value),
            'int' => $this->validateInt($value),
            'float' => $this->validateFloat($value),
            'bool' => $this->validateBool($value),
            'array' => $this->validateArray($value),
            'email' => $this->validateEmail($value),
            'date' => $this->validateDate($value),
            'json' => $this->validateJson($value),
            default => throw new ValidationException("Unsupported type: {$type}")
        };
    }

    private function validateString(mixed $value): string
    {
        if (!is_string($value)) {
            throw new ValidationException('Value must be a string');
        }
        return $value;
    }

    private function validateInt(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new ValidationException('Value must be numeric');
        }
        return (int)$value;
    }

    private function validateFloat(mixed $value): float
    {
        if (!is_numeric($value)) {
            throw new ValidationException('Value must be numeric');
        }
        return (float)$value;
    }

    private function validateBool(mixed $value): bool
    {
        if (!is_bool($value) && !in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
            throw new ValidationException('Value must be boolean');
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function validateArray(mixed $value): array
    {
        if (!is_array($value)) {
            throw new ValidationException('Value must be an array');
        }
        return $value;
    }

    private function validateEmail(mixed $value): string
    {
        $value = $this->validateString($value);
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('Invalid email format');
        }
        return $value;
    }

    private function validateDate(mixed $value): string
    {
        $value = $this->validateString($value);
        $date = date_parse($value);
        if ($date['error_count'] > 0) {
            throw new ValidationException('Invalid date format');
        }
        return $value;
    }

    private function validateJson(mixed $value): string
    {
        $value = $this->validateString($value);
        if (json_decode($value) === null) {
            throw new ValidationException('Invalid JSON format');
        }
        return $value;
    }

    private function isRequired(array $constraints): bool
    {
        return isset($constraints['required']) && $constraints['required'];
    }
}
