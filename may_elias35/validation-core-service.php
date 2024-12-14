<?php

namespace App\Core\Validation;

class ValidationService implements ValidationInterface 
{
    private SecurityManager $security;
    private RuleEngine $rules;
    private AuditLogger $logger;
    private DataSanitizer $sanitizer;

    public function validateCriticalOperation(
        Operation $operation, 
        ValidationContext $context
    ): ValidationResult {
        DB::beginTransaction();
        
        try {
            // Pre-validation security check
            $this->security->validateContext($context);
            
            // Data sanitization
            $sanitizedData = $this->sanitizer->sanitize(
                $operation->getData(),
                $operation->getSanitizationRules()
            );
            
            // Core validation
            $this->validateData($sanitizedData, $operation->getRules());
            $this->validateBusinessRules($operation);
            $this->validateSecurityConstraints($operation);
            
            // Post-validation integrity check
            $this->verifyIntegrity($operation);
            
            DB::commit();
            
            return new ValidationResult(
                true,
                $sanitizedData,
                $this->generateValidationMetadata($operation)
            );
            
        } catch (ValidationException $e) {
            DB::rollBack();
            $this->handleValidationFailure($e, $operation, $context);
            throw $e;
        }
    }

    private function validateData(array $data, array $rules): void 
    {
        foreach ($rules as $field => $fieldRules) {
            $value = $data[$field] ?? null;
            
            foreach ($fieldRules as $rule) {
                if (!$this->rules->validate($value, $rule)) {
                    throw new ValidationException(
                        "Validation failed for field: $field, rule: $rule"
                    );
                }
            }
        }
    }

    private function validateBusinessRules(Operation $operation): void 
    {
        $rules = $operation->getBusinessRules();
        
        foreach ($rules as $rule) {
            if (!$this->rules->validateBusinessRule($rule, $operation)) {
                throw new BusinessRuleViolationException(
                    "Business rule violation: {$rule->getDescription()}"
                );
            }
        }
    }

    private function validateSecurityConstraints(Operation $operation): void 
    {
        $constraints = $operation->getSecurityConstraints();
        
        foreach ($constraints as $constraint) {
            if (!$this->security->validateConstraint($constraint)) {
                throw new SecurityConstraintViolationException(
                    "Security constraint violation: {$constraint->getDescription()}"
                );
            }
        }
    }

    private function verifyIntegrity(Operation $operation): void 
    {
        $checksum = $this->calculateChecksum($operation);
        
        if (!$this->verifyOperationChecksum($operation, $checksum)) {
            throw new IntegrityViolationException(
                "Operation integrity check failed"
            );
        }
    }

    private function handleValidationFailure(
        ValidationException $e,
        Operation $operation,
        ValidationContext $context
    ): void {
        $this->logger->logValidationFailure(
            $operation,
            $context,
            $e,
            [
                'operation_data' => $operation->getData(),
                'validation_rules' => $operation->getRules(),
                'failure_point' => $e->getFailurePoint(),
                'system_state' => $this->captureSystemState()
            ]
        );
    }

    private function generateValidationMetadata(Operation $operation): array 
    {
        return [
            'timestamp' => microtime(true),
            'operation_id' => $operation->getId(),
            'validation_rules' => $operation->getRules(),
            'security_level' => $operation->getSecurityLevel(),
            'checksum' => $this->calculateChecksum($operation)
        ];
    }

    private function calculateChecksum(Operation $operation): string 
    {
        return hash_hmac(
            'sha256',
            json_encode($operation->getData()),
            config('app.validation_key')
        );
    }

    private function verifyOperationChecksum(
        Operation $operation,
        string $checksum
    ): bool {
        return hash_equals(
            $checksum,
            $operation->getChecksum()
        );
    }

    private function captureSystemState(): array 
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'time' => microtime(true),
            'load' => sys_getloadavg()
        ];
    }
}

class DataSanitizer 
{
    private array $sanitizers = [
        'string' => 'sanitizeString',
        'email' => 'sanitizeEmail',
        'url' => 'sanitizeUrl',
        'html' => 'sanitizeHtml',
        'integer' => 'sanitizeInteger'
    ];

    public function sanitize(array $data, array $rules): array 
    {
        $sanitized = [];
        
        foreach ($data as $field => $value) {
            $sanitized[$field] = $this->sanitizeField(
                $value,
                $rules[$field] ?? []
            );
        }
        
        return $sanitized;
    }

    private function sanitizeField($value, array $rules): mixed 
    {
        foreach ($rules as $rule) {
            $method = $this->sanitizers[$rule] ?? null;
            
            if ($method && method_exists($this, $method)) {
                $value = $this->$method($value);
            }
        }
        
        return $value;
    }

    private function sanitizeString(string $value): string 
    {
        return htmlspecialchars(
            strip_tags(trim($value)),
            ENT_QUOTES,
            'UTF-8'
        );
    }

    private function sanitizeHtml(string $value): string 
    {
        return purify($value); // Using HTML Purifier
    }

    private function sanitizeInteger($value): int 
    {
        return filter_var($value, FILTER_SANITIZE_NUMBER_INT);
    }

    private function sanitizeEmail(string $value): string 
    {
        return filter_var(
            $value,
            FILTER_SANITIZE_EMAIL
        );
    }
}

interface ValidationInterface 
{
    public function validateCriticalOperation(
        Operation $operation,
        ValidationContext $context
    ): ValidationResult;
}
