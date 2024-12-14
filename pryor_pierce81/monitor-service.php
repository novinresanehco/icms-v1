<?php

namespace App\Core\Monitor;

class SystemMonitor implements MonitorInterface 
{
    private StateTracker $stateTracker;
    private MetricsCollector $metricsCollector;
    private ThresholdManager $thresholdManager;
    private PatternAnalyzer $patternAnalyzer;
    private AlertService $alertService;
    private MonitorLogger $logger;

    public function __construct(
        StateTracker $stateTracker,
        MetricsCollector $metricsCollector,
        ThresholdManager $thresholdManager,
        PatternAnalyzer $patternAnalyzer,
        AlertService $alertService,
        MonitorLogger $logger
    ) {
        $this->stateTracker = $stateTracker;
        $this->metricsCollector = $metricsCollector;
        $this->thresholdManager = $thresholdManager;
        $this->patternAnalyzer = $patternAnalyzer;
        $this->alertService = $alertService;
        $this->logger = $logger;
    }

    public function monitorSystem(MonitoringContext $context): MonitoringResult 
    {
        $sessionId = $this->initializeMonitoring($context);
        
        try {
            DB::beginTransaction();
            
            $state = $this->stateTracker->captureState();
            $metrics = $this->metricsCollector->collectMetrics($state);
            
            $this->validateMetrics($metrics);
            $this->checkThresholds($metrics);
            
            $patterns = $this->patternAnalyzer->analyzePatterns($metrics);
            $this->processPatterns($patterns);
            
            $result = new MonitoringResult([
                'sessionId' => $sessionId,
                'state' => $state,
                'metrics' => $metrics,
                'patterns' => $patterns,
                'timestamp' => now()
            ]);
            
            $this->logger->logMonitoringResult($result);
            
            DB::commit();
            return $result;

        } catch (MonitoringException $e) {
            DB::rollBack();
            $this->handleMonitoringFailure($e, $sessionId);
            throw new CriticalMonitoringException($e->getMessage(), $e);
        }
    }

    private function validateMetrics(array $metrics): void 
    {
        foreach ($metrics as $metric) {
            if (!$metric->isValid()) {
                throw new MetricValidationException('Invalid metric detected');
            }
        }
    }

    private function checkThresholds(array $metrics): void 
    {
        foreach ($metrics as $metric) {
            if ($this->thresholdManager->isThresholdExceeded($metric)) {
                $this->alertService->processAlert(
                    new ThresholdAlert($metric)
                );
            }
        }
    }

    private function processPatterns(array $patterns): void 
    {
        foreach ($patterns as $pattern) {
            if ($pattern->severity >= PatternSeverity::HIGH) {
                $this->alertService->processAlert(
                    new PatternAlert($pattern)
                );
            }
        }
    }

    private function handleMonitoringFailure(
        MonitoringException $e, 
        string $sessionId
    ): void {
        $this->logger->logFailure($e, $sessionId);
        
        $this->alertService->processAlert(
            new MonitoringAlert([
                'exception' => $e,
                'sessionId' => $sessionId,
                'severity' => AlertSeverity::CRITICAL
            ])
        );
    }
}
