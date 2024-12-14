<?php

namespace App\Core\Security\Monitoring;

class AnomalyDetector implements AnomalyDetectorInterface
{
    private PatternAnalyzer $patternAnalyzer;
    private BehaviorMonitor $behaviorMonitor;
    private StatisticalAnalyzer $statisticalAnalyzer;
    private MachineLearning $mlEngine;
    private AnomalyLogger $anomalyLogger;
    private AlertSystem $alertSystem;

    public function __construct(
        PatternAnalyzer $patternAnalyzer,
        BehaviorMonitor $behaviorMonitor,
        StatisticalAnalyzer $statisticalAnalyzer,
        MachineLearning $mlEngine,
        AnomalyLogger $anomalyLogger,
        AlertSystem $alertSystem
    ) {
        $this->patternAnalyzer = $patternAnalyzer;
        $this->behaviorMonitor = $behaviorMonitor;
        $this->statisticalAnalyzer = $statisticalAnalyzer;
        $this->mlEngine = $mlEngine;
        $this->anomalyLogger = $anomalyLogger;
        $this->alertSystem = $alertSystem;
    }

    public function startDetection(MonitoringSession $session): void
    {
        try {
            $this->initializeDetectors($session);
            $this->startMonitors($session);
            $this->anomalyLogger->logDetectionStart($session);
        } catch (DetectionException $e) {
            $this->handleStartupFailure($session, $e);
            throw $e;
        }
    }

    public function detectAnomalies(SecurityContext $context): array
    {
        try {
            $patterns = $this->patternAnalyzer->analyze($context);
            $behaviors = $this->behaviorMonitor->analyze($context);
            $statistics = $this->statisticalAnalyzer->analyze($context);
            $mlPredictions = $this->mlEngine->predict($context);

            $anomalies = $this->correlateFindings([
                'patterns' => $patterns,
                'behaviors' => $behaviors,
                'statistics' => $statistics,
                'predictions' => $mlPredictions
            ]);

            $this->processAnomalies($anomalies, $context);

            return $anomalies;

        } catch (AnalysisException $e) {
            $this->handleAnalysisFailure($e, $context);
            throw $e;
        }
    }

    public function stopDetection(MonitoringSession $session): void
    {
        try {
            $this->stopMonitors($session);
            $this->collectMetrics($session);
            $this->anomalyLogger->logDetectionStop($session);
        } catch (DetectionException $e) {
            $this->handleShutdownFailure($session, $e);
            throw $e;
        }
    }

    private function initializeDetectors(MonitoringSession $session): void
    {
        $this->patternAnalyzer->initialize($session);
        $this->behaviorMonitor->initialize($session);
        $this->statisticalAnalyzer->initialize($session);
        $this->mlEngine->initialize($session);
    }

    private function startMonitors(MonitoringSession $session): void
    {
        $this->patternAnalyzer->startMonitoring($session);
        $this->behaviorMonitor->startMonitoring($session);
        $this->statisticalAnalyzer->startAnalysis($session);
        $this->mlEngine->startPrediction($session);
    }

    private function processAnomalies(array $anomalies, SecurityContext $context): void
    {
        foreach ($anomalies as $anomaly) {
            if ($anomaly->severity >= AnomalySeverity::HIGH) {
                $this->alertSystem->generateAlert($anomaly);
            }
            $this->anomalyLogger->logAnomaly($anomaly, $context);
        }
    }
}
