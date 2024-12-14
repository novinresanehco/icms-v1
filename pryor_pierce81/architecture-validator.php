<?php

namespace App\Core\Architecture;

class ArchitectureValidator implements ArchitectureInterface
{
    private PatternAnalyzer $patternAnalyzer;
    private ComplianceChecker $complianceChecker;
    private StandardsValidator $standardsValidator;
    private IntegrityVerifier $integrityVerifier;
    private ValidationLogger $logger;
    private AlertSystem $alerts;

    public function __construct(
        PatternAnalyzer $patternAnalyzer,
        ComplianceChecker $complianceChecker,
        StandardsValidator $standardsValidator,
        IntegrityVerifier $integrityVerifier,
        ValidationLogger $logger,
        AlertSystem $alerts
    ) {
        $this->patternAnalyzer = $patternAnalyzer;
        $this->complianceChecker = $complianceChecker;
        $this->standardsValidator = $standardsValidator;
        $this->integrityVerifier = $integrityVerifier;
        $this->logger = $logger;
        $this->alerts = $alerts;
    }

    public function validateArchitecture(ArchitectureContext $context): ValidationResult
    {
        $validationId = $this->initializeValidation($context);
        
        try {
            DB::beginTransaction();
            
            $this->validatePatterns($context);
            $this->checkCompliance($context);
            $this->validateStandards($context);
            $this->verifyIntegrity($context);
            
            $result = new ValidationResult([
                'validationId' => $validationId,
                'status' => ValidationStatus::PASSED,
                'timestamp' => now()
            ]);
            
            DB::commit();
            return $result;

        } catch (ValidationException $e) {
            DB::rollBack();
            $this->handleValidationFailure($e, $validationId);
            throw new CriticalValidationException($e->getMessage(), $e);
        }
    }

    private function validatePatterns(ArchitectureContext $context): void
    {
        $deviations = $this->patternAnalyzer->detectDeviations($context);
        
        if (!empty($deviations)) {
            throw new PatternDeviationException(
                'Architecture pattern deviations detected',
                ['deviations' => $deviations]
            );
        }
    }

    private function checkCompliance(ArchitectureContext $context): void
    {
        $violations = $this->complianceChecker->checkCompliance($context);
        
        if (!empty($violations)) {
            throw new ComplianceViolationException(
                'Architecture compliance violations detected',
                ['violations' => $violations]
            );
        }
    }

    private function validateStandards(ArchitectureContext $context): void
    {
        $standardsViolations = $this->standardsValidator->validate($context);
        
        if (!empty($standardsViolations)) {
            throw new StandardsViolationException(
                'Architecture standards violations detected',
                ['violations' => $standardsViolations]
            );
        }
    }

    private function verifyIntegrity(ArchitectureContext $context): void
    {
        if (!$this->integrityVerifier->verify($context)) {
            throw new IntegrityViolationException('Architecture integrity verification failed');
        }
    }

    private function handleValidationFailure(ValidationException $e, string $validationId): void
    {
        $this->logger->logFailure($e, $validationId);
        
        $this->alerts->dispatch(
            new ValidationAlert(
                'Critical architecture validation failure',
                [
                    'validationId' => $validationId,
                    'exception' => $e
                ]
            )
        );
    }
}
