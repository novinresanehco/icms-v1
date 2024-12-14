<?php

namespace App\Core\Validation;

class ValidationService implements ValidationInterface
{
    private SecurityConfig $config;
    private AuditLogger $audit;
    private MetricsCollector $metrics;

    public function validateInput(array $data, array $rules): ValidationResult 
    {
        DB::beginTransaction();
        $startTime = microtime(true);
        
        try {
            $sanitizedData = $this->sanitizeInput($data);
            $this->validateRules($sanitizedData, $rules);
            $this->validateSecurityConstraints($sanitizedData);
            $this->validateBusinessRules($sanitizedData);
            
            DB::commit();
            
            $this->metrics->recordValidation(
                'input',
                microtime(true) - $startTime
            );
            
            return new ValidationResult(true, $sanitizedData);
            
        } catch (ValidationException $e) {
            DB::rollBack();
            $this->handleValidationFailure($e, $data, $rules);
            throw $e;
        }
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

    private function sanitizeValue($value, string $key): mixed
    {
        $rules = $this->config->getSanitizationRules($key);
        
        foreach ($rules as $rule) {
            $value = match ($rule) {
                'strip_tags' => strip_tags($value),
                'escape' => htmlspecialchars($value, ENT_QUOTES | ENT_HTML5),
                'trim' => trim($value),
                'lowercase' => strtolower($value),
                'uppercase' => strtoupper($value),
                'numeric' => filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT),
                'email' => filter_var($value, FILTER_SANITIZE_EMAIL),
                'url' => filter_var($value, FILTER_SANITIZE_URL),
                default => $this->applyCustomSanitizer($value, $rule)
            };
        }
        
        return $value;
    }

    private function validateRules(array $data, array $rules): void
    {
        $validator = new DataValidator($this->config);
        
        foreach ($rules as $field => $fieldRules) {
            if (!$validator->validate($data[$field] ?? null, $fieldRules)) {
                throw new ValidationException(
                    "Validation failed for field: $field",
                    $validator->getErrors()
                );
            }
        }
    }

    private function validateSecurityConstraints(array $data): void
    {
        $constraints = $this->config->getSecurityConstraints();
        
        foreach ($constraints as $constraint) {
            if (!$this->validateSecurityConstraint($data, $constraint)) {
                throw new SecurityValidationException(
                    "Security constraint failed: {$constraint->getName()}"
                );
            }
        }
    }

    private function validateBusinessRules(array $data): void
    {
        $rules = $this->config->getBusinessRules();
        
        foreach ($rules as $rule) {
            if (!$this->validateBusinessRule($data, $rule)) {
                throw new BusinessRuleException(
                    "Business rule failed: {$rule->getName()}"
                );
            }
        }
    }

    private function validateSecurityConstraint(array $data, SecurityConstraint $constraint): bool
    {
        $validator = new SecurityValidator($this->config);
        return $validator->validateConstraint($data, $constraint);
    }

    private function validateBusinessRule(array $data, BusinessRule $rule): bool
    {
        $validator = new BusinessRuleValidator($this->config);
        return $validator->validateRule($data, $rule);
    }

    private function handleValidationFailure(ValidationException $e, array $data, array $rules): void
    {
        $this->audit->logValidationFailure([
            'data' => $this->maskSensitiveData($data),
            'rules' => $rules,
            'errors' => $e->getErrors(),
            'trace' => $e->getTraceAsString()
        ]);
        
        $this->metrics->incrementFailureCount(
            'validation',
            get_class($e)
        );
    }

    private function maskSensitiveData(array $data): array
    {
        $sensitiveFields = $this->config->getSensitiveFields();
        
        foreach ($data as $key => $value) {
            if (in_array($key, $sensitiveFields)) {
                $data[$key] = '********';
            } elseif (is_array($value)) {
                $data[$key] = $this->maskSensitiveData($value);
            }
        }
        
        return $data;
    }

    private function applyCustomSanitizer($value, string $rule): mixed
    {
        $sanitizer = $this->config->getCustomSanitizer($rule);
        return $sanitizer->sanitize($value);
    }
}
