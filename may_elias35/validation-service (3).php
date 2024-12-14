<?php

namespace App\Core\Validation;

use App\Core\Contracts\ValidationServiceInterface;
use App\Core\Exceptions\{ValidationException, SecurityException};
use Illuminate\Support\Facades\{Cache, Log};

class ValidationService implements ValidationServiceInterface 
{
    private const CACHE_TTL = 3600;
    private const MAX_VALIDATION_TIME = 500; // ms
    
    private CacheManager $cache;
    private SecurityScanner $scanner;
    private MetricsCollector $metrics;

    public function __construct(
        CacheManager $cache,
        SecurityScanner $scanner,
        MetricsCollector $metrics
    ) {
        $this->cache = $cache;
        $this->scanner = $scanner;
        $this->metrics = $metrics;
    }

    public function validateInput(array $data, array $rules): array
    {
        $startTime = microtime(true);

        try {
            // Security scan first
            $this->performSecurityScan($data);
            
            // Validate against rules
            $validated = $this->validateAgainstRules($data, $rules);
            
            // Additional security checks
            $this->validateSanitization($validated);
            $this->validatePatterns($validated);
            $this->checkBlacklist($validated);
            
            // Cache validated result
            $this->cacheValidatedData($validated);

            return $validated;

        } catch (\Exception $e) {
            $this->handleValidationFailure($e, $data);
            throw $e;
        } finally {
            $this->recordMetrics($startTime);
        }
    }

    public function verifyIntegrity($data): bool
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (!$this->verifyFieldIntegrity($key, $value)) {
                    return false;
                }
            }
            return true;
        }
        return $this->verifyFieldIntegrity('data', $data);
    }

    public function verifyBusinessRules($data): bool
    {
        $rules = $this->loadBusinessRules();
        foreach ($rules as $rule) {
            if (!$rule->validate($data)) {
                throw new ValidationException(
                    "Business rule validation failed: {$rule->getMessage()}"
                );
            }
        }
        return true;
    }

    private function performSecurityScan(array $data): void
    {
        $threats = $this->scanner->scan($data);
        if (!empty($threats)) {
            throw new SecurityException(
                'Security threats detected: ' . implode(', ', $threats)
            );
        }
    }

    private function validateAgainstRules(array $data, array $rules): array
    {
        $validated = [];
        foreach ($rules as $field => $fieldRules) {
            if (!isset($data[$field]) && $this->isRequired($fieldRules)) {
                throw new ValidationException("Required field missing: {$field}");
            }
            
            $value = $data[$field] ?? null;
            $validated[$field] = $this->validateField($value, $fieldRules);
        }
        return $validated;
    }

    private function validateField($value, array $rules): mixed
    {
        foreach ($rules as $rule) {
            if (!$this->applySingleRule($value, $rule)) {
                throw new ValidationException(
                    "Validation failed for rule: {$rule}"
                );
            }
        }
        return $value;
    }

    private function validateSanitization(array $data): void
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $sanitized = filter_var($value, FILTER_SANITIZE_STRING);
                if ($sanitized !== $value) {
                    throw new SecurityException(
                        "Sanitization failed for field: {$key}"
                    );
                }
            }
        }
    }

    private function validatePatterns(array $data): void
    {
        $patterns = $this->loadSecurityPatterns();
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                foreach ($patterns as $pattern => $message) {
                    if (preg_match($pattern, $value)) {
                        throw new SecurityException(
                            "Security pattern matched: {$message}"
                        );
                    }
                }
            }
        }
    }

    private function checkBlacklist(array $data): void
    {
        $blacklist = $this->loadBlacklist();
        foreach ($data as $key => $value) {
            if (is_string($value) && in_array(strtolower($value), $blacklist)) {
                throw new SecurityException(
                    "Blacklisted value detected in field: {$key}"
                );
            }
        }
    }

    private function verifyFieldIntegrity($key, $value): bool
    {
        $cached = $this->cache->get("integrity:{$key}");
        if ($cached && $cached !== hash('sha256', serialize($value))) {
            return false;
        }
        return true;
    }

    private function cacheValidatedData(array $data): void
    {
        foreach ($data as $key => $value) {
            $this->cache->put(
                "integrity:{$key}",
                hash('sha256', serialize($value)),
                self::CACHE_TTL
            );
        }
    }

    private function recordMetrics(float $startTime): void
    {
        $executionTime = (microtime(true) - $startTime) * 1000;
        if ($executionTime > self::MAX_VALIDATION_TIME) {
            Log::warning('Validation time exceeded threshold', [
                'execution_time' => $executionTime,
                'threshold' => self::MAX_VALIDATION_TIME
            ]);
        }
        
        $this->metrics->recordValidationTime($executionTime);
    }

    private function handleValidationFailure(\Exception $e, array $data): void
    {
        Log::error('Validation failed', [
            'exception' => $e->getMessage(),
            'data' => $data,
            'trace' => $e->getTraceAsString()
        ]);
        
        $this->metrics->incrementFailureCount(
            get_class($e),
            $e->getCode()
        );
    }
}
