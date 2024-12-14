<?php

namespace App\Core\Security\Analysis;

use App\Core\Security\SecurityManager;
use App\Core\Infrastructure\Monitoring\MetricsSystem;
use App\Core\Security\Services\{
    PatternDetector,
    RiskCalculator,
    BehaviorAnalyzer
};

class ThreatAnalyzer
{
    private SecurityManager $security;
    private MetricsSystem $metrics;
    private PatternDetector $patternDetector;
    private RiskCalculator $riskCalculator;
    private BehaviorAnalyzer $behaviorAnalyzer;
    private AuditLogger $auditLogger;

    public function analyze(SecurityViolation $violation): ThreatAnalysis
    {
        DB::beginTransaction();
        
        try {
            // Initial threat detection
            $initialThreat = $this->detectInitialThreat($violation);
            
            // Deep pattern analysis
            $patterns = $this->analyzePatterns($violation);
            
            // Risk assessment
            $risk = $this->assessRisk($initialThreat, $patterns);
            
            // Behavior correlation
            $behavior = $this->analyzeBehavior($violation, $patterns);
            
            // Create comprehensive analysis
            $analysis = new ThreatAnalysis([
                'initial_threat' => $initialThreat,
                'patterns' => $patterns,
                'risk_assessment' => $risk,
                'behavior_analysis' => $behavior,
                'timestamp' => now()
            ]);
            
            DB::commit();
            
            $this->auditLogger->logThreatAnalysis($analysis);
            return $analysis;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAnalysisFailure($e, $violation);
            throw $e;
        }
    }

    private function detectInitialThreat(SecurityViolation $violation): InitialThreat
    {
        // Collect security context
        $context = $this->security->getCurrentContext();
        
        // Analyze violation characteristics
        $characteristics = $this->patternDetector->analyzeViolation($violation);
        
        // Check against known threat patterns
        $knownPatterns = $this->patternDetector->matchKnownPatterns($characteristics);
        
        return new InitialThreat([
            'context' => $context,
            'characteristics' => $characteristics,
            'known_patterns' => $knownPatterns,
            'severity' => $this->calculateInitialSeverity($characteristics, $knownPatterns)
        ]);
    }

    private function analyzePatterns(SecurityViolation $violation): ThreatPatterns
    {
        // Historical pattern analysis
        $historicalPatterns = $this->patternDetector->analyzeHistoricalPatterns([
            'violation' => $violation,
            'timeframe' => config('security.analysis.timeframe'),
            'depth' => config('security.analysis.depth')
        ]);

        // Real-time pattern detection
        $realtimePatterns = $this->patternDetector->detectRealtimePatterns([
            'violation' => $violation,
            'window' => config('security.analysis.window')
        ]);

        // Correlation analysis
        $correlations = $this->patternDetector->analyzeCorrelations(
            $historicalPatterns,
            $realtimePatterns
        );

        return new ThreatPatterns([
            'historical' => $historicalPatterns,
            'realtime' => $realtimePatterns,
            'correlations' => $correlations
        ]);
    }

    private function assessRisk(
        InitialThreat $initialThreat,
        ThreatPatterns $patterns
    ): RiskAssessment {
        // Calculate base risk score
        $baseScore = $this->riskCalculator->calculateBaseScore([
            'initial_threat' => $initialThreat,
            'patterns' => $patterns
        ]);

        // Analyze potential impact
        $impact = $this->riskCalculator->analyzeImpact([
            'threat' => $initialThreat,
            'patterns' => $patterns,
            'context' => $this->security->getCurrentContext()
        ]);

        // Calculate probability
        $probability = $this->riskCalculator->calculateProbability([
            'patterns' => $patterns,
            'historical_data' => $this->metrics->getHistoricalSecurityData()
        ]);

        return new RiskAssessment([
            'base_score' => $baseScore,
            'impact' => $impact,
            'probability' => $probability,
            'final_score' => $this->riskCalculator->calculateFinalScore(
                $baseScore,
                $impact,
                $probability
            )
        ]);
    }

    private function analyzeBehavior(
        SecurityViolation $violation,
        ThreatPatterns $patterns
    ): BehaviorAnalysis {
        // Analyze user behavior
        $userBehavior = $this->behaviorAnalyzer->analyzeUserBehavior([
            'violation' => $violation,
            'patterns' => $patterns,
            'historical_data' => $this->metrics->getHistoricalBehaviorData()
        ]);

        // Analyze system behavior
        $systemBehavior = $this->behaviorAnalyzer->analyzeSystemBehavior([
            'violation' => $violation,
            'patterns' => $patterns,
            'metrics' => $this->metrics->getSystemBehaviorMetrics()
        ]);

        // Detect anomalies
        $anomalies = $this->behaviorAnalyzer->detectAnomalies(
            $userBehavior,
            $systemBehavior
        );

        return new BehaviorAnalysis([
            'user_behavior' => $userBehavior,
            'system_behavior' => $systemBehavior,
            'anomalies' => $anomalies,
            'risk_indicators' => $this->behaviorAnalyzer->calculateRiskIndicators(
                $userBehavior,
                $systemBehavior,
                $anomalies
            )
        ]);
    }

    private function handleAnalysisFailure(\Exception $e, SecurityViolation $violation): void
    {
        // Log failure
        $this->auditLogger->logAnalysisFailure($e, [
            'violation' => $violation,
            'context' => $this->security->getCurrentContext(),
            'stack_trace' => $e->getTraceAsString()
        ]);

        // Execute failsafe analysis if possible
        try {
            $this->executeFailsafeAnalysis($violation);
        } catch (\Exception $failsafeException) {
            $this->auditLogger->logFailsafeFailure($failsafeException);
        }
    }
}
