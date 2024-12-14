<?php

namespace App\Core\Validation;

class PatternValidator implements PatternValidatorInterface
{
    private RuleEngine $ruleEngine;
    private PatternMatcher $patternMatcher;
    private ComplianceChecker $complianceChecker;
    private ValidationLogger $logger;
    private MetricsCollector $metrics;
    private AlertSystem $alerts;

    public function __construct(
        RuleEngine $ruleEngine,
        PatternMatcher $patternMatcher,
        ComplianceChecker $complianceChecker,
        ValidationLogger $logger,
        MetricsCollector $metrics,
        AlertSystem $alerts
    ) {
        $this->ruleEngine = $ruleEngine;
        $this->patternMatcher = $patternMatcher;
        $this->complianceChecker = $complianceChecker;
        $this->logger = $logger;
        $this->metrics = $metrics;
        $this->alerts = $alerts;
    }

    public function validatePattern(ValidationContext $context): ValidationResult
    {
        $validationId = $this->initializeValidation($context);
        
        try {
            DB::beginTransaction();
            
            $this->validateRules($context);
            $patterns = $this->detectPatterns($context);
            $this->verifyCompliance($patterns, $context);
            
            $result = new ValidationResult([
                'patterns' => $patterns,
                'validation_id' => $validationId
            ]);
            
            DB::commit();
            $this->recordValidationSuccess($result);
            
            return $result;

        } catch (ValidationException $e) {
            DB::rollBack();
            $this->handleValidationFailure($e, $validationId);
            throw new CriticalValidationException($e->getMessage(), $e);
        }
    }

    private function validateRules(ValidationContext $context): void
    {
        $violations = $this->ruleEngine->validate($context);
        
        if (!empty($violations)) {
            throw new RuleValidationException(
                'Pattern rule validation failed',
                ['violations' => $violations]
            );
        }
    }

    private function detectPatterns(ValidationContext $context): array
    {
        $patterns = $this->patternMatcher->detectPatterns($context);
        
        if (empty($patterns)) {
            throw new PatternDetectionException('No valid patterns detected');
        }
        
        return $patterns;
    }

    private function verifyCompliance(array $patterns, ValidationContext $context): void
    {
        if (!$this->complianceChecker->verifyCompliance($patterns, $context)) {
            throw new ComplianceException('Pattern compliance verification failed');
        }
    }

    private function handleValidationFailure(
        ValidationException $e,
        string $validationId
    ): void {
        $this->logger->logFailure($e, $validationId);
        
        $this->alerts->dispatch(
            new ValidationAlert(
                'Pattern validation failed',
                [
                    'validation_id' => $validationId,
                    'exception' => $e
                ]
            )
        );
        
        $this->metrics->recordFailure('pattern_validation', [
            'validation_id' => $validationId,
            'error' => $e->getMessage()
        ]);
    }
}
