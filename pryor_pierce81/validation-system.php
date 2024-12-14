namespace App\Core\Validation;

class ValidationManager implements ValidationInterface 
{
    private SecurityManager $security;
    private RuleRegistry $rules;
    private SanitizerService $sanitizer;
    private MetricsCollector $metrics;
    private AuditLogger $logger;

    public function validateInput(array $data, array $rules): ValidationResult 
    {
        return $this->security->executeCriticalOperation(
            new ValidationOperation(function() use ($data, $rules) {
                $startTime = microtime(true);
                
                try {
                    $sanitized = $this->sanitizer->sanitize($data);
                    $validationErrors = [];

                    foreach ($rules as $field => $fieldRules) {
                        try {
                            $this->validateField(
                                $field, 
                                $sanitized[$field] ?? null, 
                                $fieldRules
                            );
                        } catch (ValidationException $e) {
                            $validationErrors[$field] = $e->getMessage();
                        }
                    }

                    if (!empty($validationErrors)) {
                        throw new ValidationFailedException($validationErrors);
                    }

                    $this->metrics->histogram(
                        'validation.duration',
                        microtime(true) - $startTime
                    );

                    return new ValidationResult($sanitized);

                } catch (\Exception $e) {
                    $this->handleValidationError($data, $rules, $e);
                    throw $e;
                }
            })
        );
    }

    private function validateField(string $field, $value, array $rules): void 
    {
        foreach ($rules as $rule) {
            $validator = $this->rules->get($rule);
            
            if (!$validator->isValid($value)) {
                $this->metrics->increment('validation.failures');
                throw new ValidationException($validator->getMessage());
            }
        }
    }

    public function verifyDataIntegrity(array $data): bool 
    {
        return $this->security->executeCriticalOperation(
            new IntegrityCheckOperation(function() use ($data) {
                return $this->runIntegrityChecks($data);
            })
        );
    }

    private function runIntegrityChecks(array $data): bool 
    {
        try {
            // Check data structure
            if (!$this->validateStructure($data)) {
                return false;
            }

            // Verify data types
            if (!$this->validateTypes($data)) {
                return false;
            }

            // Check for malicious content
            if ($this->containsMaliciousContent($data)) {
                $this->logger->warning('validation.malicious_content_detected', [
                    'data_hash' => hash('sha256', serialize($data))
                ]);
                return false;
            }

            return true;

        } catch (\Exception $e) {
            $this->handleIntegrityError($data, $e);
            return false;
        }
    }

    private function validateStructure(array $data): bool 
    {
        foreach ($this->rules->getRequiredFields() as $field) {
            if (!isset($data[$field])) {
                $this->metrics->increment('validation.structure_failures');
                return false;
            }
        }
        return true;
    }

    private function validateTypes(array $data): bool 
    {
        foreach ($data as $field => $value) {
            $expectedType = $this->rules->getExpectedType($field);
            
            if ($expectedType && gettype($value) !== $expectedType) {
                $this->metrics->increment('validation.type_failures');
                return false;
            }
        }
        return true;
    }

    private function containsMaliciousContent(array $data): bool 
    {
        foreach ($data as $value) {
            if (is_string($value)) {
                if ($this->detectXSS($value) || 
                    $this->detectSQLInjection($value) || 
                    $this->detectCommandInjection($value)) {
                    
                    $this->metrics->increment('validation.security_failures');
                    return true;
                }
            }
        }
        return false;
    }

    private function detectXSS(string $value): bool 
    {
        return preg_match('/<script\b[^>]*>(.*?)<\/script>/is', $value) ||
               preg_match('/javascript:/i', $value) ||
               preg_match('/on\w+=[\'"].*?[\'"]/', $value);
    }

    private function detectSQLInjection(string $value): bool 
    {
        return preg_match('/(\b(select|insert|update|delete|drop|union|alter)\b)/i', $value);
    }

    private function detectCommandInjection(string $value): bool 
    {
        return preg_match('/[&|;`$]/', $value);
    }

    private function handleValidationError(array $data, array $rules, \Exception $e): void 
    {
        $this->metrics->increment('validation.errors');
        
        $this->logger->error('validation.error', [
            'data_hash' => hash('sha256', serialize($data)),
            'rules' => $rules,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function handleIntegrityError(array $data, \Exception $e): void 
    {
        $this->metrics->increment('validation.integrity_failures');
        
        $this->logger->error('validation.integrity_error', [
            'data_hash' => hash('sha256', serialize($data)),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
