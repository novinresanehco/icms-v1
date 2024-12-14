<?php

namespace App\Core\Security\Analysis;

class PatternMatcher implements PatternMatcherInterface
{
    private PatternDatabase $patterns;
    private RuleEngine $rules;
    private AiEngine $aiEngine;
    private AuditLogger $logger;
    private array $config;

    public function matchPattern(string $code, array $options = []): PatternMatchResult
    {
        $operationId = uniqid('pattern_match_', true);

        try {
            // Load and validate patterns
            $this->loadPatterns($options);

            // Perform pattern matching
            $staticMatches = $this->performStaticMatching($code);
            $dynamicMatches = $this->performDynamicMatching($code);
            $aiMatches = $this->performAiMatching($code);

            // Analyze results
            $result = $this->analyzeMatches(
                $staticMatches,
                $dynamicMatches,
                $aiMatches,
                $operationId
            );

            // Log completion
            $this->logMatchCompletion($result, $operationId);

            return $result;

        } catch (\Throwable $e) {
            $this->handleMatchFailure($e, $operationId);
            throw $e;
        }
    }

    protected function loadPatterns(array $options): void
    {
        // Load pattern sets based on options
        $patternSets = $this->determinePatternSets($options);
        
        // Validate patterns
        foreach ($patternSets as $set) {
            if (!$this->validatePatternSet($set)) {
                throw new PatternException("Invalid pattern set: {$set}");
            }
        }

        // Load patterns into memory
        $this->patterns->loadPatternSets($patternSets);
    }

    protected function performStaticMatching(string $code): array
    {
        return $this->rules->matchPatterns($code, [
            'pattern_types' => ['static', 'structural', 'semantic'],
            'sensitivity' => $this->config['static_sensitivity'],
            'threshold' => $this->config['static_threshold']
        ]);
    }

    protected function performDynamicMatching(string $code): array
    {
        return $this->rules->matchDynamicPatterns($code, [
            'execution_context' => true,
            'flow_analysis' => true,
            'behavior_tracking' => true
        ]);
    }

    protected function performAiMatching(string $code): array
    {
        return $this->aiEngine->analyzePatterns($code, [
            'model' => $this->config['ai_model'],
            'confidence_threshold' => $this->config['ai_confidence_threshold'],
            'context_awareness' => true
        ]);
    }

    protected function analyzeMatches(
        array $staticMatches,
        array $dynamicMatches,
        array $aiMatches,
        string $operationId
    ): PatternMatchResult {
        // Combine all matches
        $allMatches = $this->combineMatches(
            $staticMatches,
            $dynamicMatches,
            $aiMatches
        );

        // Remove duplicates
        $uniqueMatches = $this->deduplicateMatches($allMatches);

        // Calculate confidence scores
        $confidenceScores = $this->calculateConfidenceScores(
            $uniqueMatches,
            $operationId
        );

        // Determine final verdict
        $verdict = $this->determineVerdict($uniqueMatches, $confidenceScores);

        return new PatternMatchResult(
            $uniqueMatches,
            $confidenceScores,
            $verdict,
            $operationId
        );
    }

    protected function combineMatches(
        array $staticMatches,
        array $dynamicMatches,
        array $aiMatches
    ): array {
        $combined = array_merge(
            $this->normalizeMatches($staticMatches, 'static'),
            $this->normalizeMatches($dynamicMatches, 'dynamic'),
            $this->normalizeMatches($aiMatches, 'ai')
        );

        return $this->prioritizeMatches($combined);
    }

    protected function normalizeMatches(array $matches, string $source): array
    {
        return array_map(function($match) use ($source) {
            return [
                'pattern' => $match['pattern'],
                'location' => $match['location'],
                'confidence' => $match['confidence'],
                'severity' => $match['severity'],
                'source' => $source
            ];
        }, $matches);
    }

    protected function deduplicateMatches(array $matches): array
    {
        $unique = [];
        $seen = [];

        foreach ($matches as $match) {
            $key = $this->generateMatchKey($match);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $match;
            }
        }

        return $unique;
    }

    protected function generateMatchKey(array $match): string
    {
        return md5(json_encode([
            $match['pattern'],
            $match['location'],
            $match['severity']
        ]));
    }

    protected function calculateConfidenceScores(
        array $matches,
        string $operationId
    ): array {
        $scores = [];

        foreach ($matches as $match) {
            $scores[$match['pattern']] = $this->calculateConfidence(
                $match,
                $operationId
            );
        }

        return $scores;
    }

    protected function calculateConfidence(array $match, string $operationId): float
    {
        $baseConfidence = $match['confidence'];

        // Adjust based on source reliability
        $sourceWeight = $this->config["weight_{$match['source']}"];
        
        // Adjust based on severity
        $severityWeight = $this->config["weight_{$match['severity']}"];

        return $baseConfidence * $sourceWeight * $severityWeight;
    }

    protected function determineVerdict(array $matches, array $scores): string
    {
        $averageConfidence = empty($scores) ? 0 : 
            array_sum($scores) / count($scores);

        if ($this->hasCriticalMatches($matches)) {
            return PatternMatchResult::VERDICT_CRITICAL;
        }

        if ($averageConfidence >= $this->config['high_confidence_threshold']) {
            return PatternMatchResult::VERDICT_HIGH_CONFIDENCE;
        }

        if ($averageConfidence >= $this->config['medium_confidence_threshold']) {
            return PatternMatchResult::VERDICT_MEDIUM_CONFIDENCE;
        }

        return PatternMatchResult::VERDICT_LOW_CONFIDENCE;
    }

    protected function hasCriticalMatches(array $matches): bool
    {
        foreach ($matches as $match) {
            if ($match['severity'] === 'CRITICAL') {
                return true;
            }
        }
        return false;
    }

    protected function handleMatchFailure(
        \Throwable $e,
        string $operationId
    ): void {
        $this->logger->error('Pattern matching failed', [
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        if ($this->isCriticalFailure($e)) {
            $this->handleCriticalFailure($e, $operationId);
        }
    }

    protected function isCriticalFailure(\Throwable $e): bool
    {
        return $e instanceof CriticalPatternException ||
               $e instanceof AiEngineException;
    }

    protected function handleCriticalFailure(
        \Throwable $e,
        string $operationId
    ): void {
        $this->logger->critical('Critical pattern matching failure', [
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->notifySecurityTeam([
            'type' => 'critical_pattern_match_failure',
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'severity' => 'CRITICAL'
        ]);
    }
}
