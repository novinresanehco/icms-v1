<?php

namespace App\Core\Analysis;

class PatternAnalyzer implements PatternAnalyzerInterface 
{
    private PatternRegistry $registry;
    private PatternMatcher $matcher;
    private ValidationService $validator;
    private AuditLogger $logger;

    public function analyzePattern(string $code, string $patternType): PatternAnalysisResult
    {
        $operationId = uniqid('pattern_', true);

        try {
            $this->validatePatternType($patternType);
            $pattern = $this->registry->getPattern($patternType);
            
            $matchResult = $this->matcher->matchPattern($code, $pattern);
            $violations = $this->detectViolations($matchResult, $pattern);
            $metrics = $this->calculatePatternMetrics($matchResult);
            
            $result = $this->compilePatternResults($violations, $metrics, $operationId);

            $this->logAnalysisSuccess($result, $operationId);
            return $result;

        } catch (\Throwable $e) {
            $this->handleAnalysisFailure($e, $patternType, $operationId);
            throw $e;
        }
    }

    protected function validatePatternType(string $patternType): void
    {
        if (!$this->validator->validatePatternType($patternType)) {
            throw new PatternAnalysisException('Invalid pattern type');
        }
    }

    protected function detectViolations(PatternMatchResult $match, Pattern $pattern): array
    {
        $violations = [];

        // Check structural violations
        $structuralViolations = $this->matcher->checkStructure($match, $pattern);
        $violations = array_merge($violations, $structuralViolations);

        // Check behavioral violations
        $behavioralViolations = $this->matcher->checkBehavior($match, $pattern);
        $violations = array_merge($violations, $behavioralViolations);

        // Check implementation violations
        $implementationViolations = $this->matcher->checkImplementation($match, $pattern);
        $violations = array_merge($violations, $implementationViolations);

        return $violations;
    }

    protected function calculatePatternMetrics(PatternMatchResult $match): array
    {
        return [
            'pattern_coverage' => $this->matcher->calculateCoverage($match),
            'pattern_conformity' => $this->matcher->calculateConformity($match),
            'pattern_complexity' => $this->matcher->calculateComplexity($match),
            'pattern_cohesion' => $this->matcher->calculateCohesion($match)
        ];
    }

    protected function compilePatternResults(
        array $violations,
        array $metrics,
        string $operationId
    ): PatternAnalysisResult {
        return new PatternAnalysisResult([
            'violations' => $violations,
            'metrics' => $metrics,
            'operation_id' => $operationId,
            'timestamp' => time(),
            'status' => $this->determineAnalysisStatus($violations)
        ]);
    }

    protected function determineAnalysisStatus(array $violations): string
    {
        if ($this->hasCriticalViolations($violations)) {
            return PatternAnalysisResult::STATUS_CRITICAL;
        }

        if ($this->hasErrorViolations($violations)) {
            return PatternAnalysisResult::STATUS_FAILED;
        }

        if ($this->hasWarningViolations($violations)) {
            return PatternAnalysisResult::STATUS_WARNING;
        }

        return PatternAnalysisResult::STATUS_PASSED;
    }

    protected function hasCriticalViolations(array $violations): bool
    {
        return !empty(array_filter($violations, fn($v) => 
            $v['severity'] === 'CRITICAL'
        ));
    }

    protected function hasErrorViolations(array $violations): bool
    {
        return !empty(array_filter($violations, fn($v) => 
            $v['severity'] === 'ERROR'
        ));
    }

    protected function hasWarningViolations(array $violations): bool
    {
        return !empty(array_filter($violations, fn($v) => 
            $v['severity'] === 'WARNING'
        ));
    }

    protected function logAnalysisSuccess(PatternAnalysisResult $result, string $operationId): void
    {
        $this->logger->logSuccess([
            'type' => 'pattern_analysis',
            'operation_id' => $operationId,
            'result' => $result->toArray(),
            'timestamp' => time()
        ]);
    }

    protected function handleAnalysisFailure(
        \Throwable $e,
        string $patternType,
        string $operationId
    ): void {
        $this->logger->logFailure([
            'type' => 'pattern_analysis_failure',
            'pattern_type' => $patternType,
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'severity' => 'ERROR'
        ]);

        if ($this->isCriticalFailure($e)) {
            $this->escalateFailure($e, $patternType, $operationId);
        }
    }

    protected function isCriticalFailure(\Throwable $e): bool
    {
        return $e instanceof CriticalPatternException ||
               $e instanceof SecurityPatternException;
    }

    protected function escalateFailure(
        \Throwable $e,
        string $patternType, 
        string $operationId
    ): void {
        $this->logger->logCritical([
            'type' => 'critical_pattern_failure',
            'pattern_type' => $patternType,
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'stack_trace' => $e->getTraceAsString(),
            'severity' => 'CRITICAL'
        ]);
    }
}
