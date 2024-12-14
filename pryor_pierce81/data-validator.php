<?php

namespace App\Core\Validation;

class DataValidator
{
    private array $rules = [];
    private array $customRules = [];
    private array $messages = [];
    
    public function validate(array $data, array $rules): ValidationResult
    {
        $errors = [];
        
        foreach ($rules as $field => $fieldRules) {
            $value = $data[$field] ?? null;
            
            foreach ($fieldRules as $rule) {
                $ruleResult = $this->validateRule($field, $value, $rule);
                if (!$ruleResult->isValid()) {
                    $errors[$field][] = $ruleResult->getMessage();
                }
            }
        }
        
        return new ValidationResult(empty($errors), $errors);
    }
    
    public function addRule(string $name, callable $validator): void
    {
        $this->customRules[$name] = $validator;
    }
    
    private function validateRule(string $field, $value, array $rule): RuleResult
    {
        $ruleName = $rule['rule'];
        $params = $rule['params'] ?? [];
        
        if (isset($this->customRules[$ruleName])) {
            return $this->customRules[$ruleName]($value, $params);
        }
        
        switch ($ruleName) {
            case 'required':
                return $this->validateRequired($value);
            case 'string':
                return $this->validateString($value);
            case 'integer':
                return $this->validateInteger($value);
            case 'email':
                return $this->validateEmail($value);
            default:
                throw new ValidationException("Unknown validation rule: {$ruleName}");
        }
    }

    private function validateRequired($value): RuleResult
    {
        return new RuleResult(
            !empty($value),
            'This field is required'
        );
    }

    private function validateString($value): RuleResult
    {
        return new RuleResult(
            is_string($value),
            'This field must be a string'
        );
    }

    private function validateInteger($value): RuleResult
    {
        return new RuleResult(
            is_int($value),
            'This field must be an integer'
        );
    }

    private function validateEmail($value): RuleResult
    {
        return new RuleResult(
            filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'This field must be a valid email address'
        );
    }
}
