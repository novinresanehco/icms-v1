<?php

namespace App\Core\Services;

use App\Core\Exceptions\ValidationException;

class ValidationService implements ValidationInterface
{
    private SecurityConfig $config;
    private RuleEngine $rules;
    
    public function __construct(SecurityConfig $config, RuleEngine $rules)
    {
        $this->config = $config;
        $this->rules = $rules;
    }

    public function validateOperation(CriticalOperation $operation): bool
    {
        // Validate input data
        if (!$this->validateInput($operation->getData())) {
            return false;
        }

        // Validate business rules
        if (!$this->validateBusinessRules($operation)) {
            return false;
        }

        // Validate system state
        if (!$this->validateSystemState()) {
            return false;
        }

        return true;
    }

    public function validateInput(array $data): bool
    {
        foreach ($data as $field => $value) {
            if (!$this->validateField($field, $value)) {
                throw new ValidationException("Invalid field: $field");
            }
        }
        return true;
    }

    public function validateBusinessRules(CriticalOperation $operation): bool
    {
        return $this->rules->validateOperation($operation);
    }

    public function validateSystemState(): bool
    {
        // Verify system is in valid state for operations
        if (!$this->checkSystemHealth()) {
            return false;
        }

        // Verify security constraints
        if (!$this->checkSecurityState()) {
            return false;
        }

        // Verify resource availability
        if (!$this->checkResources()) {
            return false;
        }

        return true;
    }

    private function validateField(string $field, $value): bool
    {
        $rules = $this->config->getValidationRules($field);
        
        foreach ($rules as $rule) {
            if (!$this->validateRule($value, $rule)) {
                return false;
            }
        }
        
        return true;
    }

    private function validateRule($value, ValidationRule $rule): bool
    {
        return $rule->validate($value);
    }

    private function checkSystemHealth(): bool
    {
        // Implement system health check
        return true;
    }

    private function checkSecurityState(): bool
    {
        // Implement security state verification
        return true;
    }

    private function checkResources(): bool
    {
        // Implement resource availability check
        return true;
    }
}
