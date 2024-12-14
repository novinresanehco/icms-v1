<?php

namespace App\Core\Validation;

interface ValidationInterface
{
    /**
     * Validate operation with full protection and monitoring
     *
     * @param Operation $operation Operation to validate
     * @return ValidationResult The validation result
     * @throws ValidationException If validation fails
     */
    public function validateOperation(Operation $operation): ValidationResult; 
}

interface Operation
{
    /**
     * Get operation data for validation
     *
     * @return array Operation data
     */
    public function getData(): array;

    /**
     * Get validation requirements
     *
     * @return array Validation requirements
     */
    public function getValidationRequirements(): array;

    /**
     * Convert operation to array
     *
     * @return array Operation details
     */
    public function toArray(): array;
}

class ValidationResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $trackingId,
        public readonly array $details
    ) {}
}

class ValidationPhaseResult
{
    public function __construct(
        public readonly string $phase,
        public readonly bool $passed,
        public readonly array $violations
    ) {}

    public function passed(): bool
    {
        return $this->passed;
    }
}

class ValidationException extends \RuntimeException
{
    public function __construct(
        string $message = '',
        ?\Throwable $previous = null  
    ) {
        parent::__construct($message, 0, $previous);
    }
}
