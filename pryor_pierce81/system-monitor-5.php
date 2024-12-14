<?php

namespace App\Core\Monitoring;

class CriticalSystemMonitor implements SystemMonitorInterface
{
    private MetricsCollector $metricsCollector;
    private StateAnalyzer $stateAnalyzer;
    private ComplianceVerifier $complianceVerifier;
    private MonitoringEngine $monitoringEngine;
    private MonitorLogger $logger;
    private EmergencyProtocol $emergency;

    public function __construct(
        MetricsCollector $metricsCollector,
        StateAnalyzer $stateAnalyzer,
        ComplianceVerifier $complianceVerifier,
        MonitoringEngine $monitoringEngine,
        MonitorLogger $logger,
        EmergencyProtocol $emergency
    ) {
        $this->metricsCollector = $metricsCollector;
        $this->stateAnalyzer = $stateAnalyzer;
        $this->complianceVerifier = $complianceVerifier;
        $this->monitoringEngine = $monitoringEngine;
        $this->logger = $logger;
        $this->emergency = $emergency;
    }

    public function monitorSystem(): MonitoringResult
    {
        $monitoringId = $this->initializeMonitoring();
        
        try {
            DB::beginTransaction();

            // Continuous monitoring cycle
            $metrics = $this->metricsCollector->collectCriticalMetrics();
            $state = $this->stateAnalyzer->analyzeSystemState($metrics);
            $compliance = $this->complianceVerifier->verifyCriticalCompliance($state);

            $this->validateSystemState($state);
            $this->enforceCompliance($compliance);
            $this->detectAnomalies($metrics);

            $result = new MonitoringResult([
                'monitoringId' => $monitoringId,
                'metrics' => $metrics,
                'state' => $state,
                'compliance' => $compliance,
                'timestamp' => now()
            ]);

            DB::commit();
            return $result;

        } catch (MonitoringException $e) {
            DB::rollBack();
            $this->handleMonitoringFailure($e, $monitoringId);
            throw new CriticalMonitoringException($e->getMessage(), $e);
        }
    }

    private function validateSystemState(SystemState $state): void
    {
        if (!$this->monitoringEngine->validateState($state)) {
            $this->emergency->handleInvalidSystemState($state);
            throw new InvalidStateException('Critical system state validation failed');
        }
    }

    private function enforceCompliance(Compliance $compliance): void
    {
        if (!$compliance->isFullyCompliant()) {
            $this->emergency->handleNonCompliance($compliance);
            throw new ComplianceViolationException('Critical compliance violation detected');
        }
    }

    private function detectAnomalies(array $metrics): void
    {
        $anomalies = $this->monitoringEngine->detectAnomalies($metrics);
        
        if (!empty($anomalies)) {
            foreach ($anomalies as $anomaly) {
                if ($anomaly->isCritical()) {
                    $this->emergency->handleCriticalAnomaly($anomaly);
                }
            }
            throw new AnomalyDetectedException('Critical system anomalies detected');
        }
    }

    private function handleMonitoringFailure(
        MonitoringException $e, 
        string $monitoringId
    ): void {
        $this->logger->logFailure($e, $monitoringId);
        
        if ($e->isCritical()) {
            $this->emergency->escalateToHighestLevel();
        }
    }
}
