<?php

namespace App\Core\Validation;

use Illuminate\Support\Facades\Cache;
use App\Core\Interfaces\ValidationServiceInterface;
use App\Core\Security\SecurityConfig;
use App\Core\Exceptions\{
    ValidationException,
    SecurityException
};

class ValidationService implements ValidationServiceInterface
{
    private SecurityConfig $securityConfig;
    private array $validationRules;
    private array $sensitivePatterns;
    
    private const CACHE_PREFIX = 'validation:';
    private const CACHE_TTL = 3600;

    public function __construct(
        SecurityConfig $securityConfig,
        array $validationRules = [],
        array $sensitivePatterns = []
    ) {
        $this->securityConfig = $securityConfig;
        $this->validationRules = $validationRules;
        $this->sensitivePatterns = $sensitivePatterns;
    }

    public function validateInput(array $data): bool
    {
        foreach ($this->validationRules as $field => $rules) {
            if (!$this->validateField($data[$field] ?? null, $rules)) {
                throw new ValidationException("Validation failed for field: {$field}");
            }
        }

        $this->validateSecurityConstraints($data);
        return true;
    }

    public function validateOutput($result): bool
    {
        if (!$this->validateStructure($result)) {
            throw new ValidationException('Invalid result structure');
        }

        if ($this->containsSensitiveData($result)) {
            throw new SecurityException('Result contains sensitive data');
        }

        return true;
    }

    public function validateTransaction(array $data, string $operation): bool
    {
        $key = self::CACHE_PREFIX . hash('sha256', json_encode([$data, $operation]));

        return Cache::remember($key, self::CACHE_TTL, function() use ($data, $operation) {
            return $this->performTransactionValidation($data, $operation);
        });
    }

    protected function validateField($value, array $rules): bool
    {
        foreach ($rules as $rule) {
            if (!$this->applySingleRule($value, $rule)) {
                return false;
            }
        }
        return true;
    }

    protected function applySingleRule($value, string $rule): bool
    {
        return match ($rule) {
            'required' => !is_null($value) && $value !== '',
            'numeric' => is_numeric($value),
            'string' => is_string($value),
            'array' => is_array($value),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url' => filter_var($value, FILTER_VALIDATE_URL) !== false,
            'json' => $this->isValidJson($value),
            default => $this->applyCustomRule($value, $rule)
        };
    }

    protected function validateSecurityConstraints(array $data): void
    {
        // Check for SQL injection patterns
        if ($this->containsSqlInjection($data)) {
            throw new SecurityException('Potential SQL injection detected');
        }

        // Check for XSS patterns
        if ($this->containsXss($data)) {
            throw new SecurityException('Potential XSS detected');
        }

        // Validate data size
        if ($this->exceedsSizeLimit($data)) {
            throw new ValidationException('Data size exceeds limit');
        }
    }

    protected function validateStructure($result): bool
    {
        if (!is_array($result)) {
            return false;
        }

        $requiredFields = ['id', 'timestamp', 'hash'];
        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $result)) {
                return false;
            }
        }

        return true;
    }

    protected function containsSensitiveData($data): bool
    {
        $json = json_encode($data);
        foreach ($this->sensitivePatterns as $pattern) {
            if (preg_match($pattern, $json)) {
                return true;
            }
        }
        return false;
    }

    protected function performTransactionValidation(array $data, string $operation): bool
    {
        // Validate operation type
        if (!in_array($operation, $this->securityConfig->getAllowedOperations())) {
            return false;
        }

        // Validate data integrity
        if (!$this->validateDataIntegrity($data)) {
            return false;
        }

        // Validate business rules
        return $this->validateBusinessRules($data, $operation);
    }

    protected function validateDataIntegrity(array $data): bool
    {
        return isset($data['hash']) && 
               hash_equals(
                   $data['hash'],
                   hash_hmac('sha256', json_encode($data['content']), $this->securityConfig->getAppKey())
               );
    }

    protected function validateBusinessRules(array $data, string $operation): bool
    {
        $rules = $this->securityConfig->getBusinessRules($operation);
        foreach ($rules as $rule) {
            if (!$this->applySingleBusinessRule($data, $rule)) {
                return false;
            }
        }
        return true;
    }

    protected function containsSqlInjection(array $data): bool
    {
        $patterns = ['/\bUNION\b/i', '/\bSELECT\b/i', '/\bDROP\b/i'];
        return $this->matchesPatterns(json_encode($data), $patterns);
    }

    protected function containsXss(array $data): bool
    {
        $patterns = ['/<script\b[^>]*>/i', '/javascript:/i', '/onclick/i'];
        return $this->matchesPatterns(json_encode($data), $patterns);
    }

    protected function exceedsSizeLimit(array $data): bool
    {
        $size = strlen(json_encode($data));
        return $size > $this->securityConfig->getMaxDataSize();
    }

    private function isValidJson($value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }

    private function applyCustomRule($value, string $rule): bool
    {
        if (method_exists($this, $rule)) {
            return $this->$rule($value);
        }
        throw new ValidationException("Unknown validation rule: {$rule}");
    }

    private function matchesPatterns(string $data, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $data)) {
                return true;
            }
        }
        return false;
    }
}
