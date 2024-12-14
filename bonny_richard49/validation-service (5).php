<?php

namespace App\Core\Services;

use App\Core\Contracts\ValidationServiceInterface;
use App\Core\Exceptions\ValidationException;
use Illuminate\Support\Facades\Validator;

class ValidationService implements ValidationServiceInterface
{
    /**
     * Validate operation context meets security requirements
     */
    public function validateContext(array $context): bool
    {
        $rules = [
            'user_id' => 'required|integer',
            'operation' => 'required|string',
            'timestamp' => 'required|date',
            'ip_address' => 'required|ip',
            'session_id' => 'required|string'
        ];

        $validator = Validator::make($context, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors()->first());
        }

        // Additional security validations
        $this->validateSecurityRequirements($context);
        
        return true;
    }

    /**
     * Validate operation result
     */
    public function validateResult($result): bool
    {
        if ($result === null) {
            throw new ValidationException('Operation returned null result');
        }

        if (is_array($result)) {
            return $this->validateArrayResult($result);
        }

        if (is_object($result)) {
            return $this->validateObjectResult($result);
        }

        return true;
    }

    /**
     * Perform additional security requirement validations
     */
    private function validateSecurityRequirements(array $context): void
    {
        // Validate IP whitelist if required
        if (!$this->validateIpAddress($context['ip_address'])) {
            throw new ValidationException('IP address not authorized');
        }

        // Validate session expiration
        if (!$this->validateSession($context['session_id'])) {
            throw new ValidationException('Session expired or invalid');
        }

        // Validate rate limits
        if (!$this->checkRateLimits($context)) {
            throw new ValidationException('Rate limit exceeded');
        }
    }

    /**
     * Validate array result
     */
    private function validateArrayResult(array $result): bool
    {
        // Ensure required fields are present
        foreach ($this->getRequiredFields() as $field) {
            if (!isset($result[$field])) {
                throw new ValidationException("Missing required field: {$field}");
            }
        }

        // Validate data integrity
        foreach ($result as $key => $value) {
            if (!$this->isValidValue($value)) {
                throw new ValidationException("Invalid value for field: {$key}");
            }
        }

        return true;
    }

    /**
     * Validate object result
     */
    private function validateObjectResult(object $result): bool
    {
        // Ensure object has required methods
        foreach ($this->getRequiredMethods() as $method) {
            if (!method_exists($result, $method)) {
                throw new ValidationException("Missing required method: {$method}");
            }
        }

        return true;
    }

    private function validateIpAddress(string $ip): bool
    {
        // Implement IP whitelist check
        return true;
    }

    private function validateSession(string $sessionId): bool
    {
        // Implement session validation
        return true;
    }

    private function checkRateLimits(array $context): bool
    {
        // Implement rate limiting
        return true;
    }

    private function getRequiredFields(): array
    {
        return ['id', 'created_at', 'updated_at'];
    }

    private function getRequiredMethods(): array
    {
        return ['getId', 'toArray'];
    }

    private function isValidValue($value): bool
    {
        return !is_null($value);
    }
}
