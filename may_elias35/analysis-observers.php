<?php

namespace App\Core\Audit\Observers;

class AnalysisObserver
{
    private LoggerInterface $logger;
    private MetricsCollector $metrics;
    private NotificationManager $notifications;

    public function __construct(
        LoggerInterface $logger,
        MetricsCollector $metrics,
        NotificationManager $notifications
    ) {
        $this->logger = $logger;
        $this->metrics = $metrics;
        $this->notifications = $notifications;
    }

    public function onAnalysisStarted(Analysis $analysis): void
    {
        $this->logger->info('Analysis started', [
            'analysis_id' => $analysis->getId(),
            'type' => $analysis->getType(),
            'config' => $analysis->getConfig()
        ]);

        $this->metrics->increment('analysis_started', 1, [
            'type' => $analysis->getType()
        ]);
    }

    public function onAnalysisCompleted(Analysis $analysis, AnalysisResult $result): void
    {
        $this->logger->info('Analysis completed', [
            'analysis_id' => $analysis->getId(),
            'duration' => $result->getDuration(),
            'metrics' => $result->getMetrics()
        ]);

        $this->metrics->timing('analysis_duration', $result->getDuration(), [
            'type' => $analysis->getType()
        ]);

        if ($this->shouldNotify($result)) {
            $this->notifications->sendAnalysisComplete($analysis, $result);
        }
    }

    public function onAnalysisFailed(Analysis $analysis, \Exception $exception): void
    {
        $this->logger->error('Analysis failed', [
            'analysis_id' => $analysis->getId(),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        $this->metrics->increment('analysis_failed', 1, [
            'type' => $analysis->getType(),
            'error_type' => get_class($exception)
        ]);

        $this->notifications->sendAnalysisError($analysis, $exception);
    }

    private function shouldNotify(AnalysisResult $result): bool
    {
        return $result->hasAnomalies() || 
               $result->hasCriticalFindings() ||
               $result->getDuration() > $this->getThreshold('duration');
    }

    private function getThreshold(string $metric): float
    {
        return config("analysis.thresholds.{$metric}", PHP_FLOAT_MAX);
    }
}

class PerformanceObserver
{
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private array $thresholds;

    public function __construct(
        MetricsCollector $metrics,
        AlertManager $alerts,
        array $thresholds = []
    ) {
        $this->metrics = $metrics;
        $this->alerts = $alerts;
        $this->thresholds = $thresholds;
    }

    public function onOperationStarted(string $operation): void
    {
        $this->metrics->startTimer($operation);
    }

    public function onOperationCompleted(string $operation): void
    {
        $duration = $this->metrics->endTimer($operation);

        if ($this->exceedsThreshold($operation, $duration)) {
            $this->alerts->sendPerformanceAlert($operation, $duration);
        }
    }

    public function onResourceUsageChanged(string $resource, float $usage): void
    {
        $this->metrics->gauge("resource.{$resource}", $usage);

        if ($this->exceedsThreshold($resource, $usage)) {
            $this->alerts->sendResourceAlert($resource, $usage);
        }
    }

    private function exceedsThreshold(string $metric, float $value): bool
    {
        return isset($this->thresholds[$metric]) && $value > $this->thresholds[$metric];
    }
}

class AnomalyObserver
{
    private NotificationManager $notifications;
    private MetricsCollector $metrics;
    private LoggerInterface $logger;

    public function __construct(
        NotificationManager $notifications,
        MetricsCollector $metrics,
        LoggerInterface $logger
    ) {
        $this->notifications = $notifications;
        $this->metrics = $metrics;
        $this->logger = $logger;
    }

    public function onAnomalyDetected(Anomaly $anomaly): void
    {
        $this->logger->warning('Anomaly detected', [
            'type' => $anomaly->getType(),
            'severity' => $anomaly->getSeverity(),
            'data' => $anomaly->getData()
        ]);

        $this->metrics->increment('anomalies_detected', 1, [
            'type' => $anomaly->getType(),
            'severity' => $anomaly->getSeverity()
        ]);

        if ($anomaly->isCritical()) {
            $this->notifications->sendCriticalAnomalyAlert($anomaly);
        }
    }

    public function onAnomalyResolved(Anomaly $anomaly): void
    {
        $this->logger->info('Anomaly resolved', [
            'type' => $anomaly->getType(),
            'resolution' => $anomaly->getResolution()
        ]);

        $this->metrics->increment('anomalies_resolved', 1, [
            'type' => $anomaly->getType()
        ]);

        $this->notifications->sendAnomalyResolutionNotice($anomaly);
    }
}

class StateObserver
{
    private LoggerInterface $logger;
    private StateManager $stateManager;
    private EventDispatcher $dispatcher;

    public function __construct(
        LoggerInterface $logger,
        StateManager $stateManager,
        EventDispatcher $dispatcher
    ) {
        $this->logger = $logger;
        $this->stateManager = $stateManager;
        $this->dispatcher = $dispatcher;
    }

    public function onStateChanged(string $key, $oldValue, $newValue): void
    {
        $this->logger->info('State changed', [
            'key' => $key,
            'old_value' => $oldValue,
            'new_value' => $newValue
        ]);

        $this->stateManager->recordStateChange($key, $oldValue, $newValue);
        
        $this->dispatcher->dispatch(new StateChangedEvent(
            $key,
            $oldValue,
            $newValue
        ));
    }

    public function onStateRestored(array $state): void
    {
        $this->logger->info('State restored', [
            'keys' => array_keys($state)
        ]);

        foreach ($state as $key => $value) {
            $this->stateManager->setState($key, $value);
        }

        $this->dispatcher->dispatch(new StateRestoredEvent($state));
    }
}
