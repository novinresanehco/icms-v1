<?php

namespace App\Core\Validation;

use Illuminate\Support\Facades\Cache;
use App\Core\Contracts\ValidationInterface;
use App\Core\Exceptions\ValidationException;

class ValidationService implements ValidationInterface
{
    private array $rules = [];
    private array $customValidators = [];
    private MetricsCollector $metrics;
    private AuditLogger $auditLogger;

    public function __construct(
        MetricsCollector $metrics,
        AuditLogger $auditLogger,
        array $validationConfig = []
    ) {
        $this->metrics = $metrics;
        $this->auditLogger = $auditLogger;
        $this->rules = $validationConfig['rules'] ?? [];
        $this->initializeValidators();
    }

    public function validateInput(array $data, array $rules = []): ValidationResult
    {
        $startTime = microtime(true);
        
        try {
            $rules = $rules ?: $this->rules;
            $errors = [];

            foreach ($rules as $field => $fieldRules) {
                if (!isset($data[$field]) && $this->isRequired($fieldRules)) {
                    $errors[$field] = 'Field is required';
                    continue;
                }

                foreach ($fieldRules as $rule) {
                    $validator = $this->getValidator($rule);
                    if (!$validator->validate($data[$field] ?? null)) {
                        $errors[$field] = $validator->getMessage();
                        break;
                    }
                }
            }

            $result = new ValidationResult(empty($errors), $errors);
            
            if (!$result->isValid()) {
                $this->handleValidationFailure($data, $errors);
            }

            return $result;

        } finally {
            $this->recordMetrics(__FUNCTION__, microtime(true) - $startTime);
        }
    }

    public function validateContext(SecurityContext $context): ValidationResult
    {
        $startTime = microtime(true);
        
        try {
            // Context validation includes security checks
            if (!$this->validateSecurityContext($context)) {
                return new ValidationResult(false, ['context' => 'Invalid security context']);
            }

            // Validate integrity
            if (!$this->validateContextIntegrity($context)) {
                return new ValidationResult(false, ['integrity' => 'Context integrity check failed']);
            }

            // Validate permissions
            if (!$this->validateContextPermissions($context)) {
                return new ValidationResult(false, ['permissions' => 'Invalid context permissions']);
            }

            return new ValidationResult(true);

        } finally {
            $this->recordMetrics(__FUNCTION__, microtime(true) - $startTime);
        }
    }

    public function validateOperation(CriticalOperation $operation): ValidationResult
    {
        $startTime = microtime(true);
        
        try {
            $errors = [];

            // Validate operation parameters
            if (!$this->validateOperationParams($operation)) {
                $errors['params'] = 'Invalid operation parameters';
            }

            // Validate security requirements
            if (!$this->validateOperationSecurity($operation)) {
                $errors['security'] = 'Operation security requirements not met';
            }

            // Validate business rules
            if (!$this->validateBusinessRules($operation)) {
                $errors['business'] = 'Business rules validation failed';
            }

            return new ValidationResult(empty($errors), $errors);

        } finally {
            $this->recordMetrics(__FUNCTION__, microtime(true) - $startTime);
        }
    }

    private function validateSecurityContext(SecurityContext $context): bool
    {
        return $context->hasValidToken() && 
               $context->isWithinTimeWindow() && 
               $this->validateOrigin($context->getOrigin());
    }

    private function validateContextIntegrity(SecurityContext $context): bool
    {
        $hash = $context->getHash();
        $calculated = $this->calculateContextHash($context);
        
        return hash_equals($hash, $calculated);
    }

    private function validateContextPermissions(SecurityContext $context): bool
    {
        $requiredPermissions = $context->getRequiredPermissions();
        $userPermissions = $context->getUserPermissions();
        
        return empty(array_diff($requiredPermissions, $userPermissions));
    }

    private function validateOperationParams(CriticalOperation $operation): bool
    {
        $params = $operation->getParameters();
        $rules = $operation->getValidationRules();
        
        return $this->validateInput($params, $rules)->isValid();
    }

    private function validateOperationSecurity(CriticalOperation $operation): bool
    {
        return $operation->getSecurityLevel() >= $this->getMinimumSecurityLevel() &&
               $this->validateOperationSignature($operation);
    }

    private function validateBusinessRules(CriticalOperation $operation): bool
    {
        foreach ($operation->getBusinessRules() as $rule) {
            if (!$this->validateBusinessRule($rule, $operation)) {
                return false;
            }
        }
        return true;
    }

    private function handleValidationFailure(array $data, array $errors): void
    {
        $this->auditLogger->logValidationFailure([
            'data' => $this->sanitizeData($data),
            'errors' => $errors,
            'timestamp' => time()
        ]);
        
        $this->metrics->incrementFailureCount('validation');
    }

    private function recordMetrics(string $operation, float $duration): void
    {
        $this->metrics->record([
            'type' => 'validation',
            'operation' => $operation,
            'duration' => $duration,
            'memory' => memory_get_peak_usage(true)
        ]);
    }

    private function sanitizeData(array $data): array
    {
        return array_map(function($value) {
            if (is_string($value)) {
                return strlen($value) > 100 ? substr($value, 0, 97) . '...' : $value;
            }
            return $value;
        }, $data);
    }

    private function initializeValidators(): void
    {
        $this->customValidators = [
            'required' => new RequiredValidator(),
            'string' => new StringValidator(),
            'numeric' => new NumericValidator(),
            'email' => new EmailValidator(),
            'url' => new UrlValidator(),
            'ip' => new IpValidator(),
            'uuid' => new UuidValidator(),
            'json' => new JsonValidator()
        ];
    }
}
