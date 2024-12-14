<?php

namespace App\Core\Services;

use Illuminate\Support\Facades\{Cache, DB};
use App\Core\Interfaces\ValidationInterface;
use App\Core\Exceptions\{ValidationException, SecurityException};

class ValidationService implements ValidationInterface
{
    private SecurityConfig $config;
    private DataSanitizer $sanitizer;
    private RuleEngine $ruleEngine;
    private ValidationCache $cache;

    public function __construct(
        SecurityConfig $config,
        DataSanitizer $sanitizer,
        RuleEngine $ruleEngine,
        ValidationCache $cache
    ) {
        $this->config = $config;
        $this->sanitizer = $sanitizer;
        $this->ruleEngine = $ruleEngine;
        $this->cache = $cache;
    }

    public function validateInput(array $input, array $rules = []): array
    {
        $cacheKey = $this->generateValidationCacheKey($input, $rules);
        
        try {
            DB::beginTransaction();

            // Check cache first
            if ($cachedResult = $this->cache->get($cacheKey)) {
                return $cachedResult;
            }

            // Sanitize input
            $sanitized = $this->sanitizer->sanitize($input);

            // Validate against security rules
            $this->validateSecurity($sanitized);

            // Apply business rules
            $validated = $this->applyValidationRules($sanitized, $rules);

            // Verify data integrity
            $this->verifyDataIntegrity($validated);

            // Cache successful validation
            $this->cache->put($cacheKey, $validated);

            DB::commit();
            return $validated;

        } catch (\Throwable $e) {
            DB::rollBack();
            throw new ValidationException(
                'Input validation failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    public function validateSecurityContext(SecurityContext $context): bool
    {
        // Verify authentication
        if (!$this->verifyAuthentication($context)) {
            return false;
        }

        // Check authorization
        if (!$this->verifyAuthorization($context)) {
            return false;
        }

        // Validate request context
        if (!$this->validateRequestContext($context)) {
            return false;
        }

        return true;
    }

    public function validateOutput($output): bool
    {
        try {
            // Check output structure
            if (!$this->validateOutputStructure($output)) {
                return false;
            }

            // Verify security constraints
            if (!$this->verifyOutputSecurity($output)) {
                return false;
            }

            // Validate data integrity
            if (!$this->verifyOutputIntegrity($output)) {
                return false;
            }

            return true;

        } catch (\Throwable $e) {
            throw new ValidationException(
                'Output validation failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    private function validateSecurity(array $data): void
    {
        // Check for injection attempts
        $this->detectInjectionAttempts($data);

        // Validate against XSS
        $this->validateXSS($data);

        // Check for malicious patterns
        $this->detectMaliciousPatterns($data);
    }

    private function detectInjectionAttempts(array $data): void
    {
        foreach ($data as $key => $value) {
            if ($this->containsSQLInjection($value)) {
                throw new SecurityException('SQL injection detected');
            }
            if ($this->containsCommandInjection($value)) {
                throw new SecurityException('Command injection detected');
            }
        }
    }

    private function validateXSS(array $data): void
    {
        foreach ($data as $key => $value) {
            if ($this->containsXSSPatterns($value)) {
                throw new SecurityException('XSS attempt detected');
            }
        }
    }

    private function detectMaliciousPatterns(array $data): void
    {
        foreach ($data as $key => $value) {
            if ($this->matchesMaliciousPattern($value)) {
                throw new SecurityException('Malicious pattern detected');
            }
        }
    }

    private function verifyDataIntegrity(array $data): void
    {
        // Verify data structure
        if (!$this->validateDataStructure($data)) {
            throw new ValidationException('Invalid data structure');
        }

        // Check data consistency
        if (!$this->verifyDataConsistency($data)) {
            throw new ValidationException('Data consistency check failed');
        }

        // Validate relationships
        if (!$this->validateRelationships($data)) {
            throw new ValidationException('Invalid data relationships');
        }
    }

    private function generateValidationCacheKey(array $input, array $rules): string
    {
        return hash('sha256', json_encode([
            'input' => $input,
            'rules' => $rules,
            'timestamp' => time()
        ]));
    }

    private function verifyAuthentication(SecurityContext $context): bool
    {
        return $context->getUser() !== null &&
               $context->getUser()->isAuthenticated() &&
               !$context->getUser()->isSessionExpired();
    }

    private function verifyAuthorization(SecurityContext $context): bool
    {
        return $context->hasRequiredPermissions() &&
               $context->isWithinRoleScope();
    }

    private function validateRequestContext(SecurityContext $context): bool
    {
        return $context->isValidSource() &&
               $context->isWithinRateLimit() &&
               $context->hasValidSignature();
    }

    private function validateOutputStructure($output): bool
    {
        return is_array($output) || is_object($output);
    }

    private function verifyOutputSecurity($output): bool
    {
        return !$this->containsSensitiveData($output) &&
               $this->isWithinSizeLimit($output);
    }

    private function verifyOutputIntegrity($output): bool
    {
        return $this->checkDataConsistency($output) &&
               $this->validateOutputFormat($output);
    }

    private function containsSQLInjection(string $value): bool
    {
        $patterns = $this->config->getSQLInjectionPatterns();
        return (bool) preg_match($patterns, $value);
    }

    private function containsCommandInjection(string $value): bool
    {
        $patterns = $this->config->getCommandInjectionPatterns();
        return (bool) preg_match($patterns, $value);
    }

    private function containsXSSPatterns(string $value): bool
    {
        $patterns = $this->config->getXSSPatterns();
        return (bool) preg_match($patterns, $value);
    }

    private function matchesMaliciousPattern(string $value): bool
    {
        $patterns = $this->config->getMaliciousPatterns();
        return (bool) preg_match($patterns, $value);
    }
}
