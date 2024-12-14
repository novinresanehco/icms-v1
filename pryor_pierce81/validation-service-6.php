<?php

namespace App\Core\Validation;

class ValidationService implements ValidationInterface 
{
    private array $securityRules;
    private DataSanitizer $sanitizer;
    private IntegrityChecker $integrityChecker;
    private AuditLogger $auditLogger;

    public function __construct(
        DataSanitizer $sanitizer,
        IntegrityChecker $integrityChecker,
        AuditLogger $auditLogger,
        array $securityRules = []
    ) {
        $this->sanitizer = $sanitizer;
        $this->integrityChecker = $integrityChecker;
        $this->auditLogger = $auditLogger;
        $this->securityRules = $securityRules;
    }

    public function validateInput($data, array $rules): ValidationResult 
    {
        DB::beginTransaction();
        
        try {
            // Sanitize input
            $sanitized = $this->sanitizer->sanitize($data);
            
            // Apply security rules
            $this->applySecurityRules($sanitized);
            
            // Validate business rules
            $this->validateBusinessRules($sanitized, $rules);
            
            // Check data integrity
            $this->verifyDataIntegrity($sanitized);
            
            DB::commit();
            
            return new ValidationResult(true, $sanitized);
            
        } catch (ValidationException $e) {
            DB::rollBack();
            $this->handleValidationFailure($data, $rules, $e);
            throw $e;
        }
    }

    private function applySecurityRules(array &$data): void 
    {
        foreach ($this->securityRules as $rule => $validator) {
            if (!$validator->validate($data)) {
                $this->auditLogger->logSecurityViolation($rule, $data);
                throw new SecurityValidationException("Security rule '$rule' failed");
            }
        }
    }

    private function validateBusinessRules(array $data, array $rules): void 
    {
        foreach ($rules as $field => $rule) {
            if (!$this->validateField($data[$field] ?? null, $rule)) {
                throw new BusinessValidationException("Validation failed for field '$field'");
            }
        }
    }

    public function verifyDataIntegrity(array $data): bool 
    {
        if (!$this->integrityChecker->verify($data)) {
            $this->auditLogger->logIntegrityFailure($data);
            throw new IntegrityException('Data integrity check failed');
        }
        return true;
    }

    private function validateField($value, $rule): bool 
    {
        return match ($rule['type']) {
            'required' => !empty($value),
            'numeric' => is_numeric($value),
            'string' => is_string($value),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL),
            'regex' => preg_match($rule['pattern'], $value),
            'custom' => $rule['validator']($value),
            default => throw new ValidationException("Unknown validation rule: {$rule['type']}")
        };
    }

    private function handleValidationFailure($data, $rules, ValidationException $e): void 
    {
        $this->auditLogger->logValidationFailure([
            'data' => $data,
            'rules' => $rules,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    public function validateOutput($data): bool 
    {
        try {
            return $this->sanitizer->sanitizeOutput($data) !== false;
        } catch (\Exception $e) {
            $this->auditLogger->logOutputValidationFailure($data, $e);
            throw new OutputValidationException('Output validation failed', 0, $e);
        }
    }
}
