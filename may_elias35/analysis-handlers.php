<?php

namespace App\Core\Audit\Handlers;

class AnalysisEventHandler
{
    private LoggerInterface $logger;
    private MetricsCollector $metrics;
    private NotificationService $notifications;

    public function __construct(
        LoggerInterface $logger,
        MetricsCollector $metrics,
        NotificationService $notifications
    ) {
        $this->logger = $logger;
        $this->metrics = $metrics;
        $this->notifications = $notifications;
    }

    public function handleAnalysisStarted(AnalysisStartedEvent $event): void
    {
        $this->logger->info('Analysis started', [
            'analysis_id' => $event->getAnalysisId(),
            'config' => $event->getConfig(),
            'metadata' => $event->getMetadata()
        ]);

        $this->metrics->incrementCounter('analysis_started');
        $this->metrics->recordGauge('active_analyses', 1);
    }

    public function handleAnalysisCompleted(AnalysisCompletedEvent $event): void
    {
        $this->logger->info('Analysis completed', [
            'analysis_id' => $event->getAnalysisId(),
            'metadata' => $event->getMetadata()
        ]);

        $this->metrics->incrementCounter('analysis_completed');
        $this->metrics->recordGauge('active_analyses', -1);
        $this->metrics->recordHistogram('analysis_duration', 
            $event->getMetadata()['duration'] ?? 0
        );
    }

    public function handleAnalysisFailed(AnalysisFailedEvent $event): void
    {
        $this->logger->error('Analysis failed', [
            'analysis_id' => $event->getAnalysisId(),
            'error' => $event->getException()->getMessage(),
            'context' => $event->getContext()
        ]);

        $this->metrics->incrementCounter('analysis_failed');
        $this->metrics->recordGauge('active_analyses', -1);

        if ($this->isCriticalError($event->getException())) {
            $this->notifications->sendAlert([
                'type' => 'analysis_failure',
                'analysis_id' => $event->getAnalysisId(),
                'error' => $event->getException()->getMessage(),
                'stack_trace' => $event->getException()->getTraceAsString()
            ]);
        }
    }

    public function handleAnomalyDetected(AnomalyDetectedEvent $event): void
    {
        $this->logger->warning('Anomaly detected', [
            'analysis_id' => $event->getAnalysisId(),
            'anomaly' => $event->getAnomaly(),
            'type' => $event->getType(),
            'context' => $event->getContext()
        ]);

        $this->metrics->incrementCounter('anomalies_detected', 1, [
            'type' => $event->getType()
        ]);

        if ($this->isSignificantAnomaly($event->getAnomaly())) {
            $this->notifications->sendAlert([
                'type' => 'significant_anomaly',
                'analysis_id' => $event->getAnalysisId(),
                'anomaly' => $event->getAnomaly(),
                'context' => $event->getContext()
            ]);
        }
    }

    private function isCriticalError(\Throwable $e): bool
    {
        return $e instanceof CriticalAnalysisException ||
               $e instanceof DataCorruptionException ||
               $e instanceof ResourceExhaustionException;
    }

    private function isSignificantAnomaly(array $anomaly): bool
    {
        return ($anomaly['confidence'] ?? 0) > 0.9 ||
               ($anomaly['severity'] ?? 'low') === 'high' ||
               ($anomaly['impact'] ?? 'low') === 'critical';
    }
}

class ErrorHandler
{
    private LoggerInterface $logger;
    private NotificationService $notifications;
    private ErrorTracker $tracker;

    public function __construct(
        LoggerInterface $logger,
        NotificationService $notifications,
        ErrorTracker $tracker
    ) {
        $this->logger = $logger;
        $this->notifications = $notifications;
        $this->tracker = $tracker;
    }

    public function handleException(\Throwable $e, array $context = []): void
    {
        $this->logException($e, $context);
        $this->trackError($e, $context);
        
        if ($this->shouldNotify($e)) {
            $this->notifyError($e, $context);
        }

        if ($this->shouldRethrow($e)) {
            throw $e;
        }
    }

    private function logException(\Throwable $e, array $context): void
    {
        $this->logger->error($e->getMessage(), [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'context' => $context
        ]);
    }

    private function trackError(\Throwable $e, array $context): void
    {
        $this->tracker->recordError([
            'type' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'context' => $context,
            'timestamp' => time()
        ]);
    }

    private function notifyError(\Throwable $e, array $context): void
    {
        $this->notifications->sendAlert([
            'type' => 'error',
            'error' => get_class($e),
            'message' => $e->getMessage(),
            'context' => $context,
            'severity' => $this->determineSeverity($e)
        ]);
    }

    private function shouldNotify(\Throwable $e): bool
    {
        return $e instanceof CriticalException ||
               $e instanceof SecurityException ||
               $e instanceof DataCorruptionException;
    }

    private function shouldRethrow(\Throwable $e): bool
    {
        return $e instanceof UnrecoverableException ||
               $e instanceof SecurityException;
    }

    private function determineSeverity(\Throwable $e): string
    {
        if ($e instanceof CriticalException) return 'critical';
        if ($e instanceof SecurityException) return 'critical';
        if ($e instanceof DataCorruptionException) return 'high';
        if ($e instanceof ValidationException) return 'medium';
        return 'low';
    }
}
