<?php

namespace App\Core\Audit\Loggers;

class AnalyticsLogger
{
    private LoggerInterface $logger;
    private MetricsCollector $metrics;
    private array $config;

    public function __construct(LoggerInterface $logger, MetricsCollector $metrics, array $config)
    {
        $this->logger = $logger;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function logAnalysis(AnalysisResult $result): void
    {
        $this->logger->info('Analysis completed', [
            'analysis_id' => $result->getId(),
            'duration' => $result->getDuration(),
            'metrics' => $result->getMetrics()
        ]);

        $this->metrics->record('analysis.duration', $result->getDuration());
        $this->metrics->increment('analysis.completed');

        foreach ($result->getMetrics() as $metric => $value) {
            $this->metrics->record("analysis.metric.{$metric}", $value);
        }
    }

    public function logError(\Exception $e, array $context = []): void
    {
        $this->logger->error('Analysis error', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'context' => $context
        ]);

        $this->metrics->increment('analysis.errors', 1, [
            'type' => get_class($e)
        ]);
    }
}

class AuditLogger
{
    private LoggerInterface $logger;
    private EventDispatcher $dispatcher;
    private array $config;

    public function __construct(LoggerInterface $logger, EventDispatcher $dispatcher, array $config)
    {
        $this->logger = $logger;
        $this->dispatcher = $dispatcher;
        $this->config = $config;
    }

    public function log(string $action, array $data, array $context = []): void
    {
        $entry = new AuditEntry(
            $action,
            $data,
            $context,
            auth()->user(),
            request()->ip()
        );

        $this->logger->info('Audit log', [
            'action' => $entry->getAction(),
            'data' => $entry->getData(),
            'user' => $entry->getUser()?->id,
            'ip' => $entry->getIp(),
            'timestamp' => $entry->getTimestamp()
        ]);

        $this->dispatcher->dispatch(new AuditLoggedEvent($entry));
    }
}

class PerformanceLogger
{
    private LoggerInterface $logger;
    private MetricsCollector $metrics;
    private array $thresholds;

    public function __construct(LoggerInterface $logger, MetricsCollector $metrics, array $thresholds)
    {
        $this->logger = $logger;
        $this->metrics = $metrics;
        $this->thresholds = $thresholds;
    }

    public function startTimer(string $operation): void
    {
        $this->metrics->startTimer($operation);
    }

    public function endTimer(string $operation): float
    {
        $duration = $this->metrics->endTimer($operation);

        if ($this->exceedsThreshold($operation, $duration)) {
            $this->logger->warning('Performance threshold exceeded', [
                'operation' => $operation,
                'duration' => $duration,
                'threshold' => $this->thresholds[$operation]
            ]);
        }

        return $duration;
    }

    private function exceedsThreshold(string $operation, float $duration): bool
    {
        return isset($this->thresholds[$operation]) && $duration > $this->thresholds[$operation];
    }
}

class ErrorLogger
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

    public function logError(\Throwable $error, array $context = []): void
    {
        $this->logger->error($error->getMessage(), [
            'exception' => $error,
            'trace' => $error->getTraceAsString(),
            'context' => $context
        ]);

        $this->metrics->increment('errors_total', 1, [
            'type' => get_class($error)
        ]);

        if ($this->isCritical($error)) {
            $this->notifications->notifyCriticalError($error);
        }
    }

    private function isCritical(\Throwable $error): bool
    {
        return $error instanceof CriticalException ||
               $error instanceof SecurityException ||
               $error->getCode() >= 500;
    }
}

class SecurityLogger
{
    private LoggerInterface $logger;
    private EventDispatcher $dispatcher;
    private array $config;

    public function __construct(LoggerInterface $logger, EventDispatcher $dispatcher, array $config)
    {
        $this->logger = $logger;
        $this->dispatcher = $dispatcher;
        $this->config = $config;
    }

    public function logSecurityEvent(string $type, array $data): void
    {
        $event = new SecurityEvent($type, $data, [
            'user_id' => auth()->id(),
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);

        $this->logger->info('Security event', [
            'type' => $event->getType(),
            'data' => $event->getData(),
            'context' => $event->getContext()
        ]);

        $this->dispatcher->dispatch($event);

        if ($this->isHighRisk($type)) {
            $this->dispatchAlert($event);
        }
    }

    private function isHighRisk(string $type): bool
    {
        return in_array($type, $this->config['high_risk_events'] ?? []);
    }

    private function dispatchAlert(SecurityEvent $event): void
    {
        $this->dispatcher->dispatch(new SecurityAlertEvent($event));
    }
}
