<?php

namespace App\Core\Validation;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\ValidationException;
use Psr\Log\LoggerInterface;

class ValidationService implements ValidationInterface
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $rules = [];
    private array $customValidators = [];

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger
    ) {
        $this->security = $security;
        $this->logger = $logger;
    }

    public function validate($data, array $rules): array
    {
        $violations = [];
        
        try {
            // Security validation
            $this->security->validateOperation('validation:execute', gettype($data));

            // Apply validation rules
            foreach ($rules as $field => $fieldRules) {
                $value = $this->extractValue($data, $field);
                $fieldViolations = $this->validateField($field, $value, $fieldRules);
                
                if (!empty($fieldViolations)) {
                    $violations[$field] = $fieldViolations;
                }
            }

            // Log validation results
            $this->logValidation($data, $rules, $violations);

            return $violations;

        } catch (\Exception $e) {
            throw new ValidationException('Validation failed', 0, $e);
        }
    }

    public function addRule(string $name, callable $validator): void
    {
        if (isset($this->rules[$name])) {
            throw new ValidationException("Validation rule already exists: {$name}");
        }

        $this->rules[$name] = $validator;
    }

    public function addCustomValidator(
        string $name,
        CustomValidatorInterface $validator
    ): void {
        if (isset($this->customValidators[$name])) {
            throw new ValidationException("Custom validator already exists: {$name}");
        }

        $this->customValidators[$name] = $validator;
    }

    private function validateField(
        string $field,
        $value,
        string $rules
    ): array {
        $violations = [];
        $rulesList = explode('|', $rules);

        foreach ($rulesList as $rule) {
            $params = [];
            
            if (strpos($rule, ':') !== false) {
                [$rule, $paramStr] = explode(':', $rule, 2);
                $params = explode(',', $paramStr);
            }

            try {
                $valid = $this->executeRule($rule, $value, $params);
                
                if (!$valid) {
                    $violations[] = $this->formatViolation($field, $rule, $params);
                }
                
            } catch (\Exception $e) {
                throw new ValidationException(
                    "Validation rule execution failed: {$rule}",
                    0,
                    $e
                );
            }
        }

        return $violations;
    }

    private function executeRule(
        string $rule,
        $value