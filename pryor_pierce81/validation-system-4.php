<?php

namespace App\Core\Validation;

use Illuminate\Support\Facades\{Cache, Log};
use App\Core\Security\SecurityManager;
use App\Core\Exceptions\ValidationException;

class ValidationService implements ValidationInterface
{
    protected SecurityManager $security;
    protected array $config;
    protected array $rules = [];
    protected array $customValidators = [];

    public function __construct(SecurityManager $security, array $config)
    {
        $this->security = $security;
        $this->config = $config;
    }

    public function validate(array $data, array $rules): array
    {
        return $this->security->executeCriticalOperation(function() use ($data, $rules) {
            $this->validateStructure($data);
            $sanitizedData = $this->sanitizeInput($data);
            $validatedData = [];

            foreach ($rules as $field => $fieldRules) {
                $value = $sanitizedData[$field] ?? null;
                $validatedData[$field] = $this->validateField($field, $value, $fieldRules);
            }

            $this->validateBusinessRules($validatedData);
            return $this->formatOutput($validatedData);
        });
    }

    public function validateField(string $field, $value, array|string $rules): mixed
    {
        $rules = is_string($rules) ? explode('|', $rules) : $rules;
        
        foreach ($rules as $rule) {
            $ruleName = is_string($rule) ? explode(':', $rule)[0] : $rule['rule'];
            $params = is_string($rule) ? array_slice(explode(':', $rule), 1) : ($rule['params'] ?? []);

            if (!$this->executeRule($ruleName, $value, $params, $field)) {
                throw new ValidationException("Validation failed for field {$field} with rule {$ruleName}");
            }
        }

        return $this->transformValue($value, $rules);
    }

    public function addRule(string $name, callable $validator): void
    {
        $this->customValidators[$name] = $validator;
    }

    public function validateBusinessRules(array $data): void
    {
        foreach ($this->config['business_rules'] as $rule) {
            if (!$rule->validate($data)) {
                throw new ValidationException("Business rule validation failed: {$rule->getMessage()}");
            }
        }
    }

    protected function validateStructure(array $data): void
    {
        if (count($data) > $this->config['max_fields']) {
            throw new ValidationException('Too many fields in input data');
        }

        $size = strlen(json_encode($data));
        if ($size > $this->config['max_input_size']) {
            throw new ValidationException('Input data size exceeds maximum allowed');
        }
    }

    protected function sanitizeInput(array $data): array
    {
        return array_map(function($value) {
            if (is_string($value)) {
                return $this->sanitizeString($value);
            }
            if (is_array($value)) {
                return $this->sanitizeInput($value);
            }
            return $value;
        }, $data);
    }

    protected function sanitizeString(string $value): string
    {
        $value = strip_tags($value);
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        foreach ($this->config['string_replacements'] as $search => $replace) {
            $value = str_replace($search, $replace, $value);
        }

        return $value;
    }

    protected function executeRule(string $rule, $value, array $params, string $field): bool
    {
        if (isset($this->customValidators[$rule])) {
            return $this->customValidators[$rule]($value, $params, $field);
        }

        $cacheKey = "validation.rule.{$rule}." . md5(serialize([$value, $params]));
        
        return Cache::remember($cacheKey, $this->config['cache_ttl'], function() use ($rule, $value, $params) {
            return match($rule) {
                'required' => !empty($value),
                'string' => is_string($value),
                'numeric' => is_numeric($value),
                'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
                'min' => is_numeric($value) && $value >= $params[0],
                'max' => is_numeric($value) && $value <= $params[0],
                'length' => is_string($value) && strlen($value) <= $params[0],
                'pattern' => is_string($value) && preg_match($params[0], $value),
                'in' => in_array($value, $params),
                default => false
            };
        });
    }

    protected function transformValue($value, array $rules): mixed
    {
        foreach ($rules as $rule) {
            $ruleName = is_string($rule) ? explode(':', $rule)[0] : $rule['rule'];
            
            $value = match($ruleName) {
                'trim' => is_string($value) ? trim($value) : $value,
                'lowercase' => is_string($value) ? strtolower($value) : $value,
                'uppercase' => is_string($value) ? strtoupper($value) : $value,
                'int' => is_numeric($value) ? (int)$value : $value,
                'float' => is_numeric($value) ? (float)$value : $value,
                'bool' => is_string($value) ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : $value,
                default => $value
            };
        }

        return $value;
    }

    protected function formatOutput(array $data): array
    {
        return array_map(function($value) {
            if (is_string($value)) {
                return $this->formatString($value);
            }
            if (is_array($value)) {
                return $this->formatOutput($value);
            }
            return $value;
        }, $data);
    }

    protected function formatString(string $value): string
    {
        $value = trim($value);
        
        if (mb_strlen($value) > $this->config['max_string_length']) {
            $value = mb_substr($value, 0, $this->config['max_string_length']);
        }

        return $value;
    }
}
