<?php

namespace App\Core\Quality;

class QualityControlService implements QualityControlInterface
{
    private CodeAnalyzer $codeAnalyzer;
    private RuntimeValidator $runtimeValidator;
    private PerformanceMonitor $performanceMonitor;
    private QualityMetrics $metrics;
    private IntegrityVerifier $integrityVerifier;
    private QualityLogger $logger;

    public function __construct(
        CodeAnalyzer $codeAnalyzer,
        RuntimeValidator $runtimeValidator,
        PerformanceMonitor $performanceMonitor,
        QualityMetrics $metrics,
        IntegrityVerifier $integrityVerifier,
        QualityLogger $logger
    ) {
        $this->codeAnalyzer = $codeAnalyzer;
        $this->runtimeValidator = $runtimeValidator;
        $this->performanceMonitor = $performanceMonitor;
        $this->metrics = $metrics;
        $this->integrityVerifier = $integrityVerifier;
        $this->logger = $logger;
    }

    public function validateQuality(QualityContext $context): QualityResult
    {
        $validationId = $this->initializeValidation($context);
        
        try {
            DB::beginTransaction();

            $this->validateCodeQuality($context);
            $this->validateRuntime($context);
            $this->validatePerformance($context);
            $this->validateIntegrity($context);

            $result = new QualityResult([
                'validationId' => $validationId,
                'metrics' => $this->collectMetrics($context),
                'status' => QualityStatus::PASSED,
                'timestamp' => now()
            ]);

            $this->logger->logValidationSuccess($result);
            
            DB::commit();
            return $result;

        } catch (QualityException $e) {
            DB::rollBack();
            $this->handleValidationFailure($e, $validationId);
            throw new CriticalQualityException($e->getMessage(), $e);
        }
    }

    private function validateCodeQuality(QualityContext $context): void
    {
        $violations = $this->codeAnalyzer->analyze($context);
        
        if (!empty($violations)) {
            throw new CodeQualityException(
                'Code quality standards violated',
                ['violations' => $violations]
            );
        }
    }

    private function validateRuntime(QualityContext $context): void
    {
        $runtimeIssues = $this->runtimeValidator->validate($context);
        
        if (!empty($runtimeIssues)) {
            throw new RuntimeValidationException(
                'Runtime validation failed',
                ['issues' => $runtimeIssues]
            );
        }
    }

    private function validateIntegrity(QualityContext $context): void
    {
        if (!$this->integrityVerifier->verify($context)) {
            throw new IntegrityException('Quality integrity verification failed');
        }
    }

    private function collectMetrics(QualityContext $context): array
    {
        return [
            'code_quality' => $this->codeAnalyzer->getMetrics(),
            'runtime' => $this->runtimeValidator->getMetrics(),
            'performance' => $this->performanceMonitor->getMetrics()
        ];
    }
}

