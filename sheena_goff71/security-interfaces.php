<?php

namespace App\Core\Security;

interface SecurityInterface
{
    /**
     * Validate operation security with full protection and monitoring
     *
     * @param Operation $operation The operation to validate
     * @return SecurityResult The validation result
     * @throws SecurityException If validation fails
     */
    public function validateOperation(Operation $operation): SecurityResult;
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
     * Get operation security constraints
     *
     * @return array Security constraints
     */
    public function getSecurityConstraints(): array;

    /**
     * Convert operation to array
     *
     * @return array Operation details
     */
    public function toArray(): array;
}

class SecurityResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $trackingId,
        public readonly array $validationDetails
    ) {}
}

class SecurityException extends \RuntimeException
{
    public function __construct(
        string $message = '',
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
