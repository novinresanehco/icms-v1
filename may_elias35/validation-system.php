<?php

namespace App\Core\Validation;

use App\Core\Security\SecurityContext;
use App\Core\Monitoring\SystemMonitor;

class ValidationManager implements ValidationInterface
{
    private SecurityContext $security;
    private SystemMonitor $monitor;
    private array $config;
    private array $validators = [];

    public function __construct(
        SecurityContext $security,
        SystemMonitor $monitor,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->config = $config;
        $this->initializeValidators();
    }

    public function validateInput(array $data, array $rules): ValidationResult
    {
        $monitoringId = $this->monitor->startOperation('input_validation');

        try {
            $this->checkInputSize($data);
            $this->validateStructure($data, $rules);
            $this->validateDataTypes($data, $rules);
            $this->validateBusinessRules($data, $rules);
            $this->validateSecurityRules($data);

            return new ValidationResult(true);

        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new ValidationException('Input validation failed', 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function validateCriticalOperation(CriticalOperation $operation): bool
    {
        $monitoringId = $this->monitor->startOperation('operation_validation');

        try {
            $type = $operation->getType();
            $rules = $this->config['operations'][$type] ?? null;

            if (!$rules) {
                throw new ValidationException('Unknown operation type');
            }

            return $this->validateOperationRules($operation, $rules);

        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function validateOperationRules(CriticalOperation $operation, array $rules): bool
    {
        foreach ($this->validators as $validator) {
            if (!$validator->validateOperation($operation, $rules)) {
                return false;
            }
        }
        return true;
    }

    private function checkInputSize(array $data): void
    {
        $size = strlen(serialize($data));
        if ($size > $this->config['max_input_size']) {
            throw new ValidationException('Input size exceeds limit');
        }
    }

    private function validateStructure(array $data, array $rules): void
    {
        foreach ($rules['required'] ?? [] as $field) {
            if (!isset($data[$field])) {
                throw new ValidationException("Missing required field: {$field}");
            }
        }
    }

    private function validateDataTypes(array $data, array $rules): void
    {
        foreach ($rules['types'] ?? [] as $field => $type) {
            if (isset($data[$field]) && !$this->validateType($data[$field], $type)) {
                throw new ValidationException("Invalid type for field: {$field}");
            }
        }
    }

    private function validateType($value, string $type): bool
    {
        return match($type) {
            'string' => is_string($value),
            'int' => is_int($value),
            'float' => is_float($value),
            'bool' => is_bool($value),
            'array' => is_array($value),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url' => filter_var($value, FILTER_VALIDATE_URL) !== false,
            default => false
        };
    }

    private function validateBusinessRules(array $data, array $rules): void
    {
        foreach ($rules['business'] ?? [] as $rule) {
            if (!$this->evaluateBusinessRule($data, $rule)) {
                throw new ValidationException("Business rule violation: {$rule['name']}");
            }
        }
    }

    private function validateSecurityRules(array $data): void
    {
        // Sanitize input
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = $this->sanitizeInput($value);
            }
        }

        // Check for security patterns
        foreach ($data as $value) {
            if (is_string($value) && $this->containsMaliciousPattern($value)) {
                throw new SecurityValidationException('Potential security threat detected');
            }
        }
    }

    private function sanitizeInput(string $value): string
    {
        $value = strip_tags($value, $this->config['allowed_tags'] ?? '');
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $value;
    }

    private function containsMaliciousPattern(string $value): bool
    {
        foreach ($this->config['malicious_patterns'] as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }
        return false;
    }

    private function initializeValidators(): void
    {
        $this->validators = [
            new InputValidator($this->config['input_validation']),
            new SecurityValidator($this->config['security_validation']),
            new BusinessValidator($this->config['business_validation'])
        ];
    }
}

class SecurityValidator implements ValidatorInterface
{
    private array $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function validateOperation(CriticalOperation $operation, array $rules): bool
    {
        // Validate security requirements
        $securityRules = $rules['security'] ?? [];
        
        foreach ($securityRules as $rule) {
            if (!$this->validateSecurityRule($operation, $rule)) {
                return false;
            }
        }

        return true;
    }

    private function validateSecurityRule(CriticalOperation $operation, array $rule): bool
    {
        return match($rule['type']) {
            'permission' => $this->validatePermission($operation, $rule),
            'authentication' => $this->validateAuthentication($operation, $rule),
            'encryption' => $this->validateEncryption($operation, $rule),
            default => false
        };
    }
}

class BusinessValidator implements ValidatorInterface
{
    private array $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function validateOperation(CriticalOperation $operation, array $rules): bool
    {
        // Validate business rules
        $businessRules = $rules['business'] ?? [];
        
        foreach ($businessRules as $rule) {
            if (!$this->validateBusinessRule($operation, $rule)) {
                return false;
            }
        }

        return true;
    }

    private function validateBusinessRule(CriticalOperation $operation, array $rule): bool
    {
        return match($rule['type']) {
            'limit' => $this->validateLimit($operation, $rule),
            'dependency' => $this->validateDependency($operation, $rule),
            'state' => $this->validateState($operation, $rule),
            default => false
        };
    }
}
