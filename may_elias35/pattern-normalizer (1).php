<?php

namespace App\Core\Security\Patterns;

use App\Core\Security\SecurityManager;
use App\Core\Infrastructure\Monitoring\MetricsSystem;
use App\Core\Security\Validation\PatternValidator;

class PatternNormalizer
{
    private SecurityManager $security;
    private MetricsSystem $metrics;
    private PatternValidator $validator;
    private AuditLogger $auditLogger;
    private array $normalizationRules;

    public function normalize(array $patterns): NormalizedPatterns
    {
        DB::beginTransaction();
        
        try {
            // Validate input patterns
            $this->validatePatterns($patterns);
            
            // Pre-process patterns
            $preprocessed = $this->preProcessPatterns($patterns);
            
            // Apply normalization rules
            $normalized = $this->applyNormalizationRules($preprocessed);
            
            // Validate normalized patterns
            $this->validateNormalizedPatterns($normalized);
            
            $result = new NormalizedPatterns([
                'original' => $patterns,
                'normalized' => $normalized,
                'metadata' => $this->generateMetadata($patterns, $normalized),
                'timestamp' => now()
            ]);
            
            DB::commit();
            $this->auditLogger->logNormalization($result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleNormalizationFailure($e, $patterns);
            throw $e;
        }
    }

    private function validatePatterns(array $patterns): void
    {
        foreach ($patterns as $pattern) {
            if (!$this->validator->isValidPattern($pattern)) {
                throw new InvalidPatternException(
                    'Invalid pattern detected: ' . json_encode($pattern)
                );
            }
        }
    }

    private function preProcessPatterns(array $patterns): array
    {
        $preprocessed = [];
        
        foreach ($patterns as $pattern) {
            // Remove noise
            $cleaned = $this->removeNoise($pattern);
            
            // Standardize format
            $standardized = $this->standardizeFormat($cleaned);
            
            // Extract core components
            $core = $this->extractCoreComponents($standardized);
            
            $preprocessed[] = [
                'original' => $pattern,
                'cleaned' => $cleaned,
                'standardized' => $standardized,
                'core' => $core
            ];
        }
        
        return $preprocessed;
    }

    private function applyNormalizationRules(array $preprocessed): array
    {
        $normalized = [];
        
        foreach ($preprocessed as $pattern) {
            $normalized[] = $this->applyRules($pattern['core']);
        }
        
        return $this->consolidatePatterns($normalized);
    }

    private function applyRules(array $pattern): array
    {
        $normalized = $pattern;
        
        foreach ($this->normalizationRules as $rule) {
            if ($this->shouldApplyRule($rule, $normalized)) {
                $normalized = $this->applyNormalizationRule($rule, $normalized);
                
                // Verify rule application
                $this->verifyRuleApplication($rule, $pattern, $normalized);
            }
        }
        
        return $normalized;
    }

    private function shouldApplyRule(string $rule, array $pattern): bool
    {
        return match($rule) {
            'sequence_order' => $this->hasSequentialElements($pattern),
            'timing_normalize' => $this->hasTimingInformation($pattern),
            'attribute_standardize' => $this->hasAttributes($pattern),
            default => true
        };
    }

    private function verifyRuleApplication(string $rule, array $original, array $normalized): void
    {
        if (!$this->validator->isValidTransformation($rule, $original, $normalized)) {
            throw new NormalizationException(
                "Rule application verification failed for: $rule"
            );
        }
    }

    private function consolidatePatterns(array $patterns): array
    {
        // Group similar patterns
        $groups = $this->groupSimilarPatterns($patterns);
        
        // Merge compatible patterns
        $merged = $this->mergeCompatiblePatterns($groups);
        
        // Verify consolidation
        $this->verifyConsolidation($patterns, $merged);
        
        return $merged;
    }

    private function generateMetadata(array $original, array $normalized): array
    {
        return [
            'transformation_stats' => $this->calculateTransformationStats($original, $normalized),
            'complexity_metrics' => $this->calculateComplexityMetrics($normalized),
            'pattern_relationships' => $this->analyzePatternRelationships($normalized),
            'validation_results' => $this->validator->validateTransformation($original, $normalized)
        ];
    }

    private function handleNormalizationFailure(\Exception $e, array $patterns): void
    {
        // Log failure details
        $this->auditLogger->logNormalizationFailure($e, [
            'patterns' => $patterns,
            'stack_trace' => $e->getTraceAsString()
        ]);

        // Execute failsafe normalization
        try {
            $this->executeFailsafeNormalization($patterns);
        } catch (\Exception $failsafeException) {
            $this->auditLogger->logFailsafeFailure($failsafeException);
        }
    }
}
