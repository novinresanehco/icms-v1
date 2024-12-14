<?php

namespace App\Core\Monitoring;

class DeviationMonitoringService implements DeviationMonitorInterface
{
    private PatternAnalyzer $patternAnalyzer;
    private ThresholdManager $thresholdManager;
    private ComplianceChecker $complianceChecker;
    private DeviationLogger $logger;
    private EmergencyProtocol $emergency;
    private AlertSystem $alerts;

    public function __construct(
        PatternAnalyzer $patternAnalyzer,
        ThresholdManager $thresholdManager,
        ComplianceChecker $complianceChecker,
        DeviationLogger $logger,
        EmergencyProtocol $emergency,
        AlertSystem $alerts
    ) {
        $this->patternAnalyzer = $patternAnalyzer;
        $this->thresholdManager = $thresholdManager;
        $this->complianceChecker = $complianceChecker;
        $this->logger = $logger;
        $this->emergency = $emergency;
        $this->alerts = $alerts;
    }

    public function monitorDeviations(MonitoringContext $context): MonitoringResult
    {
        $monitoringId = $this->initializeMonitoring($context);
        
        try {
            DB::beginTransaction();

            $patterns = $this->patternAnalyzer->analyzePatterns($context);
            $this->validatePatterns($patterns);

            $thresholds = $this->checkThresholds($context);
            $this->validateThresholds($thresholds);

            $compliance = $this->checkCompliance($context);
            $this->validateCompliance($compliance);

            $result = new MonitoringResult([
                'monitoringId' => $monitoringId,
                'patterns' => $patterns,
                'thresholds' => $thresholds,
                'compliance' => $compliance,
                'timestamp' => now()
            ]);

            $this->processResults($result);
            
            DB::commit();
            return $result;

        } catch (MonitoringException $e) {
            DB::rollBack();
            $this->handleMonitoringFailure($e, $monitoringId);
            throw new CriticalMonitoringException($e->getMessage(), $e);
        }
    }

    private function validatePatterns(array $patterns): void
    {
        foreach ($patterns as $pattern) {
            if ($pattern->isDeviant()) {
                $this->handleDeviation($pattern);
            }
        }
    }

    private function validateThresholds(array $thresholds): void
    {
        foreach ($thresholds as $threshold) {
            if ($threshold->isExceeded()) {
                $this->handleThresholdViolation($threshold);
            }
        }
    }

    private function handleDeviation(Pattern $pattern): void
    {
        $this->logger->logDeviation($pattern);
        $this->alerts->dispatchDeviation($pattern);

        if ($pattern->isCritical()) {
            $this->emergency->handleCriticalDeviation($pattern);
        }
    }

    private function processResults(MonitoringResult $result): void
    {
        if ($result->hasDeviations()) {
            foreach ($result->getDeviations() as $deviation) {
                $this->handleDeviation($deviation);
            }
        }

        if ($result->hasViolations()) {
            $this->emergency->handleViolations($result->getViolations());
        }
    }
}
