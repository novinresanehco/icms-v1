<?php

namespace App\Core\Security\Validation;

class ValidationService implements ValidationInterface 
{
    private PatternValidator $patternValidator;
    private SecurityValidator $securityValidator; 
    private PerformanceValidator $performanceValidator;
    private ComplianceValidator $complianceValidator;
    private ValidationLogger $logger;
    private AlertSystem $alerts;

    public function __construct(
        PatternValidator $patternValidator,
        SecurityValidator $securityValidator,
        PerformanceValidator $performanceValidator,
        ComplianceValidator $complianceValidator,
        ValidationLogger $logger,
        AlertSystem $alerts
    ) {
        $this->patternValidator = $patternValidator;
        $this->securityValidator = $securityValidator;
        $this->performanceValidator = $performanceValidator;
        $this->complianceValidator = $complianceValidator;
        $this->logger = $logger;
        $this->alerts = $alerts;
    }

    public function validateOperation(
        Operation $operation,
        ValidationContext $context
    ): ValidationResult {
        DB::beginTransaction();
        try {
            $this->validatePatterns($operation, $context);
            $this->validateSecurity($operation, $context);
            $this->validatePerformance($operation, $context);
            $this->validateCompliance($operation, $context);

            $result = new ValidationResult(
                true,
                $this->collectValidationMetrics($operation)
            );

            $this->logger->logSuccess($operation, $result);
            DB::commit();

            return $result;

        } catch (ValidationException $e) {
            DB::rollBack();
            $this->handleValidationFailure($e, $operation);
            throw new CriticalValidationException($e->getMessage(), $e);
        }
    }

    private function validatePatterns(
        Operation $operation,
        ValidationContext $context
    ): void {
        $violations = $this->patternValidator->validate($operation, $context);
        
        if (!empty($violations)) {
            throw new PatternValidationException(
                'Pattern validation failed',
                ['violations' => $violations]
            );
        }
    }

    private function validateSecurity(
        Operation $operation,
        ValidationContext $context
    ): void {
        $vulnerabilities = $this->securityValidator->validate($operation, $context);
        
        if (!empty($vulnerabilities)) {
            throw new SecurityValidationException(
                'Security validation failed',
                ['vulnerabilities' => $vulnerabilities]
            );
        }
    }

    private function validatePerformance(
        Operation $operation,
        ValidationContext $context
    ): void {
        $issues = $this->performanceValidator->validate($operation, $context);
        
        if (!empty($issues)) {
            throw new PerformanceValidationException(
                'Performance validation failed',
                ['issues' => $issues]
            );
        }
    }

    private function validateCompliance(
        Operation $operation,
        ValidationContext $context
    ): void {
        $violations = $this->complianceValidator->validate($operation, $context);
        
        if (!empty($violations)) {
            throw new ComplianceValidationException(
                'Compliance validation failed',
                ['violations' => $violations]
            );
        }
    }

    private function handleValidationFailure(
        ValidationException $e,
        Operation $operation
    ): void {
        $this->logger->logFailure($e, $operation);
        $this->alerts->dispatch(
            new ValidationAlert(
                $e->getMessage(),
                [
                    'operation' => $operation,
                    'exception' => $e
                ]
            )
        );
    }
}
