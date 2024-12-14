namespace App\Core\Security;

class ValidationService implements ValidationInterface
{
    private array $securityRules;
    private DataSanitizer $sanitizer;
    private AuditLogger $auditLogger;

    public function __construct(
        SecurityConfig $config,
        DataSanitizer $sanitizer,
        AuditLogger $auditLogger
    ) {
        $this->securityRules = $config->getValidationRules();
        $this->sanitizer = $sanitizer;
        $this->auditLogger = $auditLogger;
    }

    public function validateOperation(CriticalOperation $operation): ValidationResult
    {
        $startTime = microtime(true);
        $data = $operation->getData();
        $rules = $operation->getValidationRules();

        try {
            // Sanitize input
            $sanitizedData = $this->sanitizer->sanitize($data);

            // Validate against security rules
            $this->validateSecurityRules($sanitizedData);

            // Validate against operation rules
            $this->validateOperationRules($sanitizedData, $rules);

            // Verify data integrity
            $this->verifyDataIntegrity($sanitizedData);

            // Log successful validation
            $this->logValidation($operation, true, microtime(true) - $startTime);

            return new ValidationResult(true, $sanitizedData);

        } catch (ValidationException $e) {
            $this->logValidation($operation, false, microtime(true) - $startTime, $e);
            throw $e;
        }
    }

    private function validateSecurityRules(array $data): void
    {
        foreach ($this->securityRules as $field => $rules) {
            if (!$this->validateField($data[$field] ?? null, $rules)) {
                throw new SecurityValidationException(
                    "Security validation failed for field: {$field}"
                );
            }
        }
    }

    private function validateOperationRules(array $data, array $rules): void
    {
        foreach ($rules as $field => $rule) {
            if (!$this->validateField($data[$field] ?? null, $rule)) {
                throw new OperationValidationException(
                    "Operation validation failed for field: {$field}"
                );
            }
        }
    }

    private function validateField($value, array $rules): bool
    {
        foreach ($rules as $rule => $params) {
            if (!$this->applyRule($value, $rule, $params)) {
                return false;
            }
        }
        return true;
    }

    private function applyRule($value, string $rule, $params): bool
    {
        return match($rule) {
            'required' => !empty($value),
            'type' => $this->validateType($value, $params),
            'pattern' => $this->validatePattern($value, $params),
            'range' => $this->validateRange($value, $params),
            'security' => $this->validateSecurity($value, $params),
            default => throw new UnsupportedValidationRuleException("Unknown rule: {$rule}")
        };
    }

    private function verifyDataIntegrity(array $data): void
    {
        $hash = hash('sha256', serialize($data));
        if ($hash !== $this->calculateExpectedHash($data)) {
            throw new IntegrityException('Data integrity check failed');
        }
    }

    private function logValidation(
        CriticalOperation $operation,
        bool $success,
        float $duration,
        ?\Exception $error = null
    ): void {
        $this->auditLogger->logValidation([
            'operation_id' => $operation->getId(),
            'type' => get_class($operation),
            'success' => $success,
            'duration' => $duration,
            'error' => $error ? $error->getMessage() : null
        ]);
    }

    private function validateType($value, string $type): bool
    {
        return match($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'float' => is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value),
            default => false
        };
    }

    private function validatePattern($value, string $pattern): bool
    {
        return (bool)preg_match($pattern, (string)$value);
    }

    private function validateRange($value, array $range): bool
    {
        return $value >= $range[0] && $value <= $range[1];
    }

    private function validateSecurity($value, array $rules): bool
    {
        foreach ($rules as $rule) {
            if (!$this->applySecurity($value, $rule)) {
                return false;
            }
        }
        return true;
    }

    private function calculateExpectedHash(array $data): string
    {
        return hash('sha256', serialize($data));
    }
}
