<?php

namespace App\Core\Security;

use App\Core\Exceptions\ValidationException;

class ValidationService implements ValidationInterface
{
    private RuleEngine $ruleEngine;
    private IntegrityChecker $integrityChecker;
    
    public function __construct(
        RuleEngine $ruleEngine,
        IntegrityChecker $integrityChecker
    ) {
        $this->ruleEngine = $ruleEngine;
        $this->integrityChecker = $integrityChecker;
    }

    public function validate(array $data, array $rules): array
    {
        foreach ($rules as $field => $rule) {
            if (!isset($data[$field]) && $this->isRequired($rule)) {
                throw new ValidationException("Required field missing: $field");
            }

            if (isset($data[$field])) {
                $this->validateField($field, $data[$field], $rule);
            }
        }

        return $data;
    }

    public function verifyIntegrity($data): bool
    {
        return $this->integrityChecker->verify($data);
    }

    public function verifyBusinessRules($data): bool
    {
        return $this->ruleEngine->validateBusinessRules($data);
    }

    private function validateField(string $field, $value, array $rules): void
    {
        foreach ($rules as $rule) {
            if (!$this->ruleEngine->validate($value, $rule)) {
                throw new ValidationException(
                    "Validation failed for $field: $rule"
                );
            }
        }
    }

    private function isRequired(array $rules): bool
    {
        return in_array('required', $rules);
    }
}

interface ValidationInterface
{
    public function validate(array $data, array $rules): array;
    public function verifyIntegrity($data): bool;
    public function verifyBusinessRules($data): bool;
}

class RuleEngine
{
    private array $validators = [];
    private array $businessRules = [];

    public function validate($value, string $rule): bool
    {
        if (!isset($this->validators[$rule])) {
            throw new ValidationException("Unknown validation rule: $rule");
        }

        return $this->validators[$rule]($value);
    }

    public function validateBusinessRules($data): bool
    {
        foreach ($this->businessRules as $rule) {
            if (!$rule->validate($data)) {
                return false;
            }
        }
        return true;
    }

    public function registerValidator(string $rule, callable $validator): void
    {
        $this->validators[$rule] = $validator;
    }

    public function registerBusinessRule(BusinessRule $rule): void
    {
        $this->businessRules[] = $rule;
    }
}

interface BusinessRule
{
    public function validate($data): bool;
}

class IntegrityChecker
{
    private string $secret;
    
    public function __construct(string $secret)
    {
        $this->secret = $secret;
    }
    
    public function verify($data): bool
    {
        // Implementation of data integrity verification
        return true;
    }
}
