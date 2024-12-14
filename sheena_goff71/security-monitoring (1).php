<?php

namespace App\Core\Security;

class SecurityMonitoringSystem
{
    private EventCollector $collector;
    private ThreatAnalyzer $analyzer;
    private ResponseCoordinator $coordinator;

    public function monitorSecurityEvents(): void
    {
        DB::transaction(function() {
            $events = $this->collector->gatherSecurityEvents();
            $threats = $this->analyzer->analyzeThreatPatterns($events);
            $this->handleThreats($threats);
        });
    }

    private function handleThreats(array $threats): void
    {
        foreach ($threats as $threat) {
            if ($threat->isCritical()) {
                $this->coordinator->triggerCriticalResponse($threat);
            }
            $this->coordinator->implementCountermeasures($threat);
        }
    }
}

class ThreatAnalyzer
{
    public function analyzeThreatPatterns(array $events): array
    {
        return array_map(
            fn($event) => $this->analyzeEvent($event),
            $events
        );
    }

    private function analyzeEvent(SecurityEvent $event): ThreatAssessment
    {
        return new ThreatAssessment(
            $event,
            $this->calculateThreatLevel($event),
            $this->determineImpact($event)
        );
    }
}
