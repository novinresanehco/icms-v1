<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\Cache;
use App\Exceptions\ValidationException;

class ValidationService 
{
    private RuleRegistry $rules;
    private SecurityConfig $config;
    private AuditLogger $auditLogger;
    private PerformanceMonitor $monitor;

    public function validateInput(array $data, array $rules): array
    {
        DB::beginTransaction();
        
        try {
            $cacheKey = $this->generateValidationCacheKey($data, $rules);
            
            if ($cached = $this->getCachedValidation($cacheKey)) {
                return $cached;
            }

            $startTime = microtime(true);
            
            $this->validateSecurityConstraints($data);
            $validated = $this->applyValidationRules($data, $rules);
            $this->validateBusinessRules($validated);
            
            $this->monitor->recordValidationTime(microtime(true) - $startTime);
            
            $this->cacheValidationResult($cacheKey, $validated);
            DB::commit();
            
            return $validated;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleValidationFailure($e, $data);
            throw $e;
        }
    }

    public function verifyIntegrity($data): bool
    {
        try {
            $hash = hash_hmac('sha256', serialize($data), $this->config->getIntegrityKey());
            return hash_equals($data['_hash'] ?? '', $hash);
        } catch (\Exception $e) {
            $this->auditLogger->logIntegrityFailure($data, $e);
            return false;
        }
    }

    private function validateSecurityConstraints(array $data): void 
    {
        foreach ($this->rules->getSecurityRules() as $rule) {
            if (!$rule->validate($data)) {
                throw new SecurityValidationException($rule->getMessage());
            }
        }
    }

    private function applyValidationRules(array $data, array $rules): array
    {
        $validated = [];
        
        foreach ($rules as $field => $fieldRules) {
            if (!isset($data[$field]) && $this->isRequired($fieldRules)) {
                throw new ValidationException("Field {$field} is required");
            }

            $value = $data[$field] ?? null;
            $validated[$field] = $this->validateField($value, $fieldRules);
        }

        return $validated;
    }

    private function validateField($value, array $rules): mixed
    {
        foreach ($rules as $rule) {
            if (!$this->rules->getRule($rule)->validate($value)) {
                throw new ValidationException("Validation failed for rule: {$rule}");
            }
        }

        return $this->sanitizeValue($value);
    }

    private function validateBusinessRules(array $data): void
    {
        foreach ($this->rules->getBusinessRules() as $rule) {
            if (!$rule->validate($data)) {
                throw new BusinessValidationException($rule->getMessage());
            }
        }
    }

    private function sanitizeValue($value): mixed
    {
        if (is_string($value)) {
            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }
        
        if (is_array($value)) {
            return array_map([$this, 'sanitizeValue'], $value);
        }
        
        return $value;
    }

    private function generateValidationCacheKey(array $data, array $rules): string
    {
        return 'validation:' . hash('sha256', serialize($data) . serialize($rules));
    }

    private function getCachedValidation(string $key): ?array
    {
        if ($this->config->isValidationCacheEnabled()) {
            return Cache::get($key);
        }
        return null;
    }

    private function cacheValidationResult(string $key, array $validated): void
    {
        if ($this->config->isValidationCacheEnabled()) {
            Cache::put($key, $validated, $this->config->getValidationCacheTTL());
        }
    }

    private function handleValidationFailure(\Exception $e, array $data): void
    {
        $this->auditLogger->logValidationFailure(
            $e,
            $data,
            [
                'stack_trace' => $e->getTraceAsString(),
                'validation_context' => $this->getValidationContext()
            ]
        );
    }

    private function isRequired(array $rules): bool
    {
        return in_array('required', $rules, true);
    }

    private function getValidationContext(): array
    {
        return [
            'timestamp' => microtime(true),
            'memory_usage' => memory_get_usage(true),
            'rules_loaded' => $this->rules->getLoadedRulesCount()
        ];
    }
}
