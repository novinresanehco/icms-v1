<?php namespace App\Core\Monitoring;

class PerformanceMonitor
{
    private ThresholdManager $thresholds;
    private AlertSystem $alerts;

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
}

class SecurityMonitor
{
    private IntrusionDetector $detector;
    private ThreatAnalyzer $analyzer;
    
    public function scan(): SecurityStatus
    {
        $threats = $this->detector->detectThreats();
        
        foreach ($threats as $threat) {
            $analysis = $this->analyzer->analyzeThreat($threat);
            if ($analysis->isCritical()) {
                $this->handleCriticalThreat($threat);
            }
        }
        
        return new SecurityStatus($threats);
    }
}

class LogManager
{
    public function criticalLog(string $message, array $context = []): void
    {
        Log::critical($message, array_merge([
            'timestamp' => now(),
            'trace' => debug_backtrace()
        ], $context));
    }
}
