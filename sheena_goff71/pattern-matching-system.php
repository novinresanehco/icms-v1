<?php

namespace App\Core\Validation;

class ArchitecturePatternMatcher implements PatternMatcherInterface
{
    private PatternRepository $patterns;
    private ValidationEngine $validator;
    private MetricsCollector $metrics;
    private AuditLogger $auditLogger;

    public function validatePattern(Operation $operation): ValidationResult
    {
        $validationId = $this->auditLogger->startValidation();

        try {
            // Load master architecture pattern
            $masterPattern = $this->patterns->getMasterPattern();

            // Start pattern analysis
            $analysisStart = microtime(true);
            $deviations = $this->findDeviations($operation, $masterPattern);
            $analysisTime = microtime(true) - $analysisStart;

            // Log metrics
            $this->metrics->recordAnalysis([
                'duration' => $analysisTime,
                'patterns_checked' => count($masterPattern->getNodes()),
                'deviations_found' => count($deviations)
            ]);

            // Validate result
            if (!empty($deviations)) {
                throw new PatternDeviationException($deviations);
            }

            $this->auditLogger->recordSuccess($validationId);
            return new ValidationResult(true);

        } catch (ValidationException $e) {
            $this->handleValidationFailure($validationId, $operation, $e);
            throw $e;
        }
    }

    private function findDeviations(Operation $operation, Pattern $masterPattern): array
    {
        $deviations = [];

        foreach ($masterPattern->getNodes() as $node) {
            $validationResult = $this->validator->validateNode(
                $operation,
                $node,
                [
                    'strict_mode' => true,
                    'pattern_matching' => 'exact',
                    'deviation_tolerance' => 0
                ]
            );

            if (!$validationResult->isValid()) {
                $deviations[] = new PatternDeviation(
                    $node,
                    $validationResult->getViolations()
                );
            }
        }

        return $deviations;
    }

    private function handleValidationFailure(
        string $validationId,
        Operation $operation,
        ValidationException $e
    ): void {
        $this->auditLogger->recordFailure($validationId, [
            'operation' => $operation->getIdentifier(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'system_state' => $this->captureSystemState()
        ]);
    }

    private function captureSystemState(): array
    {
        return [
            'memory' => [
                'usage' => memory_get_usage(true),
                'peak' => memory_get_peak_usage(true)
            ],
            'metrics' => [
                'validation_time' => $this->metrics->getAverageValidationTime(),
                'pattern_matches' => $this->metrics->getPatternMatchCount(),
                'deviation_rate' => $this->metrics->getDeviationRate()
            ]
        ];
    }
}

class ValidationEngine
{
    private ComplianceChecker $complianceChecker;
    private RuleEngine $ruleEngine;

    public function validateNode(
        Operation $operation,
        PatternNode $node,
        array $options
    ): NodeValidationResult {
        // Check node compliance
        $complianceResult = $this->complianceChecker->checkCompliance(
            $operation,
            $node,
            $options
        );

        if (!$complianceResult->isCompliant()) {
            return new NodeValidationResult(false, $complianceResult->getViolations());
        }

        // Validate rules
        $ruleResult = $this->ruleEngine->validateRules(
            $operation,
            $node->getRules(),
            $options
        );

        if (!$ruleResult->isValid()) {
            return new NodeValidationResult(false, $ruleResult->getViolations());
        }

        return new NodeValidationResult(true);
    }
}

class MetricsCollector
{
    private array $metrics = [];

    public function recordAnalysis(array $data): void
    {
        $this->metrics['analysis'] = array_merge(
            $this->metrics['analysis'] ?? [],
            $data
        );
    }

    public function getAverageValidationTime(): float
    {
        return collect($this->metrics['analysis'] ?? [])
            ->avg('duration');
    }

    public function getPatternMatchCount(): int
    {
        return collect($this->metrics['analysis'] ?? [])
            ->sum('patterns_checked');
    }

    public function getDeviationRate(): float
    {
        $analyses = collect($this->metrics['analysis'] ?? []);
        
        if ($analyses->isEmpty()) {
            return 0.0;
        }

        return $analyses->sum('deviations_found') / $analyses->count();
    }
}
