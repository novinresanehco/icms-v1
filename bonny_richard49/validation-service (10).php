<?php

namespace App\Core\Services;

use App\Core\Contracts\ValidationInterface;
use Illuminate\Support\Facades\Validator;
use App\Core\Security\SecurityConfig;

class ValidationService implements ValidationInterface
{
    protected SecurityConfig $config;
    protected array $securityRules;

    public function __construct(SecurityConfig $config)
    {
        $this->config = $config;
        $this->securityRules = $config->getSecurityRules();
    }

    public function validateContext(array $context): bool
    {
        $rules = [
            'operation_type' => 'required|string',
            'resource' => 'required|string',
            'user_id' => 'nullable|integer',
            'payload' => 'required|array'
        ];

        return $this->validate($context, $rules);
    }

    public function validateInput(array $data, array $rules): bool
    {
        // First apply security rules
        $securityValidation = $this->validateSecurity($data);
        if (!$securityValidation) {
            return false;
        }

        // Then validate business rules
        return $this->validate($data, $rules);
    }

    public function validateSecurity(array $data): bool
    {
        foreach ($this->securityRules as $field => $rules) {
            if (isset($data[$field]) && !$this->validateField($data[$field], $rules)) {
                return false;
            }
        }
        return true;
    }

    public function validateResult($result): bool
    {
        if (is_null($result)) {
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

    public function checkSecurityConstraints(array $context): bool
    {
        // Validate IP restrictions
        if (!$this->validateIpRestrictions($context)) {
            return false;
        }

        // Check rate limits
        if (!$this->checkRateLimits($context)) {
            return false;
        }

        // Validate permissions
        if (!$this->validatePermissions($context)) {
            return false;
        }

        return true;
    }

    protected function validateField($value, array $rules): bool
    {
        foreach ($rules as $rule) {
            if (!$this->applySingleRule($value, $rule)) {
                return false;
            }
        }
        return true;
    }

    protected function validate(array $data, array $rules): bool
    {
        $validator = Validator::make($data, $rules);
        return !$validator->fails();
    }

    protected function validateArrayResult(array $result): bool
    {
        foreach ($result as $value) {
            if (is_array($value) && !$this->validateArrayResult($value)) {
                return false;
            }
        }
        return true;
    }

    protected function validateObjectResult(object $result): bool
    {
        if (method_exists($result, 'validate')) {
            return $result->validate();
        }
        return true;
    }

    protected function validateIpRestrictions(array $context): bool
    {
        $ip = $context['ip'] ?? request()->ip();
        return !in_array($ip, $this->config->getBlockedIps());
    }

    protected function checkRateLimits(array $context): bool
    {
        $key = $this->getRateLimitKey($context);
        $limit = $this->config->getRateLimit($context['operation_type']);
        $current = cache()->increment($key);
        
        return $current <= $limit;
    }

    protected function validatePermissions(array $context): bool
    {
        if (!isset($context['user_id'])) {
            return false;
        }

        $required = $this->config->getRequiredPermissions($context['operation_type']);
        $user = User::find($context['user_id']);

        return $user && $user->hasPermissions($required);
    }

    protected function getRateLimitKey(array $context): string
    {
        return sprintf(
            'rate_limit:%s:%s:%s',
            $context['operation_type'],
            $context['user_id'] ?? 'anonymous',
            request()->ip()
        );
    }
}
