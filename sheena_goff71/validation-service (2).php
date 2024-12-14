namespace App\Core\Security;

use App\Exceptions\ValidationException;
use App\Interfaces\ValidationInterface;
use App\Core\Security\Rules\ValidationRuleSet;
use Illuminate\Support\Collection;

class ValidationService implements ValidationInterface
{
    private RuleEngine $ruleEngine;
    private DataSanitizer $sanitizer;
    private SecurityConfig $config;
    private AuditLogger $logger;

    public function __construct(
        RuleEngine $ruleEngine,
        DataSanitizer $sanitizer,
        SecurityConfig $config,
        AuditLogger $logger
    ) {
        $this->ruleEngine = $ruleEngine;
        $this->sanitizer = $sanitizer;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function validateInput(array $data, ValidationRuleSet $rules): array
    {
        $sanitized = $this->sanitizer->sanitize($data);
        $validationStart = microtime(true);

        try {
            foreach ($rules->getRules() as $field => $fieldRules) {
                $value = $sanitized[$field] ?? null;
                
                foreach ($fieldRules as $rule) {
                    if (!$this->ruleEngine->validate($value, $rule)) {
                        throw new ValidationException(
                            "Validation failed for {$field}: {$rule->getMessage()}"
                        );
                    }
                }
            }

            $this->validateComplexRules($sanitized, $rules->getComplexRules());
            $this->validateSecurityConstraints($sanitized);
            
            return $sanitized;

        } catch (ValidationException $e) {
            $this->logValidationFailure($data, $e);
            throw $e;
        } finally {
            $this->recordMetrics($validationStart);
        }
    }

    public function validateBusinessRules(array $data, Collection $rules): bool
    {
        foreach ($rules as $rule) {
            if (!$rule->evaluate($data)) {
                $this->logger->logBusinessRuleViolation($rule, $data);
                return false;
            }
        }
        return true;
    }

    public function verifyIntegrity(OperationResult $result): bool
    {
        $integrityStart = microtime(true);

        try {
            // Verify data structure
            if (!$this->verifyDataStructure($result)) {
                return false;
            }

            // Verify checksums
            if (!$this->verifyChecksums($result)) {
                return false;
            }

            // Verify data consistency
            if (!$this->verifyDataConsistency($result)) {
                return false;
            }

            return true;

        } finally {
            $this->recordIntegrityCheck($integrityStart);
        }
    }

    private function validateComplexRules(array $data, Collection $rules): void
    {
        foreach ($rules as $rule) {
            if (!$rule->validate($data)) {
                throw new ValidationException(
                    "Complex rule validation failed: {$rule->getMessage()}"
                );
            }
        }
    }

    private function validateSecurityConstraints(array $data): void
    {
        // Validate against injection attacks
        $this->validateSqlInjection($data);
        $this->validateXssAttacks($data);
        
        // Validate against known attack patterns
        $this->validateAttackPatterns($data);
        
        // Validate data format security
        $this->validateSecureFormats($data);
    }

    private function validateSqlInjection(array $data): void
    {
        foreach ($data as $key => $value) {
            if (is_string($value) && $this->containsSqlInjection($value)) {
                throw new ValidationException("SQL injection detected in {$key}");
            }
        }
    }

    private function validateXssAttacks(array $data): void
    {
        foreach ($data as $key => $value) {
            if (is_string($value) && $this->containsXssPattern($value)) {
                throw new ValidationException("XSS attack pattern detected in {$key}");
            }
        }
    }

    private function validateAttackPatterns(array $data): void
    {
        foreach ($data as $key => $value) {
            if (is_string($value) && $this->matchesKnownAttackPattern($value)) {
                $this->logger->logAttackAttempt($key, $value);
                throw new ValidationException("Known attack pattern detected");
            }
        }
    }

    private function validateSecureFormats(array $data): void
    {
        foreach ($this->config->getSecureFormatRules() as $key => $format) {
            if (isset($data[$key]) && !$this->matchesSecureFormat($data[$key], $format)) {
                throw new ValidationException("Invalid secure format for {$key}");
            }
        }
    }

    private function verifyDataStructure(OperationResult $result): bool
    {
        return $result->hasRequiredFields() && 
               $result->hasValidTypes() && 
               $result->hasConsistentStructure();
    }

    private function verifyChecksums(OperationResult $result): bool
    {
        return $result->verifyDataChecksum() && 
               $result->verifyMetadataChecksum();
    }

    private function verifyDataConsistency(OperationResult $result): bool
    {
        return $result->hasConsistentRelations() && 
               $result->hasValidCalculations() && 
               $result->meetsBusinessRules();
    }

    private function logValidationFailure(array $data, ValidationException $e): void
    {
        $this->logger->logValidationFailure([
            'data' => $data,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function recordMetrics(float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        $this->logger->recordMetric('validation_duration', $duration);
    }

    private function recordIntegrityCheck(float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        $this->logger->recordMetric('integrity_check_duration', $duration);
    }
}
