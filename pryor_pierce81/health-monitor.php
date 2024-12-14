<?php

namespace App\Core\Health;

class HealthMonitoringService implements HealthMonitorInterface
{
    private SystemAnalyzer $analyzer;
    private MetricsCollector $metrics;
    private ThresholdManager $thresholds;
    private DiagnosticEngine $diagnostics;
    private HealthLogger $logger;
    private EmergencyProtocol $emergency;

    public function __construct(
        SystemAnalyzer $analyzer,
        MetricsCollector $metrics,
        ThresholdManager $thresholds,
        DiagnosticEngine $diagnostics,
        HealthLogger $logger,
        EmergencyProtocol $emergency
    ) {
        $this->analyzer = $analyzer;
        $this->metrics = $metrics;
        $this->thresholds = $thresholds;
        $this->diagnostics = $diagnostics;
        $this->logger = $logger;
        $this->emergency = $emergency;
    }

    public function monitorSystemHealth(): HealthStatus
    {
        $monitoringId = $this->initializeMonitoring();
        
        try {
            DB::beginTransaction();

            $metrics = $this->collectSystemMetrics();
            $this->validateMetrics($metrics);

            $analysis = $this->analyzer->analyzeHealth($metrics);
            $this->validateAnalysis($analysis);

            $diagnosticResults = $this->runDiagnostics($analysis);
            $this->validateDiagnostics($diagnosticResults);

            $status = new HealthStatus([
                'monitoringId' => $monitoringId,
                'metrics' => $metrics,
                'analysis' => $analysis,
                'diagnostics' => $diagnosticResults,
                'timestamp' => now()
            ]);

            DB::commit();
            return $status;

        } catch (HealthMonitoringException $e) {
            DB::rollBack();
            $this->handleMonitoringFailure($e, $monitoringId);
            throw new CriticalHealthException($e->getMessage(), $e);
        }
    }

    private function validateMetrics(array $metrics): void
    {
        foreach ($metrics as $metric) {
            if ($this->thresholds->isExceeded($metric)) {
                $this->emergency->handleThresholdViolation($metric);
                throw new MetricViolationException(
                    "Critical threshold exceeded for metric: {$metric->getName()}"
                );
            }
        }
    }

    private function validateAnalysis(HealthAnalysis $analysis): void
    {
        if (!$analysis->isHealthy()) {
            $this->emergency->handleUnhealthyAnalysis($analysis);
            throw new UnhealthySystemException('System health analysis failed');
        }
    }

    private function runDiagnostics(HealthAnalysis $analysis): DiagnosticResults
    {
        $results = $this->diagnostics->runDiagnostics($analysis);
        
        if ($results->hasCriticalIssues()) {
            $this->emergency->handleCriticalDiagnostics($results);
            throw new CriticalDiagnosticException('Critical diagnostic issues detected');
        }
        
        return $results;
    }

    private function handleMonitoringFailure(
        HealthMonitoringException $e,
        string $monitoringId
    ): void {
        $this->logger->logFailure($e, $monitoringId);
        
        if ($e->isCritical()) {
            $this->emergency->initiateCriticalResponse([
                'exception' => $e,
                'monitoringId' => $monitoringId,
                'severity' => EmergencyLevel::CRITICAL
            ]);
        }
    }
}
