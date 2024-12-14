<?php

namespace App\Core\Security\Services;

use Illuminate\Support\Facades\Cache;
use App\Core\Interfaces\ValidationInterface;
use App\Core\Security\Exceptions\{
    ValidationException,
    SecurityConstraintException,
    SystemStateException
};

class ValidationService implements ValidationInterface
{
    private SecurityConfig $config;
    private AuditLogger $auditLogger;
    private array $validationRules;

    public function __construct(
        SecurityConfig $config,
        AuditLogger $auditLogger
    ) {
        $this->config = $config;
        $this->auditLogger = $auditLogger;
        $this->validationRules = $this->config->getValidationRules();
    }

    public function validateInput(array $data): bool
    {
        DB::beginTransaction();
        
        try {
            // Sanitize and validate each field
            foreach ($data as $field => $value) {
                $this->validateField($field, $value);
                $this->sanitizeField($field, $value);
                $this->checkSecurityConstraints($field, $value);
            }

            // Validate cross-field dependencies
            $this->validateCrossFieldRules($data);

            // Log successful validation
            $this->auditLogger->logValidation('input', $data, true);
            
            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->auditLogger->logValidation('input', $data, false, $e);
            throw new ValidationException(
                'Input validation failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    public function validateOperation(CriticalOperation $operation): bool
    {
        try {
            // Validate operation context
            $this->validateContext($operation->getContext());

            // Validate operation parameters
            $this->validateParameters($operation->getParameters());

            // Check operation-specific security constraints
            $this->validateOperationSecurity($operation);

            return true;

        } catch (\Exception $e) {
            $this->auditLogger->logValidationFailure('operation', $operation, $e);
            throw $e;
        }
    }

    public function validateResult(OperationResult $result): bool
    {
        try {
            // Validate result structure
            $this->validateResultStructure($result);

            // Verify data integrity
            $this->verifyResultIntegrity($result);

            // Check security constraints on result
            $this->validateResultSecurity($result);

            return true;

        } catch (\Exception $e) {
            $this->auditLogger->logValidationFailure('result', $result, $e);
            throw $e;
        }
    }

    protected function validateField(string $field, $value): void
    {
        $rules = $this->validationRules[$field] ?? null;
        
        if (!$rules) {
            throw new ValidationException("No validation rules found for field: {$field}");
        }

        foreach ($rules as $rule => $parameters) {
            if (!$this->checkRule($value, $rule, $parameters)) {
                throw new ValidationException(
                    "Field {$field} failed validation rule: {$rule}"
                );
            }
        }
    }

    protected function sanitizeField(string $field, &$value): void
    {
        $sanitizers = $this->config->getSanitizers($field);
        
        foreach ($sanitizers as $sanitizer) {
            $value = $this->applySanitizer($value, $sanitizer);
        }
    }

    protected function validateCrossFieldRules(array $data): void
    {
        $crossFieldRules = $this->config->getCrossFieldRules();
        
        foreach ($crossFieldRules as $rule) {
            if (!$this->checkCrossFieldRule($data, $rule)) {
                throw new ValidationException(
                    "Cross-field validation failed for rule: {$rule['name']}"
                );
            }
        }
    }

    protected function checkSecurityConstraints($field, $value): void
    {
        $constraints = $this->config->getSecurityConstraints($field);
        
        foreach ($constraints as $constraint) {
            if (!$this->validateConstraint($value, $constraint)) {
                throw new SecurityConstraintException(
                    "Security constraint failed for field: {$field}"
                );
            }
        }
    }

    protected function validateResultStructure(OperationResult $result): void
    {
        $requiredFields = $this->config->getRequiredResultFields();
        
        foreach ($requiredFields as $field) {
            if (!$result->hasField($field)) {
                throw new ValidationException(
                    "Required result field missing: {$field}"
                );
            }
        }
    }

    protected function verifyResultIntegrity(OperationResult $result): void
    {
        if (!$result->verifyChecksum()) {
            throw new ValidationException("Result integrity check failed");
        }
    }
}
