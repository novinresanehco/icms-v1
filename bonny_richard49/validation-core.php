<?php

namespace App\Core\Validation;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Monitoring\MonitoringSystem;
use Illuminate\Support\Facades\DB;

class ValidationSystem implements ValidationInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private MonitoringSystem $monitor;
    private array $config;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        MonitoringSystem $monitor,
        array $config
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->monitor = $monitor;
        $this->config = $config;
    }

    public function validate(array $data, array $rules, string $context = ''): array
    {
        $operationId = "validation.{$context}." . uniqid();
        $this->monitor->startOperation($operationId);

        try {
            // Security pre-validation
            $this->security->validateOperation('validation', ['context' => $context]);
            
            // Sanitize input
            $sanitized = $this->sanitizeInput($data);
            
            // Validate against rules
            $validated = $this->validateAgainstRules($sanitized, $rules);
            
            // Deep validation
            $this->performDeepValidation($validated, $context);
            
            // Cache validation result if applicable
            $this->cacheValidationResult($validated, $context);
            
            $this->monitor->endOperation($operationId, ['status' => 'success']);
            
            return $validated;
            
        } catch (\Throwable $e) {
            $this->monitor->endOperation($operationId, ['status' => 'failed']);
            throw $e;
        }
    }

    private function sanitizeInput(array $data): array
    {
        return array_map(function($value) {
            if (is_string($value)) {
                $value = strip_tags($value);
                $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
                return trim($value);
            }
            if (is_array($value)) {
                return $this->sanitizeInput($value);
            }
            return $value;
        }, $data);
    }

    private function validateAgainstRules(array $data, array $rules): array
    {
        $validated = [];

        foreach ($rules as $field => $fieldRules) {
            if (!isset($data[$field]) && $this->isRequired($fieldRules)) {
                throw new ValidationException("Required field missing: {$field}");
            }

            if (isset($data[$field])) {
                $value = $data[$field];
                
                foreach ($this->parseRules($fieldRules) as $rule) {
                    $this->validateRule($field, $value, $rule);
                }
                
                $validated[$field] = $value;
            }
        }

        return $validated;
    }

    private function parseRules(string|array $rules): array
    {
        if (is_string($rules)) {
            return explode('|', $rules);
        }
        return $rules;
    }

    private function validateRule(string $field, mixed $value, string $rule): void
    {
        [$ruleName, $parameters] = $this->parseRule($rule);
        
        $method = 'validate' . ucfirst($ruleName);
        if (!method_exists($this, $method)) {
            throw new ValidationException("Unknown validation rule: {$ruleName}");
        }
        
        if (!$this->$method($value, $parameters)) {
            throw new ValidationException("Validation failed for {$field}: {$rule}");
        }
    }

    private function parseRule(string $rule): array
    {
        if (strpos($rule, ':') === false) {
            return [$rule, []];
        }
        
        [$ruleName, $parameters] = explode(':', $rule, 2);
        return [$ruleName, explode(',', $parameters)];
    }

    private function validateRequired($value): bool
    {
        return !empty($value) || $value === '0' || $value === 0;
    }

    private function validateString($value): bool
    {
        return is_string($value);
    }

    private function validateNumeric($value): bool
    {
        return is_numeric($value);
    }

    private function validateMax($value, array $parameters): bool
    {
        $max = $parameters[0];
        if (is_numeric($value)) {
            return $value <= $max;
        }
        if (is_string($value)) {
            return strlen($value) <= $max;
        }
        if (is_array($value)) {
            return count($value) <= $max;
        }
        return false;
    }

    private function validateMin($value, array $parameters): bool
    {
        $min = $parameters[0];
        if (is_numeric($value)) {
            return $value >= $min;
        }
        if (is_string($value)) {
            return strlen($value) >= $min;
        }
        if (is_array($value)) {
            return count($value) >= $min;
        }
        return false;
    }

    private function validateEmail($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function validateUrl($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    private function validateRegex($value, array $parameters): bool
    {
        return preg_match($parameters[0], $value) > 0;
    }

    private function validateUnique($value, array $parameters): bool
    {
        [$table, $column] = $parameters;
        
        $count = DB::table($table)
            ->where($column, $value)
            ->count();
            
        return $count === 0;
    }

    private function validateExists($value, array $parameters): bool
    {
        [$table, $column] = $parameters;
        
        $exists = $this->cache->remember(
            "validation:exists:{$table}:{$column}:{$value}",
            3600,
            function() use ($table, $column, $value) {
                return DB::table($table)
                    ->where($column, $value)
                    ->exists();
            }
        );
        
        return $exists;
    }

    private function performDeepValidation(array $data, string $context): void
    {
        if (isset($this->config['deep_validation'][$context])) {
            $validators = $this->config['deep_validation'][$context];
            
            foreach ($validators as $validator) {
                $this->executeValidator($validator, $data);
            }
        }
    }

    private function executeValidator(string $validator, array $data): void
    {
        $instance = app($validator);
        if (!$instance->validate($data)) {
            throw new ValidationException(
                "Deep validation failed: {$instance->getError()}"
            );
        }
    }

    private function cacheValidationResult(array $validated, string $context): void
    {
        if ($this->shouldCacheValidation($context)) {
            $key = $this->getValidationCacheKey($validated, $context);
            $this->cache->put($key, $validated, $this->config['cache_ttl']);
        }
    }

    private function shouldCacheValidation(string $context): bool
    {
        return in_array($context, $this->config['cacheable_validations']);
    }

    private function getValidationCacheKey(array $data, string $context): string
    {
        return sprintf(
            'validation:%s:%s',
            $context,
            md5(serialize($data))
        );
    }

    private function isRequired(string|array $rules): bool
    {
        $rules = $this->parseRules($rules);
        return in_array('required', $rules);
    }
}
