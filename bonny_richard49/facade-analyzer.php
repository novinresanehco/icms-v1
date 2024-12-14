<?php

namespace App\Core\Analysis;

class FacadeAnalyzer implements FacadeAnalyzerInterface
{
    private FacadeRegistry $registry;
    private CodeAnalyzer $analyzer;
    private ValidationService $validator;
    private AuditLogger $logger;

    public function analyzeFacade(string $facadeClass): FacadeAnalysisResult
    {
        $operationId = $this->logger->startOperation('facade_analysis');

        try {
            $this->validateFacadeClass($facadeClass);
            $facade = $this->registry->getFacade($facadeClass);
            
            $structureAnalysis = $this->analyzeStructure($facade);
            $implementationAnalysis = $this->analyzeImplementation($facade);
            $securityAnalysis = $this->analyzeSecurityCompliance($facade);
            
            $result = $this->compileAnalysisResults(
                $structureAnalysis,
                $implementationAnalysis,
                $securityAnalysis
            );

            $this->logAnalysisSuccess($result, $operationId);
            return $result;

        } catch (\Throwable $e) {
            $this->handleAnalysisFailure($e, $facadeClass, $operationId);
            throw $e;
        }
    }

    protected function validateFacadeClass(string $facadeClass): void
    {
        if (!$this->validator->validateFacadeClass($facadeClass)) {
            throw new FacadeAnalysisException('Invalid facade class');
        }
    }

    protected function analyzeStructure(FacadeDefinition $facade): StructureAnalysisResult
    {
        return $this->analyzer->analyzeStructure($facade, [
            'enforceInterfaceSegregation' => true,
            'checkDependencyInversion' => true,
            'validateEncapsulation' => true,
            'verifyAbstraction' => true
        ]);
    }

    protected function analyzeImplementation(FacadeDefinition $facade): ImplementationAnalysisResult
    {
        return $this->analyzer->analyzeImplementation($facade, [
            'checkCodeQuality' => true,
            'validatePatternCompliance' => true,
            'verifyErrorHandling' => true,
            'assessPerformance' => true
        ]);
    }

    protected function analyzeSecurityCompliance(FacadeDefinition $facade): SecurityAnalysisResult
    {
        return $this->analyzer->analyzeSecurityCompliance($facade, [
            'validateInputHandling' => true,
            'checkAccessControl' => true,
            'verifyDataProtection' => true,
            'assessVulnerabilities' => true
        ]);
    }

    protected function compileAnalysisResults(
        StructureAnalysisResult $structure,
        ImplementationAnalysisResult $implementation,
        SecurityAnalysisResult $security
    ): FacadeAnalysisResult {
        return new FacadeAnalysisResult([
            'structure' => $structure,
            'implementation' => $implementation,
            'security' => $security,
            'timestamp' => time(),
            'status' => $this->determineAnalysisStatus(
                $structure,
                $implementation,
                $security
            )
        ]);
    }

    protected function determineAnalysisStatus(
        StructureAnalysisResult $structure,
        ImplementationAnalysisResult $implementation,
        SecurityAnalysisResult $security
    ): string {
        if (!$structure->isValid() || 
            !$implementation->isValid() || 
            !$security->isValid()) {
            return FacadeAnalysisResult::STATUS_FAILED;
        }

        if ($structure->hasWarnings() || 
            $implementation->hasWarnings() || 
            $security->hasWarnings()) {
            return FacadeAnalysisResult::STATUS_WARNING;
        }

        return FacadeAnalysisResult::STATUS_PASSED;
    }

    protected function logAnalysisSuccess(FacadeAnalysisResult $result, string $operationId): void
    {
        $this->logger->logSuccess([
            'type' => 'facade_analysis',
            'operation_id' => $operationId,
            'result' => $result->toArray(),
            'timestamp' => time()
        ]);
    }

    protected function handleAnalysisFailure(
        \Throwable $e,
        string $facadeClass,
        string $operationId
    ): void {
        $this->logger->logFailure([
            'type' => 'facade_analysis_failure',
            'operation_id' => $operationId,
            'facade_class' => $facadeClass,
            'error' => $e->getMessage(),
            'severity' => 'ERROR'
        ]);
    }
}
