namespace App\Core\Security;

class ValidationService implements ValidationInterface
{
    private SecurityConfig $config;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private array $validators = [];
    private MetricsCollector $metrics;

    public function __construct(
        SecurityConfig $config,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        MetricsCollector $metrics
    ) {
        $this->config = $config;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->metrics = $metrics;
    }

    public function validate(array $data, array $rules = []): ValidationResult 
    {
        $startTime = microtime(true);
        DB::beginTransaction();

        try {
            // Input sanitization
            $sanitized = $this->sanitizeInput($data);
            
            // Structural validation
            $this->validateStructure($sanitized, $rules);
            
            // Business rules validation
            $this->validateBusinessRules($sanitized);
            
            // Security validation
            $this->validateSecurity($sanitized);
            
            // Performance impact check
            $this->checkPerformanceImpact($sanitized);

            DB::commit();
            
            $this->metrics->recordValidation(
                microtime(true) - $startTime,
                count($data),
                'success'
            );

            return new ValidationResult(true, $sanitized);

        } catch (ValidationException $e) {
            DB::rollBack();
            
            $this->metrics->recordValidation(
                microtime(true) - $startTime,
                count($data),
                'failure'
            );
            
            $this->auditLogger->logValidationFailure($data, $e);
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
        // Type-specific sanitization
        if (is_string($value)) {
            $value = $this->sanitizeString($value);
        } elseif (is_numeric($value)) {
            $value = $this->sanitizeNumeric($value);
        }

        // Field-specific sanitization
        if ($validator = $this->validators[$key] ?? null) {
            $value = $validator->sanitize($value);
        }

        return $value;
    }

    private function validateStructure(array $data, array $rules): void
    {
        foreach ($rules as $field => $rule) {
            if (!isset($data[$field]) && $rule['required'] ?? false) {
                throw new ValidationException("Required field missing: $field");
            }

            if (isset($data[$field])) {
                $this->validateField($data[$field], $rule, $field);
            }
        }
    }

    private function validateField($value, array $rule, string $field): void
    {
        // Type validation
        if (!$this->validateType($value, $rule['type'] ?? null)) {
            throw new ValidationException("Invalid type for field: $field");
        }

        // Format validation
        if (isset($rule['format']) && !$this->validateFormat($value, $rule['format'])) {
            throw new ValidationException("Invalid format for field: $field");
        }

        // Range validation
        if (isset($rule['range'])) {
            $this->validateRange($value, $rule['range'], $field);
        }

        // Custom validation
        if (isset($rule['custom'])) {
            $this->executeCustomValidation($value, $rule['custom'], $field);
        }
    }

    private function validateBusinessRules(array $data): void
    {
        foreach ($this->config->getBusinessRules() as $rule) {
            if (!$rule->validate($data)) {
                throw new BusinessRuleValidationException($rule->getMessage());
            }
        }
    }

    private function validateSecurity(array $data): void
    {
        // XSS Protection
        foreach ($data as $value) {
            if (is_string($value) && $this->containsXSS($value)) {
                throw new SecurityValidationException("XSS attempt detected");
            }
        }

        // SQL Injection Protection
        if ($this->detectSQLInjection($data)) {
            throw new SecurityValidationException("SQL injection attempt detected");
        }

        // Authentication/Authorization Check
        if (!$this->validateAuthContext()) {
            throw new SecurityValidationException("Invalid security context");
        }

        // Rate Limiting Check
        if ($this->isRateLimitExceeded()) {
            throw new SecurityValidationException("Rate limit exceeded");
        }
    }

    private function checkPerformanceImpact(array $data): void
    {
        $impact = $this->calculatePerformanceImpact($data);
        
        if ($impact > $this->config->getMaxPerformanceImpact()) {
            throw new PerformanceValidationException("Operation exceeds performance thresholds");
        }
    }

    private function calculatePerformanceImpact(array $data): float
    {
        $metrics = [
            'memory_usage' => memory_get_usage(true),
            'cpu_time' => getrusage()['ru_utime.tv_usec'],
            'data_size' => strlen(serialize($data))
        ];

        return $this->metrics->calculateImpact($metrics);
    }
}
