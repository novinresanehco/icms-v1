<?php

namespace App\Core\Validation;

use Illuminate\Support\Facades\Cache;
use App\Core\Contracts\{ValidatorInterface, RuleInterface};
use App\Core\Security\SecurityContext;
use App\Core\Exceptions\{ValidationException, SecurityException};

class ValidationService implements ValidatorInterface 
{
    private RuleRegistry $rules;
    private SecurityContext $context;
    private AuditLogger $auditLogger;
    private PerformanceMonitor $monitor;

    public function validate(array $data, array $rules): array 
    {
        $startTime = microtime(true);
        
        try {
            // Pre-validation security check
            $this->validateSecurity($data);
            
            // Input sanitization
            $sanitized = $this->sanitizeInput($data);
            
            // Rule validation
            $this->validateRules($sanitized, $rules);
            
            // Business logic validation
            $this->validateBusinessLogic($sanitized);
            
            // Performance tracking
            $this->monitor->recordValidation(microtime(true) - $startTime);
            
            return $sanitized;
            
        } catch (\Throwable $e) {
            $this->handleValidationFailure($e, $data);
            throw $e;
        }
    }

    private function validateSecurity(array $data): void 
    {
        // Security checks
        if ($this->containsMaliciousContent($data)) {
            throw new SecurityException('Malicious content detected');
        }

        // Rate limiting
        if (!$this->checkRateLimit()) {
            throw new SecurityException('Rate limit exceeded');
        }

        // Input size validation
        if ($this->exceedsSizeLimit($data)) {
            throw new ValidationException('Input size exceeds limit');
        }
    }

    private function sanitizeInput(array $data): array 
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeInput($value);
            } else {
                $sanitized[$key] = $this->sanitizeValue($value);
            }
        }
        
        return $sanitized;
    }

    private function validateRules(array $data, array $rules): void 
    {
        $errors = [];
        
        foreach ($rules as $field => $ruleSet) {
            $value = $data[$field] ?? null;
            
            foreach ($ruleSet as $rule) {
                $validator = $this->rules->get($rule);
                
                if (!$validator->validate($value)) {
                    $errors[$field][] = $validator->getMessage();
                }
            }
        }
        
        if (!empty($errors)) {
            throw new ValidationException('Validation failed', $errors);
        }
    }

    private function validateBusinessLogic(array $data): void 
    {
        // Cache complex validation results
        $cacheKey = $this->generateValidationCacheKey($data);
        
        $result = Cache::remember($cacheKey, 60, function() use ($data) {
            return $this->executeBusinessValidation($data);
        });
        
        if (!$result['valid']) {
            throw new ValidationException($result['message']);
        }
    }

    private function containsMaliciousContent(array $data): bool 
    {
        foreach ($data as $value) {
            if (is_array($value)) {
                if ($this->containsMaliciousContent($value)) {
                    return true;
                }
            } else {
                if ($this->isMalicious($value)) {
                    return true;
                }
            }
        }
        
        return false;
    }

    private function isMalicious($value): bool 
    {
        if (!is_string($value)) {
            return false;
        }

        $patterns = [
            '/[<>].*?(?:javascript|vbscript):/i',
            '/(?:javascript|vbscript):/i',
            '/on(?:error|load|click|mouse|unload)/i',
            '/<[^>]*?\b(?:href|src)\s*=.*?(?:javascript|vbscript):/i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }

        return false;
    }

    private function checkRateLimit(): bool 
    {
        $key = 'validation_rate_' . $this->context->getUserId();
        $limit = 1000;
        $window = 3600;
        
        $current = Cache::increment($key);
        
        if ($current === 1) {
            Cache::put($key, 1, $window);
        }
        
        return $current <= $limit;
    }

    private function exceedsSizeLimit(array $data): bool 
    {
        return strlen(serialize($data)) > 1048576; // 1MB limit
    }

    private function sanitizeValue($value): string 
    {
        if (!is_string($value)) {
            return $value;
        }

        // Remove null bytes
        $value = str_replace(chr(0), '', $value);
        
        // Convert special characters to HTML entities
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Remove any remaining potentially dangerous characters
        $value = preg_replace('/[^\p{L}\p{N}\s\-_.,!?@#$%&*()[\]{}+=\/\\\'":;]/u', '', $value);
        
        return trim($value);
    }

    private function generateValidationCacheKey(array $data): string 
    {
        return 'validation_' . md5(serialize($data));
    }

    private function executeBusinessValidation(array $data): array 
    {
        // Implement business-specific validation logic
        return [
            'valid' => true,
            'message' => ''
        ];
    }

    private function handleValidationFailure(\Throwable $e, array $data): void 
    {
        $this->auditLogger->logValidationFailure([
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'data' => $data,
            'trace' => $e->getTraceAsString()
        ]);
    }
}
