<?php

namespace App\Core\Validation;

class ValidationChainService implements ValidationChainInterface
{
    private ArchitectureValidator $architectureValidator;
    private SecurityValidator $securityValidator;
    private QualityValidator $qualityValidator;
    private PerformanceValidator $performanceValidator;
    private ChainLogger $logger;
    private EmergencyProtocol $emergency;

    public function __construct(
        ArchitectureValidator $architectureValidator,
        SecurityValidator $securityValidator,
        QualityValidator $qualityValidator,
        PerformanceValidator $performanceValidator,
        ChainLogger $logger,
        EmergencyProtocol $emergency
    ) {
        $this->architectureValidator = $architectureValidator;
        $this->securityValidator = $securityValidator;
        $this->qualityValidator = $qualityValidator;
        $this->performanceValidator = $performanceValidator;
        $this->logger = $logger;
        $this->emergency = $emergency;
    }

    public function executeValidationChain(ValidationContext $context): ChainResult
    {
        $chainId = $this->initializeChain($context);
        
        try {
            DB::beginTransaction();

            $this->validateArchitecture($context);
            $this->validateSecurity($context);
            $this->validateQuality($context);
            $this->validatePerformance($context);

            $result = new ChainResult([
                'chainId' => $chainId,
                'status' => ValidationStatus::PASSED,
                'metrics' => $this->collectValidationMetrics(),
                'timestamp' => now()
            ]);

            DB::commit();
            $this->finalizeChain($result);
            
            return $result;

        } catch (ValidationException $e) {
            DB::rollBack();
            $this->handleValidationFailure($e, $chainId);
            throw new CriticalValidationException($e->getMessage(), $e);
        }
    }

    private function validateArchitecture(ValidationContext $context): void
    {
        $architectureResult = $this->architectureValidator->validate($context);
        
        if (!$architectureResult->isPassed()) {
            $this->emergency->handleArchitectureViolation($architectureResult);
            throw new ArchitectureValidationException(
                'Architecture validation failed',
                $architectureResult->getViolations()
            );
        }
    }

    private function validateSecurity(ValidationContext $context): void
    {
        $securityResult = $this->securityValidator->validate($context);
        
        if (!$securityResult->isPassed()) {
            $this->emergency->handleSecurityViolation($securityResult);
            throw new SecurityValidationException(
                'Security validation failed',
                $securityResult->getViolations()
            );
        }
    }

    private function finalizeChain(ChainResult $result): void
    {
        $this->logger->logChainCompletion($result);
        
        if ($result->hasWarnings()) {
            $this->emergency->assessWarnings($result->getWarnings());
        }
    }

    private function handleValidationFailure(
        ValidationException $e,
        string $chainId
    ): void {
        $this->logger->logFailure($e, $chainId);
        $this->emergency->handleValidationFailure($e);

        if ($e->isCritical()) {
            $this->emergency->lockdownSystem();
        }
    }
}
