<?php

namespace App\Core\Validation;

use App\Core\Security\SecurityManager;
use App\Core\Monitoring\SystemMonitor;
use App\Core\Encryption\EncryptionService;
use App\Core\Exceptions\ValidationException;

class ValidationService implements ValidationInterface
{
    private SecurityManager $security;
    private SystemMonitor $monitor;
    private EncryptionService $encryption;
    private array $config;

    public function __construct(
        SecurityManager $security,
        SystemMonitor $monitor,
        EncryptionService $encryption,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->encryption = $encryption;
        $this->config = $config;
    }

    public function validateData(array $data, array $rules = []): array
    {
        $monitoringId = $this->monitor->startOperation('data_validation');
        
        try {
            $this->validateStructure($data);
            $this->validateSecurity($data);
            $this->validateBusinessRules($data, $rules);
            
            $validatedData = $this->sanitizeData($data);
            
            $this->monitor->recordSuccess($monitoringId);
            
            return $validatedData;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new ValidationException('Data validation failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function validateOperation(Operation $operation): bool
    {
        $monitoringId = $this->monitor->startOperation('operation_validation');
        
        try {
            $this->validateOperationType($operation);
            $this->validateOperationContext($operation);
            $this->validateOperationParameters($operation);
            
            $this->monitor->recordSuccess($monitoringId);
            
            return true;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new ValidationException('Operation validation failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function validateStructure(array $data): void
    {
        foreach ($this->config['required_fields'] as $field) {
            if (!isset($data[$field])) {
                throw new ValidationException("Required field missing: {$field}");
            }
        }

        foreach ($data as $field => $value) {
            if (!$this->isValidDataType($field, $value)) {
                throw new ValidationException("Invalid data type for field: {$field}");
            }
        }
    }

    private function validateSecurity(array $data): void
    {
        if (!$this->security->validateInputSecurity($data)) {
            throw new ValidationException('Security validation failed');
        }

        foreach ($data as $field => $value) {
            if ($this->isSecurityCritical($field)) {
                $this->validateSecurityField($field, $value);
            }
        }
    }

    private function validateBusinessRules(array $data, array $rules): void
    {
        foreach ($rules as $rule) {
            if (!$this->evaluateBusinessRule($rule, $data)) {
                throw new ValidationException("Business rule validation failed: {$rule->getName()}");
            }
        }
    }

    private function sanitizeData(array $data): array
    {
        $sanitized = [];
        
        foreach ($data as $field => $value) {
            $sanitized[$field] = $this->sanitizeField($field, $value);
        }
        
        return $sanitized;
    }

    private function validateOperationType(Operation $operation): void
    {
        if (!in_array($operation->getType(), $this->config['allowed_operations'])) {
            throw new ValidationException('Invalid operation type');
        }
    }

    private function validateOperationContext(Operation $operation): void
    {
        $context = $operation->getContext();
        
        if (!$this->security->validateContext($context)) {
            throw new ValidationException('Invalid operation context');
        }
    }

    private function validateOperationParameters(Operation $operation): void
    {
        $parameters = $operation->getParameters();
        $requiredParams = $this->config['operation_parameters'][$operation->getType()] ?? [];
        
        foreach ($requiredParams as $param => $rules) {
            if (!isset($parameters[$param])) {
                throw new ValidationException("Missing required parameter: {$param}");
            }
            
            if (!$this->validateParameter($parameters[$param], $rules)) {
                throw new ValidationException("Invalid parameter: {$param}");
            }
        }
    }

    private function isValidDataType(string $field, $value): bool
    {
        $expectedType = $this->config['field_types'][$field] ?? 'string';
        
        return match($expectedType) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'float' => is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value),
            'date' => $this->isValidDate($value),
            default => false
        };
    }

    private function isSecurityCritical(string $field): bool
    {
        return in_array($field, $this->config['security_critical_fields']);
    }

    private function validateSecurityField(string $field, $value): void
    {
        if ($this->config['encryption_required'][$field] ?? false) {
            $this->validateEncryption($value);
        }

        if ($this->config['hash_required'][$field] ?? false) {
            $this->validateHash($value);
        }
    }

    private function validateEncryption($value): void
    {
        if (!$this->encryption->isEncrypted($value)) {
            throw new ValidationException('Value must be encrypted');
        }
    }

    private function validateHash($value): void
    {
        if (!$this->security->verifyHash($value)) {
            throw new ValidationException('Invalid hash value');
        }
    }

    private function validateParameter($value, array $rules): bool
    {
        foreach ($rules as $rule => $constraint) {
            if (!$this->evaluateRule($rule, $value, $constraint)) {
                return false;
            }
        }
        return true;
    }

    private function evaluateBusinessRule(BusinessRule $rule, array $data): bool
    {
        return $rule->evaluate($data);
    }

    private function sanitizeField(string $field, $value)
    {
        if (is_string($value)) {
            $value = $this->sanitizeString($value);
        }

        if (is_array($value)) {
            $value = $this->sanitizeArray($value);
        }

        return $value;
    }

    private function sanitizeString(string $value): string
    {
        $value = trim($value);
        $value = strip_tags($value);
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        return $value;
    }

    private function sanitizeArray(array $value): array
    {
        return array_map(function ($item) {
            if (is_string($item)) {
                return $this->sanitizeString($item);
            }
            if (is_array($item)) {
                return $this->sanitizeArray($item);
            }
            return $item;
        }, $value);
    }

    private function isValidDate($value): bool
    {
        if (!is_string($value)) return false;
        
        try {
            new \DateTime($value);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
