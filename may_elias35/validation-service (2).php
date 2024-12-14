<?php

namespace App\Core\Services;

use App\Core\Contracts\ValidationInterface;
use App\Core\Exceptions\ValidationException;

class ValidationService implements ValidationInterface
{
    private array $securityConstraints;
    
    public function __construct(array $securityConstraints)
    {
        $this->securityConstraints = $securityConstraints;
    }

    public function validateContext(array $context): bool
    {
        // Required fields check
        $requiredFields = ['user_id', 'action', 'resource'];
        foreach ($requiredFields as $field) {
            if (!isset($context[$field])) {
                throw new ValidationException("Missing required field: $field");
            }
        }

        // Data type validation
        if (!is_int($context['user_id'])) {
            throw new ValidationException('User ID must be integer');
        }

        // Action validation
        if (!in_array($context['action'], ['create', 'read', 'update', 'delete'])) {
            throw new ValidationException('Invalid action specified');
        }

        return true;
    }

    public function checkSecurityConstraints(array $context): bool
    {
        // Input sanitization
        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $context[$key] = strip_tags($value);
            }
        }

        // Security rules check
        foreach ($this->securityConstraints as $constraint => $validator) {
            if (!$validator($context)) {
                throw new ValidationException("Security constraint failed: $constraint");
            }
        }

        return true;
    }

    public function validateResult($result): bool
    {
        if ($result === null) {
            return false;
        }

        if (is_array($result)) {
            return $this->validateArrayResult($result);
        }

        if (is_object($result)) {
            return $this->validateObjectResult($result);
        }

        return true;
    }

    private function validateArrayResult(array $result): bool
    {
        foreach ($result as $key => $value) {
            if ($value === null) {
                throw new ValidationException("Null value detected in result array at key: $key");
            }
        }
        return true;
    }

    private function validateObjectResult(object $result): bool
    {
        $properties = get_object_vars($result);
        foreach ($properties as $property => $value) {
            if ($value === null) {
                throw new ValidationException("Null value detected in result object for property: $property");
            }
        }
        return true;
    }
}
