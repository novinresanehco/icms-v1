<?php

namespace App\Core\Validation;

use App\Core\Security\SecurityContext;
use App\Core\Security\OperationResult;

class ValidationService implements ValidationInterface
{
    private array $validators;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->initializeValidators();
    }

    public function validateInput(array $data): bool
    {
        foreach ($this->validators as $validator) {
            if (!$validator->validate($data)) {
                return false;
            }
        }
        
        return $this->validateDataStructure($data) &&
               $this->validateDataTypes($data) &&
               $this->validateDataValues($data);
    }

    public function verifyResult(OperationResult $result): bool
    {
        return $this->validateResultStructure($result) &&
               $this->validateResultIntegrity($result) &&
               $this->validateResultSecurity($result);
    }

    public function validateSecurityContext(SecurityContext $context): bool
    {
        return $this->validateContextAuthentication($context) &&
               $this->validateContextAuthorization($context) &&
               $this->validateContextIntegrity($context);
    }

    private function initializeValidators(): void
    {
        $this->validators = [
            new InputValidator($this->config['input']),
            new SecurityValidator($this->config['security']),
            new IntegrityValidator($this->config['integrity'])
        ];
    }

    private function validateDataStructure(array $data): bool
    {
        $requiredFields = $this->config['required_fields'];
        
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                return false;
            }
        }
        
        return true;
    }

    private function validateDataTypes(array $data): bool
    {
        $typeRules = $this->config['type_rules'];
        
        foreach ($data as $field => $value) {
            if (isset($typeRules[$field])) {
                if (!$this->validateType($value, $typeRules[$field])) {
                    return false;
                }
            }
        }
        
        return true;
    }

    private function validateDataValues(array $data): bool
    {
        $valueRules = $this->config['value_rules'];
        
        foreach ($data as $field => $value) {
            if (isset($valueRules[$field])) {
                if (!$this->validateValue($value, $valueRules[$field])) {
                    return false;
                }
            }
        }
        
        return true;
    }

    private function validateType($value, string $expectedType): bool
    {
        switch ($expectedType) {
            case 'string':
                return is_string($value);
            case 'int':
                return is_int($value);
            case 'array':
                return is_array($value);
            case 'bool':
                return is_bool($value);
            default:
                return false;
        }
    }

    private function validateValue($value, array $rules): bool
    {
        foreach ($rules as $rule => $params) {
            if (!$this->applyValidationRule($value, $rule, $params)) {
                return false;
            }
        }
        return true;
    }

    private function validateResultStructure(OperationResult $result): bool
    {
        return $result->hasRequiredFields() &&
               $result->isStructureValid();
    }

    private function validateResultIntegrity(OperationResult $result): bool
    {
        return $result->verifyChecksum() &&
               $result->validateDataConsistency();
    }

    private function validateResultSecurity(OperationResult $result): bool
    {
        return $result->isSecurityCompliant() &&
               $result->validateAccessControl();
    }
}
