namespace App\Core\Validation;

class ValidationManager implements ValidationInterface 
{
    private SecurityManager $security;
    private RuleRepository $rules;
    private AuditLogger $logger;
    private array $config;

    public function validate(array $data, string $context): ValidationResult 
    {
        return $this->security->executeCriticalOperation(
            new ValidationOperation($data, $context),
            function() use ($data, $context) {
                // Load validation rules
                $rules = $this->loadValidationRules($context);
                
                // Pre-validation hooks
                $this->executePreValidationHooks($data, $rules);
                
                // Core validation
                $results = $this->executeValidation($data, $rules);
                
                // Business rules validation
                $this->validateBusinessRules($data, $context);
                
                // Security validation
                $this->validateSecurityConstraints($data, $context);
                
                // Log validation result
                $this->logValidation($data, $context, $results);
                
                return new ValidationResult($results);
            }
        );
    }

    public function validateField(mixed $value, string $field, string $context): FieldValidationResult 
    {
        return $this->security->executeCriticalOperation(
            new FieldValidationOperation($value, $field, $context),
            function() use ($value, $field, $context) {
                $rules = $this->loadFieldRules($field, $context);
                return $this->executeFieldValidation($value, $rules);
            }
        );
    }

    protected function loadValidationRules(string $context): ValidationRuleSet 
    {
        $rules = $this->rules->getForContext($context);
        
        if (!$rules) {
            throw new ValidationException("No rules defined for context: $context");
        }
        
        return new ValidationRuleSet($rules);
    }

    protected function executePreValidationHooks(array $data, ValidationRuleSet $rules): void 
    {
        foreach ($rules->getPreValidationHooks() as $hook) {
            $hook->execute($data);
        }
    }

    protected function executeValidation(array $data, ValidationRuleSet $rules): array 
    {
        $results = [];
        
        foreach ($rules as $field => $fieldRules) {
            $value = $data[$field] ?? null;
            
            $fieldResult = $this->validateFieldValue(
                $value,
                $fieldRules,
                $data
            );
            
            $results[$field] = $fieldResult;
            
            if ($fieldResult->hasErrors() && $rules->isFailFast()) {
                break;
            }
        }
        
        return $results;
    }

    protected function validateFieldValue(
        mixed $value,
        array $rules,
        array $context
    ): FieldValidationResult {
        $errors = [];
        
        foreach ($rules as $rule) {
            try {
                $rule->validate($value, $context);
            } catch (ValidationException $e) {
                $errors[] = $e->getMessage();
                
                if ($rule->isFailFast()) {
                    break;
                }
            }
        }
        
        return new FieldValidationResult($errors);
    }

    protected function validateBusinessRules(array $data, string $context): void 
    {
        $rules = $this->rules->getBusinessRules($context);
        
        foreach ($rules as $rule) {
            if (!$rule->isSatisfied($data)) {
                throw new BusinessRuleException(
                    $rule->getMessage(),
                    $rule->getCode()
                );
            }
        }
    }

    protected function validateSecurityConstraints(array $data, string $context): void 
    {
        // Input sanitization
        $data = $this->sanitizeInput($data);
        
        // Security checks
        foreach ($this->config['security_constraints'] as $constraint) {
            if (!$constraint->verify($data, $context)) {
                throw new SecurityConstraintException(
                    $constraint->getMessage()
                );
            }
        }
    }

    protected function sanitizeInput(array $data): array 
    {
        return array_map(function($value) {
            if (is_string($value)) {
                return $this->sanitizeString($value);
            } elseif (is_array($value)) {
                return $this->sanitizeInput($value);
            }
            return $value;
        }, $data);
    }

    protected function sanitizeString(string $value): string 
    {
        // XSS prevention
        $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        
        // SQL injection prevention
        $value = addslashes($value);
        
        // Additional sanitization based on config
        foreach ($this->config['sanitizers'] as $sanitizer) {
            $value = $sanitizer->sanitize($value);
        }
        
        return $value;
    }

    protected function logValidation(
        array $data,
        string $context,
        array $results
    ): void {
        $this->logger->logValidation([
            'context' => $context,
            'data_hash' => hash('sha256', serialize($data)),
            'results' => $results,
            'timestamp' => microtime(true)
        ]);
    }
}
