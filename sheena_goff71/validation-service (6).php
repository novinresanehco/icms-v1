<?php

namespace App\Core\Validation;

class ValidationService implements ValidationInterface
{
    private SecurityConfig $config;
    private AuditLogger $logger;
    private RuleEngine $rules;
    private IntegrityChecker $integrity;
    private MetricsCollector $metrics;

    public function __construct(
        SecurityConfig $config,
        AuditLogger $logger,
        RuleEngine $rules,
        IntegrityChecker $integrity,
        MetricsCollector $metrics
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->rules = $rules;
        $this->integrity = $integrity;
        $this->metrics = $metrics;
    }

    public function validateInput(array $data, array $rules = []): ValidationResult
    {
        $validationId = $this->metrics->startValidation();
        
        try {
            // Sanitize input
            $sanitized = $this->sanitizeInput($data);
            
            // Apply validation rules
            $rules = array_merge(
                $this->config->getDefaultRules(),
                $rules,
                $this->rules->getContextualRules($data)
            );
            
            foreach ($rules as $field => $rule) {
                $this->validateField($sanitized[$field], $rule, $validationId);
            }

            // Verify data integrity
            $this->integrity->verifyData($sanitized);
            
            // Check business rules
            $this->rules->validateBusinessRules($sanitized);
            
            return new ValidationResult(true, $sanitized);

        } catch (ValidationException $e) {
            $this->handleValidationFailure($e, $data, $validationId);
            throw $e;
        } finally {
            $this->metrics->endValidation($validationId);
        }
    }

    public function validateOutput($result): ValidationResult
    {
        $validationId = $this->metrics->startValidation();
        
        try {
            // Verify output structure
            $this->validateStructure($result);
            
            // Check data consistency
            $this->integrity->verifyConsistency($result);
            
            // Validate against output rules
            $this->rules->validateOutput($result);
            
            // Verify security constraints
            $this->validateSecurityConstraints($result);
            
            return new ValidationResult(true, $result);

        } catch (ValidationException $e) {
            $this->handleOutputFailure($e, $result, $validationId);
            throw $e;
        } finally {
            $this->metrics->endValidation($validationId);
        }
    }

    public function verifyIntegrity($data): bool
    {
        return $this->integrity->verifyHash($data) && 
               $this->integrity->verifyStructure($data) &&
               $this->integrity->verifyConsistency($data);
    }

    private function sanitizeInput(array $data): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            $sanitized[$key] = match(gettype($value)) {
                'string' => $this->sanitizeString($value),
                'array' => $this->sanitizeInput($value),
                default => $value
            };
        }
        return $sanitized;
    }

    private function sanitizeString(string $value): string
    {
        $value = strip_tags($value);
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5);
        return trim($value);
    }

    private function validateField($value, ValidationRule $rule, string $validationId): void
    {
        if (!$this->rules->validate($value, $rule)) {
            $this->logger->logValidationFailure($rule, $value, $validationId);
            throw new ValidationException("Validation failed for rule: {$rule->getName()}");
        }
    }

    private function validateStructure($result): void
    {
        if (!$this->rules->validateStructure($result)) {
            throw new ValidationException('Invalid output structure');
        }
    }

    private function validateSecurityConstraints($result): void
    {
        if (!$this->rules->validateSecurityConstraints($result)) {
            throw new SecurityValidationException('Security constraints not met');
        }
    }

    private function handleValidationFailure(
        ValidationException $e,
        array $data,
        string $validationId
    ): void {
        $this->logger->logFailure($e, $data, $validationId);
        $this->metrics->incrementFailureCount('validation');
    }

    private function handleOutputFailure(
        ValidationException $e,
        $result,
        string $validationId
    ): void {
        $this->logger->logOutputFailure($e, $result, $validationId);
        $this->metrics->incrementFailureCount('output_validation');
    }
}
