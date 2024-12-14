<?php

namespace App\Core\Analysis;

class PatternAnalysisSystem
{
    private PatternMatcher $matcher;
    private DeviationDetector $detector;
    private ComplianceVerifier $verifier;

    public function analyzePatterns(): void
    {
        DB::transaction(function() {
            $this->validatePatternIntegrity();
            $this->detectDeviations();
            $this->enforceCompliance();
        });
    }

    private function validatePatternIntegrity(): void
    {
        $patterns = $this->matcher->getCurrentPatterns();
        foreach ($patterns as $pattern) {
            if (!$this->verifier->verifyIntegrity($pattern)) {
                throw new IntegrityException("Pattern integrity violation");
            }
        }
    }

    private function detectDeviations(): void
    {
        $deviations = $this->detector->analyzeCurrentState();
        if ($deviations->hasViolations()) {
            throw new DeviationException("Pattern deviations detected");
        }
    }

    private function enforceCompliance(): void
    {
        $this->verifier->enforcePatternCompliance();
        $this->matcher->validatePatternState();
    }
}
