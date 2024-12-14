<?php

namespace App\Core\Monitoring;

class SystemMonitor
{
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private Logger $logger;

    public function monitor(): SystemStatus
    {
        $metrics = $this->metrics->collect();
        
        if ($metrics->hasWarnings()) {
            $this->handleWarnings($metrics);
        }
        
        if ($metrics->hasCritical()) {
            $this->handleCritical($metrics);
        }
        
        $this->logger->logMetrics($metrics);
        
        return new SystemStatus($metrics);
    }

    private function handleWarnings(Metrics $metrics): void
    {
        $this->alerts->sendWarning($metrics->getWarnings());
    }

    private function handleCritical(Metrics $metrics): void
    {
        $this->alerts->sendCritical($metrics->getCritical());
        $this->executeEmergencyProcedures($metrics);
    }
}

class PerformanceMonitor
{
    private ThresholdManager $thresholds;
    private AlertSystem $alerts;
    private Logger $logger;

    public function checkPerformance(): PerformanceStatus
    {
        $metrics = $this->gatherMetrics();
        
        foreach ($this->thresholds->getAll() as $threshold) {
            if ($metrics->exceeds($threshold)) {
                $this->handleThresholdViolation($threshold, $metrics);
            }
        }
        
        return new PerformanceStatus($metrics);
    }

    private function gatherMetrics(): Metrics
    {
        return new Metrics([
            'memory' => memory_get_usage(true),
            'cpu' => sys_getloadavg(),
            'connections' => $this->getConnectionCount(),
            'response_time' => $this->getAverageResponseTime()
        ]);
    }
}

class SecurityMonitor 
{
    private IntrusionDetector $detector;
    private ThreatAnalyzer $analyzer;
    private AlertManager $alerts;

    public function scan(): SecurityStatus
    {
        $threats = $this->detector->detectThreats();
        
        foreach ($threats as $threat) {
            $analysis = $this->analyzer->analyzeThreat($threat);
            
            if ($analysis->isCritical()) {
                $this->handleCriticalThreat($threat, $analysis);
            }
        }
        
        return new SecurityStatus($threats);
    }

    private function handleCriticalThreat(Threat $threat, Analysis $analysis): void
    {
        $this->alerts->sendCriticalThreatAlert($threat);
        $this->executeSecurityProcedures($analysis);
    }
}
