<?php

namespace App\Core\Security;

use App\Core\Contracts\ValidationInterface;
use App\Core\Exceptions\ValidationException;

class ValidationService implements ValidationInterface
{
    private array $rules;
    private array $constraints;
    private IntegrityVerifier $integrityVerifier;
    private ValidationLogger $logger;

    public function __construct(
        IntegrityVerifier $integrityVerifier,
        ValidationLogger $logger,
        array $rules = [],
        array $constraints = []
    ) {
        $this->integrityVerifier = $integrityVerifier;
        $this->logger = $logger;
        $this->rules = $rules;
        $this->constraints = $constraints;
    }

    public function validateData(array $data): bool
    {
        try {
            // Validate core data structure
            $this->validateStructure($data);
            
            // Apply validation rules
            $this->applyValidationRules($data);
            
            // Check business constraints
            $this->checkConstraints($data);
            
            // Verify data integrity
            $this->verifyDataIntegrity($data);
            
            // Log successful validation
            $this->logger->logValidation($data);
            
            return true;
            
        } catch (ValidationException $e) {
            $this->logger->logValidationFailure($data, $e);
            throw $e;
        }
    }

    public function validateResultState(OperationResult $result): bool
    {
        // Validate result structure
        if (!$this->validateResultStructure($result)) {
            return false;
        }

        // Check result integrity
        if (!$this->integrityVerifier->verifyResultIntegrity($result)) {
            return false;
        }

        // Validate business rules
        if (!$this->validateResultRules($result)) {
            return false;
        }

        return true;
    }

    public function verifyResultIntegrity(OperationResult $result): bool
    {
        return $this->integrityVerifier->verifyIntegrity(
            $result->getData(),
            $result->getChecksum()
        );
    }

    private function validateStructure(array $data): void
    {
        foreach ($this->rules['structure'] as $field => $requirements) {
            if (!$this->validateField($data, $field, $requirements)) {
                throw new ValidationException("Invalid structure for field: {$field}");
            }
        }
    }

    private function applyValidationRules(array $data): void
    {
        foreach ($this->rules['validation'] as $rule) {
            if (!$rule->validate($data)) {
                throw new ValidationException("Validation rule failed: {$rule->getName()}");
            }
        }
    }

    private function checkConstraints(array $data): void
    {
        foreach ($this->constraints as $constraint) {
            if (!$constraint->check($data)) {
                throw new ValidationException("Constraint violation: {$constraint->getMessage()}");
            }
        }
    }

    private function verifyDataIntegrity(array $data): void
    {
        if (!$this->integrityVerifier->verifyIntegrity($data)) {
            throw new ValidationException('Data integrity verification failed');
        }
    }

    private function validateField(array $data, string $field, array $requirements): bool
    {
        if (!isset($data[$field]) && $requirements['required']) {
            return false;
        }

        if (isset($data[$field])) {
            // Type validation
            if (!$this->validateType($data[$field], $requirements['type'])) {
                return false;
            }

            // Format validation
            if (isset($requirements['format']) && 
                !$this->validateFormat($data[$field], $requirements['format'])) {
                return false;
            }

            // Range validation
            if (isset($requirements['range']) && 
                !$this->validateRange($data[$field], $requirements['range'])) {
                return false;
            }
        }

        return true;
    }

    private function validateResultStructure(OperationResult $result): bool
    {
        $requiredFields = ['status', 'data', 'timestamp', 'checksum'];
        
        foreach ($requiredFields as $field) {
            if (!$result->has($field)) {
                return false;
            }
        }

        return true;
    }

    private function validateResultRules(OperationResult $result): bool
    {
        foreach ($this->rules['result'] as $rule) {
            if (!$rule->validate($result)) {
                return false;
            }
        }

        return true;
    }

    private function validateType($value, string $type): bool
    {
        return match($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'float' => is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value),
            'object' => is_object($value),
            default => false
        };
    }

    private function validateFormat($value, string $format): bool
    {
        return match($format) {
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url' => filter_var($value, FILTER_VALIDATE_URL) !== false,
            'ip' => filter_var($value, FILTER_VALIDATE_IP) !== false,
            'date' => strtotime($value) !== false,
            'json' => json_decode($value) !== null,
            default => true
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
}
