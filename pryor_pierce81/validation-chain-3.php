<?php

namespace App\Core\Validation;

class ValidationChainService implements ValidationChainInterface
{
    private ArchitectureValidator $architectureValidator;
    private SecurityValidator $securityValidator;
    private QualityValidator $qualityValidator;
    private PerformanceValidator $performanceValidator;
    private ValidationLogger $logger;
    private AlertSystem $alerts;

    public function __construct(
        ArchitectureValidator $architectureValidator,
        SecurityValidator $securityValidator,
        QualityValidator $qualityValidator,
        PerformanceValidator $performanceValidator,
        ValidationLogger $logger,
        AlertSystem $alerts
    ) {
        $this->architectureValidator = $architectureValidator;
        $this->securityValidator = $securityValidator;
        $this->qualityValidator = $qualityValidator;
        $this->performanceValidator = $performanceValidator;
        $this->logger = $logger;
        $this->alerts = $alerts;
    }

    public function executeValidationChain(ValidationRequest $request): ValidationResult
    {
        $chainId = $this->initializeChain($request);
        
        try {
            DB::beginTransaction();
            
            $this->validateArchitecture($request);
            $this->validateSecurity($request);
            $this->validateQuality($request);
            $this->validatePerformance($request);
            
            $result = new ValidationResult([
                'chain_id' => $chainId,
                'status' => ValidationStatus::PASSED,
                'timestamp' => now()
            ]);
            
            $this->logger->logSuccess($result);
            
            DB::commit();
            return $result;

        } catch (ValidationException $e) {
            DB::rollBack();
            $this->handleValidationFailure($e, $chainId);
            throw new CriticalValidationException($e->getMessage(), $e);
        }
    }

    private function validateArchitecture(ValidationRequest $request): void
    {
        if (!$this->architectureValidator->validate($request)) {
            throw new ArchitectureValidationException('Architecture validation failed');
        }
    }

    private function validateSecurity(ValidationRequest $request): void
    {
        if (!$this->securityValidator->validate($request)) {
            throw new SecurityValidationException('Security validation failed');
        }
    }

    private function validateQuality(ValidationRequest $request): void
    {
        if (!$this->qualityValidator->validate($request)) {
            throw new QualityValidationException('Quality validation failed');
        }
    }

    private function validatePerformance(ValidationRequest $request): void
    {
        if (!$this->performanceValidator->validate($request)) {
            throw new PerformanceValidationException('Performance validation failed');
        }
    }

    private function handleValidationFailure(ValidationException $e, string $chainId): void
    {
        $this->logger->logFailure($e, $chainId);
        
        $this->alerts->dispatch(
            new ValidationAlert(
                'Critical validation chain failure',
                [
                    'chain_id' => $chainId,
                    'exception' => $e
                ]
            )
        );
    }
}
