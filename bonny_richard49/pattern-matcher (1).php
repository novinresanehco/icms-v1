<?php

namespace App\Core\Analysis;

class PatternMatcher implements PatternMatcherInterface
{
    private Parser $parser;
    private RuleMatcher $matcher;
    private ValidationService $validator;
    private array $criteria;

    public function matchPattern(string $code, Pattern $pattern): PatternMatchResult 
    {
        $operationId = uniqid('match_', true);

        try {
            $ast = $this->parser->parse($code);
            $this->validateAst($ast);
            
            $structureMatch = $this->matchStructure($ast, $pattern);
            $behaviorMatch = $this->matchBehavior($ast, $pattern);
            $implementationMatch = $this->matchImplementation($ast, $pattern);

            return $this->compileMatchResults(
                $structureMatch,
                $behaviorMatch,
                $implementationMatch,
                $operationId
            );

        } catch (\Throwable $e) {
            $this->handleMatchFailure($e, $pattern, $operationId);
            throw $e;
        }
    }

    protected function matchStructure($ast, Pattern $pattern): array
    {
        return [
            'class_structure' => $this->matcher->matchClassStructure($ast, $pattern),
            'method_structure' => $this->matcher->matchMethodStructure($ast, $pattern),
            'property_structure' => $this->matcher->matchPropertyStructure($ast, $pattern),
            'inheritance_structure' => $this->matcher->matchInheritanceStructure($ast, $pattern)
        ];
    }

    protected function matchBehavior($ast, Pattern $pattern): array 
    {
        return [
            'method_behavior' => $this->matcher->matchMethodBehavior($ast, $pattern),
            'interaction_patterns' => $this->matcher->matchInteractionPatterns($ast, $pattern),
            'state_management' => $this->matcher->matchStateManagement($ast, $pattern),
            'error_handling' => $this->matcher->matchErrorHandling($ast, $pattern)
        ];
    }

    protected function matchImplementation($ast, Pattern $pattern): array

    {
        return [
            'dependency_patterns' => $this->matcher->matchDependencyPatterns($ast, $pattern),
            'security_patterns' => $this->matcher->matchSecurityPatterns($ast, $pattern),
            'performance_patterns' => $this->matcher->matchPerformancePatterns($ast, $pattern),
            'quality_patterns' => $this->matcher->matchQualityPatterns($ast, $pattern)
        ];
    }

    protected function compileMatchResults(
        array $structure,
        array $behavior,
        array $implementation,
        string $operationId
    ): PatternMatchResult {
        return new PatternMatchResult([
            'structure' => $this->analyzeMatchResults($structure),
            'behavior' => $this->analyzeMatchResults($behavior),
            'implementation' => $this->analyzeMatchResults($implementation),
            'operation_id' => $operationId,
            'timestamp' => time(),
            'match_score' => $this->calculateMatchScore($structure, $behavior, $implementation)
        ]);
    }

    protected function analyzeMatchResults(array $results): array
    {
        $analysis = [];
        foreach ($results as $key => $result) {
            $analysis[$key] = [
                'matches' => $result['matches'] ?? [],
                'violations' => $result['violations'] ?? [],
                'score' => $this->calculateComponentScore($result)
            ];
        }
        return $analysis;
    }

    protected function calculateMatchScore(
        array $structure,
        array $behavior,
        array $implementation
    ): float {
        $weights = [
            'structure' => 0.4,
            'behavior' => 0.3,
            'implementation' => 0.3
        ];

        return 
            $weights['structure'] * $this->calculateCategoryScore($structure) +
            $weights['behavior'] * $this->calculateCategoryScore($behavior) +
            $weights['implementation'] * $this->calculateCategoryScore($implementation);
    }

    protected function calculateCategoryScore(array $category): float
    {
        $scores = array_map(function($component) {
            return $this->calculateComponentScore($component);
        }, $category);

        return !empty($scores) ? array_sum($scores) / count($scores) : 0.0;
    }

    protected function calculateComponentScore(array $component): float
    {
        $matches = count($component['matches'] ?? []);
        $violations = count($component['violations'] ?? []);
        $total = $matches + $violations;

        return $total > 0 ? $matches / $total : 1.0;
    }

    protected function validatePatternCriteria(Pattern $pattern): void
    {
        if (!$this->validator->validatePatternCriteria($pattern, $this->criteria)) {
            throw new PatternMatchException('Invalid pattern criteria');
        }
    }

    protected function handleMatchFailure(
        \Throwable $e,
        Pattern $pattern,
        string $operationId
    ): void {
        if ($this->isCriticalFailure($e)) {
            $this->handleCriticalFailure($e, $pattern, $operationId);
        } else {
            $this->handleNonCriticalFailure($e, $pattern, $operationId);
        }
    }

    protected function isCriticalFailure(\Throwable $e): bool
    {
        return $e instanceof CriticalPatternException ||
               $e instanceof SecurityViolationException;
    }

    protected function handleCriticalFailure(
        \Throwable $e,
        Pattern $pattern,
        string $operationId
    ): void {
        $this->validator->logCriticalViolation([
            'type' => 'critical_pattern_match_failure',
            'pattern' => $pattern->getName(),
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'stack_trace' => $e->getTraceAsString(),
            'severity' => 'CRITICAL'
        ]);
    }

    protected function handleNonCriticalFailure(
        \Throwable $e,
        Pattern $pattern,
        string $operationId
    ): void {
        $this->validator->logError([
            'type' => 'pattern_match_failure',
            'pattern' => $pattern->getName(),
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'severity' => 'ERROR'
        ]);
    }
}
