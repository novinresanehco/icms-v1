<?php

namespace App\Core\Monitoring;

class DeviationDetectionSystem
{
    private PatternMonitor $monitor;
    private AlertSystem $alerts;
    private ValidationEngine $validator;

    public function monitorDeviations(): void
    {
        DB::transaction(function() {
            $this->validateSystemState();
            $this->detectRealTimeDeviations();
            $this->enforceCompliance();
            $this->updateMonitoringState();
        });
    }

    private function detectRealTimeDeviations(): void
    {
        $patterns = $this->monitor->getCurrentPatterns();
        foreach ($patterns as $pattern) {
            if ($this->isDeviation($pattern)) {
                $this->handleDeviation($pattern);
            }
        }
    }

    private function isDeviation(Pattern $pattern): bool
    {
        return !$this->validator->validatePattern($pattern);
    }

    private function handleDeviation(Pattern $pattern): void
    {
        $this->alerts->triggerDeviation($pattern);
        $this->enforceCorrection($pattern);
    }
}
