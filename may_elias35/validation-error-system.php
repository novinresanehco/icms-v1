<?php

namespace App\Core\Validation;

class ValidationManager implements ValidationInterface
{
    private SecurityManager $security;
    private RuleRegistry $rules;
    private ErrorHandler $errors;
    private AuditLogger $logger;

    public function validate(array $data, array $rules): ValidationResult
    {
        return $this->security->executeCriticalOperation(
            new ValidateDataOperation(
                $data,
                $rules,
                $this->rules,
                $this->errors
            )
        );
    }

    public function validateCritical(array $data, array $rules): ValidationResult
    {
        try {
            $result = $this->validate($data, $rules);
            
            if (!$result->isValid()) {
                $this->logger->logValidationFailure($data, $result->getErrors());
                throw new ValidationException($result->getErrors());
            }
            
            return $result;
        } catch (\Exception $e) {
            $this->handleValidationError($e, $data);
            throw $e;
        }
    }
}

class ErrorHandler implements ErrorInterface
{
    private SecurityManager $security;
    private LogManager $logs;
    private AlertDispatcher $alerts;
    private array $errorPatterns;

    public function handleError(\Throwable $error, Context $context): void
    {
        $severity = $this->classifyError($error);
        
        $this->logError($error, $context, $severity);
        
        if ($this->isCritical($severity)) {
            $this->handleCriticalError($error, $context);
        }

        if ($this->isSecurityRelated($error)) {
            $this->handleSecurityError($error, $context);
        }
    }

    private function handleCriticalError(\Throwable $error, Context $context): void
    {
        $this->alerts->dispatchCritical([
            'error' => $error->getMessage(),
            'context' => $context->toArray(),
            'stack_trace' => $error->getTraceAsString(),
            'timestamp' => now()
        ]);

        $this->security->logSecurityEvent(
            new SecurityEvent('critical_error', $error)
        );
    }

    private function handleSecurityError(\Throwable $error, Context $context): void
    {
        $this->security->handleSecurityBreach(
            new SecurityBreach($error, $context)
        );
        
        $this->alerts->dispatchSecurityAlert([
            'type' => 'security_error',
            'details' => $error->getMessage(),
            'context' => $context->toArray()
        ]);
    }
}

class ValidateDataOperation implements CriticalOperation
{
    private array $data;
    private array $rules;
    private RuleRegistry $registry;
    private ErrorHandler $errors;

    public function execute(): ValidationResult
    {
        $violations = [];

        foreach ($this->rules as $field => $ruleSet) {
            $value = $this->data[$field] ?? null;
            
            foreach ($this->parseRules($ruleSet) as $rule) {
                if (!$this->validateRule($rule, $value)) {
                    $violations[$field][] = $this->formatViolation($rule);
                }
            }
        }

        return new ValidationResult(
            empty($violations),
            $violations
        );
    }

    private function validateRule(Rule $rule, $value): bool
    {
        $validator = $this->registry->getValidator($rule->getName());
        
        try {
            return $validator->validate($value, $rule->getParameters());
        } catch (\Exception $e) {
            $this->errors->handleError($e, new Context([
                'rule' => $rule->getName(),
                'value' => $value
            ]));
            return false;
        }
    }
}

class RuleRegistry
{
    private array $validators = [];
    private SecurityManager $security;

    public function register(string $name, Validator $validator): void
    {
        $this->validators[$name] = $validator;
    }

    public function getValidator(string $name): Validator
    {
        if (!isset($this->validators[$name])) {
            throw new ValidationException("Unknown validator: $name");
        }

        return $this->security->validateValidator(
            $this->validators[$name]
        );
    }
}

class Validator
{
    private SecurityContext $context;
    private ValidationRules $rules;

    public function validate($value, array $parameters = []): bool
    {
        if (!$this->context->isSecure()) {
            throw new SecurityException('Insecure validation context');
        }

        return $this->rules->evaluate($value, $parameters);
    }

    protected function sanitizeInput($value)
    {
        return htmlspecialchars(
            strip_tags(trim($value)),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

class ValidationResult
{
    private bool $valid;
    private array $errors;
    private array $meta;

    public function __construct(bool $valid, array $errors = [], array $meta = [])
    {
        $this->valid = $valid;
        $this->errors = $errors;
        $this->meta = $meta;
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
        $this->valid = false;
    }

    public function getMeta(): array
    {
        return $this->meta;
    }
}
