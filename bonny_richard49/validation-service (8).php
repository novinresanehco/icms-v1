<?php

namespace App\Core\Security;

use App\Core\Interfaces\ValidationInterface;
use App\Core\Exceptions\ValidationException;

class ValidationService implements ValidationInterface 
{
    private RuleEngine $ruleEngine;
    private IntegrityChecker $integrityChecker;
    private BusinessRuleValidator $businessValidator;
    
    public function __construct(
        RuleEngine $ruleEngine,
        IntegrityChecker $integrityChecker,
        BusinessRuleValidator $businessValidator
    ) {
        $this->ruleEngine = $ruleEngine;
        $this->integrityChecker = $integrityChecker;
        $this->businessValidator = $businessValidator;
    }

    public function validate(array $data, array $rules): ValidationResult 
    {
        try {
            // Validate basic data constraints
            $this->validateDataConstraints($data, $rules);
            
            // Apply complex validation rules
            $this->applyValidationRules($data, $rules);
            
            // Verify business logic constraints
            $this->verifyBusinessConstraints($data, $rules);
            
            return new ValidationResult(true);
            
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ValidationException(
                'Validation failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    public function verifyIntegrity($data): bool 
    {
        return $this->integrityChecker->verify($data);
    }

    public function verifyBusinessRules($data): bool 
    {
        return $this->businessValidator->validate($data);
    }

    private function validateDataConstraints(array $data, array $rules): void 
    {
        foreach ($rules as $field => $constraints) {
            if (!isset($data[$field]) && $this->isRequired($constraints)) {
                throw new ValidationException("Required field missing: {$field}");
            }

            if (isset($data[$field])) {
                $this->validateField($field, $data[$field], $constraints);
            }
        }
    }

    private function validateField(string $field, $value, array $constraints): void 
    {
        foreach ($constraints as $constraint => $params) {
            if (!$this->ruleEngine->validate($value, $constraint, $params)) {
                throw new ValidationException(
                    "Validation failed for {$field}: {$constraint} constraint not met"
                );
            }
        }
    }

    private function applyValidationRules(array $data, array $rules): void 
    {
        $result = $this->ruleEngine->applyRules($data, $rules);
        
        if (!$result->isValid()) {
            throw new ValidationException(
                'Validation rules failed: ' . $result->getErrorMessage()
            );
        }
    }

    private function verifyBusinessConstraints(array $data, array $rules): void 
    {
        $result = $this->businessValidator->validateConstraints($data, $rules);
        
        if (!$result->isValid()) {
            throw new ValidationException(
                'Business constraints failed: ' . $result->getErrorMessage()
            );
        }
    }

    private function isRequired(array $constraints): bool 
    {
        return isset($constraints['required']) && $constraints['required'];
    }
}
