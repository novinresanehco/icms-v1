<?php

namespace App\Core\Validation;

use Illuminate\Support\Facades\Validator;
use App\Core\Security\SecurityContext;
use App\Core\Cache\CacheManager;
use App\Core\Interfaces\ValidationInterface;

class ValidationService implements ValidationInterface
{
    private SecurityContext $security;
    private CacheManager $cache;
    private array $config;
    private array $customRules = [];

    public function __construct(
        SecurityContext $security,
        CacheManager $cache,
        array $config
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->config = $config;
        $this->registerCustomRules();
    }

    public function validate(array $data, array $rules, array $messages = []): array
    {
        // Apply security context
        $rules = $this->applySecurityRules($rules);
        
        // Cache validation patterns
        $cacheKey = $this->generateCacheKey($rules);
        
        return $this->cache->remember($cacheKey, function() use ($data, $rules, $messages) {
            $validator = Validator::make(
                $this->sanitizeInput($data),
                $rules,
                $messages
            );

            if ($validator->fails()) {
                throw new ValidationException(
                    'Validation failed',
                    $validator->errors()->toArray()
                );
            }

            return $validator->validated();
        });
    }

    public function validateField(string $field, $value, array $rules): bool
    {
        $data = [$field => $value];
        $fieldRules = [$field => $rules];

        try {
            $this->validate($data, $fieldRules);
            return true;
        } catch (ValidationException $e) {
            return false;
        }
    }

    public function validateBatch(array $items, array $rules): array
    {
        $results = [];
        
        foreach ($items as $key => $data) {
            try {
                $results[$key] = [
                    'success' => true,
                    'data' => $this->validate($data, $rules)
                ];
            } catch (ValidationException $e) {
                $results[$key] = [
                    'success' => false,
                    'errors' => $e->errors()
                ];
            }
        }

        return $results;
    }

    public function extendRules(array $customRules): void
    {
        foreach ($customRules as $rule => $validator) {
            $this->customRules[$rule] = $validator;
            Validator::extend($rule, $validator);
        }
    }

    protected function registerCustomRules(): void
    {
        // Security Rules
        Validator::extend('secure_string', function($attribute, $value) {
            return $this->validateSecureString($value);
        });

        Validator::extend('no_sql_injection', function($attribute, $value) {
            return $this->validateNoSqlInjection($value);
        });

        Validator::extend('xss_clean', function($attribute, $value) {
            return $this->validateXssClean($value);
        });

        // Business Rules
        foreach ($this->config['custom_rules'] ?? [] as $rule => $validator) {
            Validator::extend($rule, $validator);
        }
    }

    protected function applySecurityRules(array $rules): array
    {
        $securityRules = [];

        foreach ($rules as $field => $fieldRules) {
            $fieldRules = is_string($fieldRules) 
                ? explode('|', $fieldRules) 
                : $fieldRules;

            // Add security rules based on field type and context
            if ($this->isTextField($field)) {
                $fieldRules[] = 'xss_clean';
                $fieldRules[] = 'no_sql_injection';
            }

            if ($this->isCriticalField($field)) {
                $fieldRules[] = 'secure_string';
            }

            $securityRules[$field] = $fieldRules;
        }

        return $securityRules;
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
        $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        
        if (strlen($value) > $this->config['max_string_length'] ?? 1000) {
            throw new ValidationException('String exceeds maximum length');
        }

        return $value;
    }

    protected function validateSecureString(string $value): bool
    {
        $pattern = '/^[\p{L}\p{N}\s\-_.,!?@#$%^&*()]+$/u';
        return preg_match($pattern, $value) === 1;
    }

    protected function validateNoSqlInjection(string $value): bool
    {
        $blacklist = [
            'DROP',
            'DELETE',
            'UPDATE',
            'INSERT',
            '--',
            ';',
            'UNION',
            'SELECT'
        ];

        $value = strtoupper($value);
        foreach ($blacklist as $term) {
            if (strpos($value, $term) !== false) {
                return false;
            }
        }

        return true;
    }

    protected function validateXssClean(string $value): bool
    {
        $patterns = [
            '/<script\b[^>]*>(.*?)<\/script>/is',
            '/on\w+\s*=\s*"[^"]*"/is',
            '/on\w+\s*=\s*\'[^\']*\'/is',
            '/javascript:\s*([^"\']*)/is'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return false;
            }
        }

        return true;
    }

    protected function generateCacheKey(array $rules): string
    {
        return 'validation:' . md5(serialize($rules));
    }

    protected function isTextField(string $field): bool
    {
        return in_array($field, $this->config['text_fields'] ?? []);
    }

    protected function isCriticalField(string $field): bool
    {
        return in_array($field, $this->config['critical_fields'] ?? []);
    }

    public function getCustomRules(): array
    {
        return $this->customRules;
    }
}
