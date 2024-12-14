<?php

namespace App\Core\Observability;

class ObservabilityService implements ObservabilityInterface
{
    private MetricsCollector $metricsCollector;
    private TraceAnalyzer $traceAnalyzer;
    private LogAggregator $logAggregator;
    private AlertManager $alertManager;
    private ObservabilityLogger $logger;
    private EmergencyProtocol $emergency;

    public function __construct(
        MetricsCollector $metricsCollector,
        TraceAnalyzer $traceAnalyzer,
        LogAggregator $logAggregator,
        AlertManager $alertManager,
        ObservabilityLogger $logger,
        EmergencyProtocol $emergency
    ) {
        $this->metricsCollector = $metricsCollector;
        $this->traceAnalyzer = $traceAnalyzer;
        $this->logAggregator = $logAggregator;
        $this->alertManager = $alertManager;
        $this->logger = $logger;
        $this->emergency = $emergency;
    }

    public function monitor(ObservabilityContext $context): ObservabilityResult
    {
        $monitoringId = $this->initializeMonitoring($context);
        
        try {
            DB::beginTransaction();

            $metrics = $this->collectMetrics($context);
            $traces = $this->analyzeTraces($context);
            $logs = $this->aggregateLogs($context);

            $this->validateObservabilityData([
                'metrics' => $metrics,
                'traces' => $traces,
                'logs' => $logs
            ]);

            $analysis = $this->analyzeData($metrics, $traces, $logs);
            $this->checkThresholds($analysis);

            $result = new ObservabilityResult([
                'monitoringId' => $monitoringId,
                'metrics' => $metrics,
                'traces' => $traces,
                'logs' => $logs,
                'analysis' => $analysis,
                'timestamp' => now()
            ]);

            DB::commit();
            return $result;

        } catch (ObservabilityException $e) {
            DB::rollBack();
            $this->handleMonitoringFailure($e, $monitoringId);
            throw new CriticalObservabilityException($e->getMessage(), $e);
        }
    }

    private function validateObservabilityData(array $data): void
    {
        foreach ($data as $type => $items) {
            if (!$this->validateDataType($type, $items)) {
                $this->emergency->handleInvalidData($type, $items);
                throw new DataValidationException("Invalid observability data: $type");
            }
        }
    }

    private function checkThresholds(Analysis $analysis): void
    {
        $violations = $analysis->checkThresholds();
        
        if (!empty($violations)) {
            foreach ($violations as $violation) {
                if ($violation->isCritical()) {
                    $this->alertManager->triggerCriticalAlert($violation);
                }
            }
            throw new ThresholdViolationException('Critical thresholds exceeded');
        }
    }
}
