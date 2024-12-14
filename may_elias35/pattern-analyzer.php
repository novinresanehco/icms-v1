<?php

namespace App\Core\Monitoring;

class PatternAnalyzer
{
    private array $architecturalPatterns;
    private array $validationRules;
    private MetricsCollector $metrics;
    
    public function __construct(
        array $architecturalPatterns,
        array $validationRules,
        MetricsCollector $metrics
    ) {
        $this->architecturalPatterns = $architecturalPatterns;
        $this->validationRules = $validationRules;
        $this->metrics = $metrics;
    }

    public function analyzePattern(string $pattern): float
    {
        $scores = [];

        foreach ($this->architecturalPatterns as $reference => $rules) {
            $scores[] = $this->calculatePatternMatch($pattern, $rules);
        }

        $finalScore = max($scores);
        $this->metrics->recordPatternScore($pattern, $finalScore);

        return $finalScore;
    }

    public function validateResultPattern($result): float
    {
        $pattern = $this->extractPattern($result);
        return $this->analyzePattern($pattern);
    }

    public function getComplianceMetrics(): array
    {
        return [
            'pattern_scores' => $this->metrics->getPatternScores(),
            'compliance_rate' => $this->calculateComplianceRate(),
            'violation_count' => $this->metrics->getViolationCount(),
            'pattern_distribution' => $this->getPatternDistribution()
        ];
    }

    public function getComplianceScore(): float
    {
        return $this->metrics->getAveragePatternScore();
    }

    public function getCurrentPatterns(): array
    {
        return $this->metrics->getActivePatterns();
    }

    private function calculatePatternMatch(string $pattern, array $rules): float
    {
        $matches = 0;
        $total = count($rules);

        foreach ($rules as $rule => $constraint) {
            if ($this->validateRule($pattern, $rule, $constraint)) {
                $matches++;
            }
        }

        return $matches / $total;
    }

    private function validateRule(string $pattern, string $rule, $constraint): bool
    {
        return match($rule) {
            'structure' => $this->validateStructure($pattern, $constraint),
            'complexity' => $this->validateComplexity($pattern, $constraint),
            'coupling' => $this->validateCoupling($pattern, $constraint),
            'cohesion' => $this->validateCohesion($pattern, $constraint),
            default => false
        };
    }

    private function extractPattern($result): string
    {
        if (is_object($result)) {
            return get_class($result);
        }

        return gettype($result);
    }

    private function calculateComplianceRate(): float
    {
        $scores = $this->metrics->getPatternScores();
        if (empty($scores)) {
            return 1.0;
        }

        return array_sum($scores) / count($scores);
    }

    private function getPatternDistribution(): array
    {
        $patterns = $this->metrics->getActivePatterns();
        $distribution = [];

        foreach ($patterns as $pattern) {
            $type = $this->classifyPattern($pattern);
            $distribution[$type] = ($distribution[$type] ?? 0) + 1;
        }

        return $distribution;
    }

    private function classifyPattern(string $pattern): string
    {
        foreach ($this->validationRules as $type => $rules) {
            if ($this->matchesRules($pattern, $rules)) {
                return $type;
            }
        }

        return 'unknown';
    }

    private function matchesRules(string $pattern, array $rules): bool
    {
        foreach ($rules as $rule) {
            if (!preg_match($rule, $pattern)) {
                return false;
            }
        }

        return true;
    }

    private function validateStructure(string $pattern, array $constraint): bool
    {
        return preg_match($constraint['pattern'], $pattern) &&
               $this->validateDependencies($pattern, $constraint['dependencies']);
    }

    private function validateComplexity(string $pattern, int $maxComplexity): bool
    {
        $complexity = $this->calculateComplexity($pattern);
        return $complexity <= $maxComplexity;
    }

    private function validateCoupling(string $pattern, array $constraint): bool
    {
        $coupling = $this->analyzeCoupling($pattern);
        return $coupling <= $constraint['max_coupling'];
    }

    private function validateCohesion(string $pattern, float $minCohesion): bool
    {
        $cohesion = $this->analyzeCohesion($pattern);
        return $cohesion >= $minCohesion;
    }

    private function calculateComplexity(string $pattern): int
    {
        // Implement cyclomatic complexity calculation
        return 1;
    }

    private function analyzeCoupling(string $pattern): int
    {
        // Implement coupling analysis
        return 0;
    }

    private function analyzeCohesion(string $pattern): float
    {
        // Implement cohesion analysis
        return 1.0;
    }

    private function validateDependencies(string $pattern, array $allowedDeps): bool
    {
        // Implement dependency validation
        return true;
    }
}
