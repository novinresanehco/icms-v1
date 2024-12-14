<?php

namespace App\Core\Security;

class ValidationService implements ValidationInterface
{
    private ConfigurationManager $config;
    private RuleEngine $rules;
    private IntegrityVerifier $integrity;
    private MetricsCollector $metrics;

    public function __construct(
        ConfigurationManager $config,
        RuleEngine $rules,
        IntegrityVerifier $integrity,
        MetricsCollector $metrics
    ) {
        $this->config = $config;
        $this->rules = $rules;
        $this->integrity = $integrity;
        $this->metrics = $metrics;
    }

    public function validate(array $data, string $context = null): ValidationResult
    {
        $validationId = $this->metrics->startValidation($context);

        try {
            // Input sanitization
            $sanitized = $this->sanitizeInput($data);

            // Schema validation
            $this->validateSchema($sanitized, $context);

            // Business rules validation
            $this->validateBusinessRules($sanitized, $context);

            // Security rules validation
            $this->validateSecurityRules($sanitized, $context);

            // Record success metrics
            $this->metrics->recordValidationSuccess($validationId);

            return new ValidationResult(true, $sanitized);

        } catch (ValidationException $e) {
            $this->metrics->recordValidationFailure($validationId, $e);
            throw $e;
        }
    }

    public function verifyIntegrity($data, array $options = []): bool
    {
        return $this->integrity->verify($data, array_merge(
            $this->config->get('integrity.default_options'),
            $options
        ));
    }

    private function sanitizeInput(array $data): array
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeInput($value);
            } else {
                $sanitized[$key] = $this->sanitizeValue($value, $key);
            }
        }

        return $sanitized;
    }

    private function sanitizeValue($value, string $key)
    {
        $rules = $this->rules->getSanitizationRules($key);
        
        foreach ($rules as $rule) {
            $value = $rule->apply($value);
        }

        return $value;
    }

    private function validateSchema(array $data, ?string $context): void
    {
        $schema = $this->rules->getSchemaRules($context);
        
        foreach ($schema as $field => $rules) {
            if (!isset($data[$field]) && $rules['required'] ?? false) {
                throw new ValidationException("Required field missing: {$field}");
            }

            if (isset($data[$field])) {
                $this->validateField($data[$field], $rules, $field);
            }
        }
    }

    private function validateField($value, array $rules, string $field): void
    {
        foreach ($rules as $rule => $params) {
            if (!$this->rules->validateRule($value, $rule, $params)) {
                throw new ValidationException("Validation failed for {$field}: {$rule}");
            }
        }
    }

    private function validateBusinessRules(array $data, ?string $context): void
    {
        $rules = $this->rules->getBusinessRules($context);
        
        foreach ($rules as $rule) {
            if (!$rule->validate($data)) {
                throw new BusinessRuleValidationException($rule->getMessage());
            }
        }
    }

    private function validateSecurityRules(array $data, ?string $context): void
    {
        $rules = $this->rules->getSecurityRules($context);
        
        foreach ($rules as $rule) {
            if (!$rule->validate($data)) {
                throw new SecurityValidationException(
                    "Security validation failed: {$rule->getMessage()}"
                );
            }
        }
    }

    public function validateResult(Result $result): bool
    {
        return $this->rules->validateResultRules($result);
    }

    public function validateBusinessLogic(array $data, string $operation): bool
    {
        $rules = $this->rules->getBusinessLogicRules($operation);
        
        foreach ($rules as $rule) {
            if (!$rule->validate($data)) {
                return false;
            }
        }

        return true;
    }
}

interface ValidationInterface
{
    public function validate(array $data, string $context = null): ValidationResult;
    public function verifyIntegrity($data, array $options = []): bool;
    public function validateResult(Result $result): bool;
    public function validateBusinessLogic(array $data, string $operation): bool;
}

class ValidationResult
{
    private bool $success;
    private array $data;
    private array $errors;

    public function __construct(bool $success, array $data = [], array $errors = [])
    {
        $this->success = $success;
        $this->data = $data;
        $this->errors = $errors;
    }

    public function isValid(): bool
    {
        return $this->success;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
