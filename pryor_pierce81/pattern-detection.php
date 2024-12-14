<?php

namespace App\Core\Pattern;

class PatternDetectionSystem implements DetectionInterface
{
    private ReferenceArchitecture $reference;
    private PatternMatcher $matcher;
    private DeviationAnalyzer $analyzer;
    private CriticalLogger $logger;

    public function detectDeviations(Operation $operation): DetectionResult
    {
        DB::beginTransaction();

        try {
            $architectureResult = $this->validateArchitecture($operation);
            $securityResult = $this->validateSecurity($operation);
            $qualityResult = $this->validateQuality($operation);
            $performanceResult = $this->validatePerformance($operation);

            if ($this->hasAnyViolations([
                $architectureResult,
                $securityResult, 
                $qualityResult,
                $performanceResult
            ])) {
                throw new CriticalDeviationException('Pattern violations detected');
            }

            DB::commit();
            return new DetectionResult(success: true);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleDetectionFailure($e);
            throw $e;
        }
    }

    private function validateArchitecture(Operation $operation): ValidationResult
    {
        $pattern = $this->reference->getArchitecturePattern();
        $matches = $this->matcher->matchArchitecture($operation, $pattern);
        
        if (!$matches->isValid()) {
            $this->escalateViolation('architecture', $matches->getViolations());
        }
        
        return $matches;
    }

    private function validateSecurity(Operation $operation): ValidationResult 
    {
        $pattern = $this->reference->getSecurityPattern();
        $matches = $this->matcher->matchSecurity($operation, $pattern);

        if (!$matches->isValid()) {
            $this->escalateViolation('security', $matches->getViolations());
        }

        return $matches;
    }

    private function validateQuality(Operation $operation): ValidationResult
    {
        $metrics = $this->analyzer->analyzeQuality($operation);
        $result = $this->matcher->matchQualityMetrics($metrics);

        if (!$result->isValid()) {
            $this->escalateViolation('quality', $result->getViolations());
        }

        return $result;
    }

    private function validatePerformance(Operation $operation): ValidationResult
    {
        $metrics = $this->analyzer->analyzePerformance($operation);
        $result = $this->matcher->matchPerformanceMetrics($metrics);

        if (!$result->isValid()) {
            $this->escalateViolation('performance', $result->getViolations());
        }

        return $result;
    }

    private function hasAnyViolations(array $results): bool
    {
        foreach ($results as $result) {
            if (!$result->isValid()) {
                return true;
            }
        }
        return false;
    }

    private function escalateViolation(string $type, array $violations): void
    {
        $this->logger->logCriticalViolation([
            'type' => $type,
            'violations' => $violations,
            'timestamp' => now(),
            'severity' => 'critical'
        ]);

        $this->notifyArchitects($type, $violations);
        $this->enforceCorrectiveAction($type, $violations);
    }

    private function enforceCorrectiveAction(string $type, array $violations): void
    {
        foreach ($violations as $violation) {
            if ($violation->isCritical()) {
                $this->enforceEmergencyProtocol($violation);
            } else {
                $this->enforceStandardCorrection($violation);
            }
        }
    }

    private function enforceEmergencyProtocol(Violation $violation): void
    {
        $this->logger->logEmergencyProtocol($violation);
        $this->notifyEmergencyTeam($violation);
        throw new EmergencyProtocolException('Critical pattern violation detected');
    }

    private function handleDetectionFailure(\Exception $e): void
    {
        $this->logger->logFailure([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => now()
        ]);

        if ($e instanceof CriticalException) {
            $this->notifyEmergencyTeam($e);
        }
    }
}
