<?php

namespace App\Core\Validation;

use App\Core\Contracts\ValidationServiceInterface;
use App\Core\Exceptions\ValidationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;

class ValidationService implements ValidationServiceInterface
{
    private RuleEngine $ruleEngine;
    private SecurityValidator $securityValidator;
    private ConstraintValidator $constraintValidator;
    private ResultValidator $resultValidator;
    private array $config;

    public function __construct(
        RuleEngine $ruleEngine,
        SecurityValidator $securityValidator,
        ConstraintValidator $constraintValidator,
        ResultValidator $resultValidator,
        array $config
    ) {
        $this->ruleEngine = $ruleEngine;
        $this->securityValidator = $securityValidator;
        $this->constraintValidator = $constraintValidator;
        $this->resultValidator = $resultValidator;
        $this->config = $config;
    }

    public function validateContext(array $context): bool
    {
        $validationId = $this->startValidation($context);

        try {
            $this->validateRequiredFields($context);
            $this->validateDataTypes($context);
            $this->validateBusinessRules($context);
            $this->validateSecurityConstraints($context);

            $this->completeValidation($validationId, true);
            return true;

        } catch (\Exception $e) {
            $this->handleValidationFailure($validationId, $e, $context);
            throw new ValidationException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function validateResult($result): bool
    {
        return $this->resultValidator->validate($result, [
            'integrity' => $this->config['result_integrity_rules'],
            'security' => $this->config['result_security_rules'],
            'business' => $this->config['result_business_rules']
        ]);
    }

    public function checkSecurityConstraints(array $context): bool
    {
        return $this->securityValidator->validate($context, [
            'input_sanitization' => true,
            'access_validation' => true,
            'injection_prevention' => true,
            'xss_protection' => true
        ]);
    }

    private function validateRequiredFields(array $context): void
    {
        $missingFields = Collection::make($this->config['required_fields'])
            ->reject(fn($field) => isset($context[$field]))
            ->toArray();

        if (!empty($missingFields)) {
            throw new ValidationException('Missing required fields: ' . implode(', ', $missingFields));
        }
    }

    private function validateDataTypes(array $context): void
    {
        foreach ($this->config['field_types'] as $field => $type) {
            if (isset($context[$field]) && !$this->validateType($context[$field], $type)) {
                throw new ValidationException("Invalid type for field {$field}. Expected {$type}");
            }
        }
    }

    private function validateBusinessRules(array $context): void
    {
        $violations = $this->ruleEngine->validateRules($context);

        if (!empty($violations)) {
            throw new ValidationException('Business rule violations: ' . implode(', ', $violations));
        }
    }

    private function validateType($value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'float' => is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value),
            'object' => is_object($value),
            default => false
        };
    }

    private function startValidation(array $context): string
    {
        $validationId = uniqid('val_', true);
        
        Cache::put("validation:{$validationId}", [
            'status' => 'in_progress',
            'context' => $context,
            'start_time' => microtime(true)
        ], 3600);

        return $validationId;
    }

    private function completeValidation(string $validationId, bool $success): void
    {
        $validation = Cache::get("validation:{$validationId}");
        $validation['status'] = $success ? 'completed' : 'failed';
        $validation['end_time'] = microtime(true);
        
        Cache::put("validation:{$validationId}", $validation, 3600);
    }

    private function handleValidationFailure(string $validationId, \Exception $e, array $context): void
    {
        $this->completeValidation($validationId, false);

        Log::error('Validation failed', [
            'validation_id' => $validationId,
            'error' => $e->getMessage(),
            'context' => $context,
            'trace' => $e->getTraceAsString()
        ]);
    }
}

class RuleEngine
{
    private Collection $rules;
    private RuleValidator $validator;

    public function __construct(RuleValidator $validator)
    {
        $this->validator = $validator;
        $this->rules = new Collection();
    }

    public function validateRules(array $context): array
    {
        return $this->rules
            ->map(fn($rule) => $this->validateRule($rule, $context))
            ->filter()
            ->values()
            ->toArray();
    }

    private function validateRule(Rule $rule, array $context): ?string
    {
        return $this->validator->validate($rule, $context);
    }
}

class SecurityValidator 
{
    private array $sanitizationRules;
    private array $securityChecks;

    public function validate(array $context, array $requirements): bool
    {
        foreach ($requirements as $requirement => $enabled) {
            if ($enabled && !$this->validateRequirement($requirement, $context)) {
                return false;
            }
        }
        return true;
    }

    private function validateRequirement(string $requirement, array $context): bool
    {
        return match($requirement) {
            'input_sanitization' => $this->validateInputSanitization($context),
            'access_validation' => $this->validateAccessControl($context),
            'injection_prevention' => $this->validateInjectionPrevention($context),
            'xss_protection' => $this->validateXssProtection($context),
            default => false
        };
    }

    private function validateInputSanitization(array $context): bool
    {
        foreach ($context as $key => $value) {
            if (!$this->sanitizeInput($value)) {
                return false;
            }
        }
        return true;
    }

    private function validateAccessControl(array $context): bool
    {
        return isset($context['access_token']) && 
               $this->validateToken($context['access_token']);
    }

    private function validateInjectionPrevention(array $context): bool
    {
        return !$this->detectInjectionPatterns($context);
    }

    private function validateXssProtection(array $context): bool
    {
        return !$this->detectXssPatterns($context);
    }

    private function sanitizeInput($value): bool
    {
        if (is_array($value)) {
            return array_reduce($value, fn($carry, $item) => 
                $carry && $this->sanitizeInput($item), true);
        }
        
        return !preg_match($this->sanitizationRules['pattern'], (string)$value);
    }

    private function validateToken(string $token): bool
    {
        return strlen($token) >= 32 && 
               preg_match('/^[A-Za-z0-9._-]+$/', $token);
    }

    private function detectInjectionPatterns(array $context): bool
    {
        $patterns = $this->securityChecks['injection_patterns'];
        
        foreach ($context as $value) {
            if (is_string($value) && 
                preg_match($patterns['sql_injection'], $value)) {
                return true;
            }
        }
        
        return false;
    }

    private function detectXssPatterns(array $context): bool
    {
        $patterns = $this->securityChecks['xss_patterns'];
        
        foreach ($context as $value) {
            if (is_string($value) && 
                preg_match($patterns['xss'], $value)) {
                return true;
            }
        }
        
        return false;
    }
}
