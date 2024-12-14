<?php

namespace App\Core\Constraint;

class ConstraintValidationService implements ConstraintInterface
{
    private RuleEngine $ruleEngine;
    private DependencyValidator $dependencyValidator;
    private IntegrityVerifier $integrityVerifier;
    private CircuitBreaker $circuitBreaker;
    private ConstraintLogger $logger;
    private AlertDispatcher $alerts;

    public function __construct(
        RuleEngine $ruleEngine,
        DependencyValidator $dependencyValidator,
        IntegrityVerifier $integrityVerifier,
        CircuitBreaker $circuitBreaker,
        ConstraintLogger $logger,
        AlertDispatcher $alerts
    ) {
        $this->ruleEngine = $ruleEngine;
        $this->dependencyValidator = $dependencyValidator;
        $this->integrityVerifier = $integrityVerifier;
        $this->circuitBreaker = $circuitBreaker;
        $this->logger = $logger;
        $this->alerts = $alerts;
    }

    public function validateConstraints(ValidationContext $context): ValidationResult
    {
        $validationId = $this->initializeValidation($context);
        
        try {
            DB::beginTransaction();

            $this->validateRules($context);
            $this->validateDependencies($context);
            $this->verifyIntegrity($context);
            
            if ($this->circuitBreaker->isTripped()) {
                throw new CircuitBreakerException('Circuit breaker tripped during validation');
            }

            $result = new ValidationResult([
                'validationId' => $validationId,
                'status' => ValidationStatus::PASSED,
                'metrics' => $this->collectMetrics($context),
                'timestamp' => now()
            ]);

            DB::commit();
            $this->logger->logSuccess($result);

            return $result;

        } catch (ValidationException $e) {
            DB::rollBack();
            $this->handleValidationFailure($e, $validationId);
            throw new CriticalValidationException($e->getMessage(), $e);
        }
    }

    private function validateRules(ValidationContext $context): void
    {
        $violations = $this->ruleEngine->evaluateRules($context);
        
        if (!empty($violations)) {
            throw new RuleViolationException(
                'Constraint rules violated',
                ['violations' => $violations]
            );
        }
    }

    private function validateDependencies(ValidationContext $context): void
    {
        $dependencyIssues = $this->dependencyValidator->validate($context);
        
        if (!empty($dependencyIssues)) {
            throw new DependencyValidationException(
                'Dependency validation failed',
                ['issues' => $dependencyIssues]
            );
        }
    }

    private function verifyIntegrity(ValidationContext $context): void
    {
        if (!$this->integrityVerifier->verify($context)) {
            $this->circuitBreaker->trip();
            throw new IntegrityException('Constraint integrity verification failed');
        }
    }

    private function handleValidationFailure(ValidationException $e, string $validationId): void
    {
        $this->logger->logFailure($e, $validationId);

        $this->alerts->dispatch(
            new ValidationAlert(
                'Critical constraint validation failure',
                [
                    'validationId' => $validationId,
                    'exception' => $e,
                    'context' => $this->collectFailureContext($e)
                ]
            )
        );

        if ($e->isCritical()) {
            $this->circuitBreaker->trip();
        }
    }
}
