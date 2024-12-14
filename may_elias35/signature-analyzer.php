<?php

namespace App\Core\Security\Analysis;

use App\Core\Security\SecurityManager;
use App\Core\Infrastructure\Monitoring\MetricsSystem;
use App\Core\Security\Patterns\{
    SignatureRepository,
    SignatureMatcher,
    PatternNormalizer
};

class SignatureAnalyzer
{
    private SecurityManager $security;
    private MetricsSystem $metrics;
    private SignatureRepository $signatureRepo;
    private SignatureMatcher $matcher;
    private PatternNormalizer $normalizer;
    private AuditLogger $auditLogger;

    public function analyzeCurrentActivity(array $context): SignatureAnalysisResult
    {
        DB::beginTransaction();
        
        try {
            // Generate current signature
            $signature = $this->generateSignature($context);
            
            // Match against known patterns
            $matches = $this->findSignatureMatches($signature);
            
            // Analyze signature characteristics
            $characteristics = $this->analyzeCharacteristics($signature);
            
            // Calculate threat indicators
            $indicators = $this->calculateThreatIndicators($signature, $matches);
            
            $result = new SignatureAnalysisResult([
                'signature' => $signature,
                'matches' => $matches,
                'characteristics' => $characteristics,
                'threat_indicators' => $indicators,
                'timestamp' => now()
            ]);
            
            DB::commit();
            $this->auditLogger->logSignatureAnalysis($result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAnalysisFailure($e, $context);
            throw $e;
        }
    }

    private function generateSignature(array $context): SecuritySignature
    {
        // Extract behavioral patterns
        $behavior = $this->extractBehavioralPatterns($context);
        
        // Normalize patterns
        $normalized = $this->normalizer->normalize($behavior);
        
        // Generate signature hash
        $hash = $this->generateSignatureHash($normalized);
        
        return new SecuritySignature([
            'patterns' => $normalized,
            'hash' => $hash,
            'context' => $context,
            'timestamp' => now()
        ]);
    }

    private function findSignatureMatches(SecuritySignature $signature): SignatureMatches
    {
        // Get known signatures
        $knownSignatures = $this->signatureRepo->getKnownSignatures();
        
        // Find exact matches
        $exactMatches = $this->matcher->findExactMatches(
            $signature,
            $knownSignatures
        );
        
        // Find partial matches
        $partialMatches = $this->matcher->findPartialMatches(
            $signature,
            $knownSignatures,
            config('security.signature.matching_threshold')
        );
        
        return new SignatureMatches([
            'exact' => $exactMatches,
            'partial' => $partialMatches,
            'similarity_scores' => $this->calculateSimilarityScores($signature, $partialMatches)
        ]);
    }

    private function analyzeCharacteristics(SecuritySignature $signature): SignatureCharacteristics
    {
        // Analyze pattern complexity
        $complexity = $this->analyzeComplexity($signature);
        
        // Identify unique features
        $features = $this->identifyUniqueFeatures($signature);
        
        // Calculate risk metrics
        $riskMetrics = $this->calculateRiskMetrics($signature, $features);
        
        return new SignatureCharacteristics([
            'complexity' => $complexity,
            'features' => $features,
            'risk_metrics' => $riskMetrics
        ]);
    }

    private function calculateThreatIndicators(
        SecuritySignature $signature,
        SignatureMatches $matches
    ): ThreatIndicators {
        // Calculate base threat score
        $baseScore = $this->calculateBaseThreatScore($signature);
        
        // Adjust based on matches
        $adjustedScore = $this->adjustThreatScore($baseScore, $matches);
        
        // Calculate confidence level
        $confidence = $this->calculateConfidenceLevel($signature, $matches);
        
        return new ThreatIndicators([
            'base_score' => $baseScore,
            'adjusted_score' => $adjustedScore,
            'confidence' => $confidence,
            'risk_level' => $this->determineThreatLevel($adjustedScore, $confidence)
        ]);
    }

    private function handleAnalysisFailure(\Exception $e, array $context): void
    {
        // Log failure with full context
        $this->auditLogger->logAnalysisFailure($e, [
            'context' => $context,
            'system_state' => $this->metrics->getCurrentSystemState(),
            'stack_trace' => $e->getTraceAsString()
        ]);

        // Execute failsafe signature analysis
        try {
            $this->executeFailsafeAnalysis($context);
        } catch (\Exception $failsafeException) {
            $this->auditLogger->logFailsafeFailure($failsafeException);
        }

        // Update threat indicators
        $this->security->updateThreatIndicators([
            'analysis_failure' => true,
            'context' => $context,
            'timestamp' => now()
        ]);
    }
}
