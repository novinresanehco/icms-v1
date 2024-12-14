namespace App\Core\Protection;

class ValidationManager implements ValidationInterface
{
    private SecurityManager $security;
    private ErrorHandler $errorHandler;
    private AuditLogger $auditLogger;
    private RuleValidator $validator;

    public function __construct(
        SecurityManager $security,
        ErrorHandler $errorHandler,
        AuditLogger $auditLogger,
        RuleValidator $validator
    ) {
        $this->security = $security;
        $this->errorHandler = $errorHandler;
        $this->auditLogger = $auditLogger;
        $this->validator = $validator;
    }

    public function validateOperation(OperationContext $context): ValidationResult
    {
        DB::beginTransaction();
        
        try {
            // Security validation
            $this->validateSecurity($context);
            
            // Data validation
            $this->validateData($context);
            
            // Business rules validation
            $this->validateBusinessRules($context);
            
            // Log successful validation
            $this->auditLogger->logValidation($context);
            
            DB::commit();
            return new ValidationResult(true);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleValidationFailure($e, $context);
            throw $e;
        }
    }

    private function validateSecurity(OperationContext $context): void
    {
        if (!$this->security->validateContext($context)) {
            throw new SecurityValidationException('Security validation failed');
        }
    }

    private function validateData(OperationContext $context): void
    {
        if (!$this->validator->validateData($context->getData(), $context->getRules())) {
            throw new DataValidationException($this->validator->getErrors());
        }
    }

    private function validateBusinessRules(OperationContext $context): void
    {
        if (!$this->validator->validateBusinessRules($context)) {
            throw new BusinessRuleException('Business rule validation failed');
        }
    }

    private function handleValidationFailure(\Exception $e, OperationContext $context): void
    {
        $this->errorHandler->handleError($e, [
            'context' => $context->toArray(),
            'validation_type' => get_class($e),
            'timestamp' => now()
        ]);

        $this->auditLogger->logValidationFailure($e, $context);
    }
}

class ErrorHandler implements ErrorHandlerInterface
{
    private LogManager $logger;
    private AlertSystem $alerts;
    private MetricsCollector $metrics;
    private RecoveryManager $recovery;

    public function handleError(\Throwable $e, array $context = []): void
    {
        // Log error with full context
        $this->logError($e, $context);
        
        // Record error metrics
        $this->recordErrorMetrics($e, $context);
        
        // Alert if critical
        if ($this->isCriticalError($e)) {
            $this->alertCriticalError($e, $context);
        }
        
        // Attempt recovery if possible
        if ($this->isRecoverable($e)) {
            $this->attemptRecovery($e, $context);
        }
    }

    private function logError(\Throwable $e, array $context): void
    {
        $errorData = [
            'error' => [
                'type' => get_class($e),
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ],
            'context' => $context,
            'timestamp' => now(),
            'environment' => app()->environment(),
            'server' => request()->server()
        ];

        $this->logger->error('System error occurred', $errorData);
    }

    private function recordErrorMetrics(\Throwable $e, array $context): void
    {
        $this->metrics->incrementError(
            get_class($e),
            $this->getErrorSeverity($e)
        );
    }

    private function isCriticalError(\Throwable $e): bool
    {
        return $e instanceof CriticalException || 
               $this->getErrorSeverity($e) >= ErrorSeverity::HIGH;
    }

    private function isRecoverable(\Throwable $e): bool
    {
        return $e instanceof RecoverableException &&
               $this->recovery->canRecover($e);
    }

    private function getErrorSeverity(\Throwable $e): int
    {
        if ($e instanceof SeverityAwareException) {
            return $e->getSeverity();
        }
        
        return ErrorSeverity::MEDIUM;
    }

    private function alertCriticalError(\Throwable $e, array $context): void
    {
        $this->alerts->sendCriticalAlert([
            'error' => get_class($e),
            'message' => $e->getMessage(),
            'severity' => $this->getErrorSeverity($e),
            'context' => $context
        ]);
    }

    private function attemptRecovery(\Throwable $e, array $context): void
    {
        try {
            $this->recovery->executeRecovery($e, $context);
        } catch (\Exception $recoveryError) {
            $this->logError($recoveryError, [
                'original_error' => $e,
                'context' => $context,
                'recovery_attempted' => true
            ]);
        }
    }
}

class BusinessRuleValidator implements RuleValidatorInterface
{
    private array $rules = [];
    private array $errors = [];

    public function validateBusinessRules(OperationContext $context): bool
    {
        $this->errors = [];
        
        foreach ($this->rules as $rule) {
            if (!$this->validateRule($rule, $context)) {
                return false;
            }
        }
        
        return true;
    }

    private function validateRule(BusinessRule $rule, OperationContext $context): bool
    {
        if (!$rule->validate($context)) {
            $this->errors[] = $rule->getMessage();
            return false;
        }
        
        return true;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}

interface ErrorSeverity
{
    const LOW = 1;
    const MEDIUM = 2;
    const HIGH = 3;
    const CRITICAL = 4;
}
