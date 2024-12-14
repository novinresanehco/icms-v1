<?php

namespace App\Core\Services;

use App\Core\Interfaces\ValidationServiceInterface;
use App\Core\Exceptions\ValidationException;
use Illuminate\Support\Facades\{Cache, Log};

class ValidationService implements ValidationServiceInterface
{
    private array $rules;
    private array $config;

    public function __construct(array $rules, array $config)
    {
        $this->rules = $rules;
        $this->config = $config;
    }

    public function validateInput(array $data): bool
    {
        try {
            // Rate limiting check
            if (!$this->checkRateLimit($data)) {
                throw new ValidationException('Rate limit exceeded');
            }

            // Input sanitization
            $sanitized = $this->sanitizeInput($data);

            // Validation against rules
            $this->validateAgainstRules($sanitized);

            // Security pattern check
            if (!$this->checkSecurityPatterns($sanitized)) {
                throw new ValidationException('Security pattern violation');
            }

            // Log successful validation
            $this->logValidation($data);

            return true;

        } catch (\Exception $e) {
            $this->logFailure($data, $e);
            throw $e;
        }
    }

    public function validateOperation(string $operation, array $context): bool
    {
        try {
            // Operation validation
            if (!isset($this->rules[$operation])) {
                throw new ValidationException('Invalid operation');
            }

            // Context validation
            if (!$this->validateContext($context)) {
                throw new ValidationException('Invalid context');
            }

            // Operation-specific rules
            $this->validateOperationRules($operation, $context);

            return true;

        } catch (\Exception $e) {
            $this->logFailure(['operation' => $operation, 'context' => $context], $e);
            throw $e;
        }
    }

    public function validateResult($result): bool
    {
        try {
            // Type validation
            if (!$this->validateResultType($result)) {
                throw new ValidationException('Invalid result type');
            }

            // Structure validation
            if (!$this->validateResultStructure($result)) {
                throw new ValidationException('Invalid result structure');
            }

            // Business rules validation
            if (!$this->validateBusinessRules($result)) {
                throw new ValidationException('Business rule violation');
            }

            return true;

        } catch (\Exception $e) {
            $this->logFailure(['result' => $result], $e);
            throw $e;
        }
    }

    protected function sanitizeInput(array $data): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            $sanitized[$key] = $this->sanitizeValue($value);
        }
        return $sanitized;
    }

    protected function sanitizeValue($value)
    {
        if (is_string($value)) {
            // XSS prevention
            $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            
            // SQL injection prevention
            $value = addslashes($value);
        }
        return $value;
    }

    protected function validateAgainstRules(array $data): void
    {
        foreach ($this->rules as $field => $rules) {
            if (isset($data[$field])) {
                $this->validateField($data[$field], $rules);
            }
        }
    }

    protected function validateField($value, array $rules): void
    {
        foreach ($rules as $rule => $params) {
            if (!$this->checkRule($value, $rule, $params)) {
                throw new ValidationException("Validation failed for rule: $rule");
            }
        }
    }

    protected function checkRule($value, string $rule, $params): bool
    {
        switch ($rule) {
            case 'required':
                return !empty($value);
            case 'type':
                return $this->checkType($value, $params);
            case 'length':
                return strlen($value) <= $params;
            case 'pattern':
                return preg_match($params, $value);
            default:
                return true;
        }
    }

    protected function checkSecurityPatterns(array $data): bool
    {
        foreach ($data as $value) {
            if (is_string($value) && $this->containsSecurityThreats($value)) {
                return false;
            }
        }
        return true;
    }

    protected function containsSecurityThreats(string $value): bool
    {
        // Check for common security threats
        $threats = [
            'script',
            'javascript:',
            'data:',
            'vbscript:',
            'onload=',
            'onerror='
        ];

        foreach ($threats as $threat) {
            if (stripos($value, $threat) !== false) {
                return true;
            }
        }
        return false;
    }

    protected function checkRateLimit(array $data): bool
    {
        $key = $this->getRateLimitKey($data);
        $limit = $this->config['rate_limit'] ?? 100;
        $window = $this->config['rate_window'] ?? 3600;

        $current = (int) Cache::get($key, 0);
        if ($current >= $limit) {
            return false;
        }

        Cache::increment($key, 1);
        Cache::expire($key, $window);

        return true;
    }

    protected function getRateLimitKey(array $data): string
    {
        return 'validation_rate_limit:' . md5(json_encode($data));
    }

    protected function validateContext(array $context): bool
    {
        return isset($context['user_id']) && isset($context['timestamp']);
    }

    protected function validateOperationRules(string $operation, array $context): void
    {
        $rules = $this->rules[$operation] ?? [];
        foreach ($rules as $rule => $params) {
            if (!$this->validateOperationRule($rule, $params, $context)) {
                throw new ValidationException("Operation rule validation failed: $rule");
            }
        }
    }

    protected function validateOperationRule(string $rule, $params, array $context): bool
    {
        return true; // Implement based on specific operation rules
    }

    protected function validateResultType($result): bool
    {
        if (isset($this->config['expected_types'])) {
            return in_array(gettype($result), $this->config['expected_types']);
        }
        return true;
    }

    protected function validateResultStructure($result): bool
    {
        if (is_array($result) && isset($this->config['required_fields'])) {
            foreach ($this->config['required_fields'] as $field) {
                if (!isset($result[$field])) {
                    return false;
                }
            }
        }
        return true;
    }

    protected function validateBusinessRules($result): bool
    {
        return true; // Implement based on specific business rules
    }

    protected function logValidation(array $data): void
    {
        Log::info('Validation successful', [
            'data_keys' => array_keys($data),
            'timestamp' => now()
        ]);
    }

    protected function logFailure(array $data, \Exception $e): void
    {
        Log::error('Validation failed', [
            'data_keys' => array_keys($data),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => now()
        ]);
    }
}
