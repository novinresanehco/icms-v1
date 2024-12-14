```php
namespace App\Core\Monitoring;

class CriticalMonitor implements MonitorInterface
{
    private AlertSystem $alerts;
    private MetricsCollector $metrics;
    private SecurityManager $security;
    private LogManager $logger;

    public function trackOperation(string $operation, callable $callback): mixed
    {
        $context = $this->createContext($operation);
        
        try {
            $this->startMonitoring($context);
            $result = $callback();
            $this->endMonitoring($context);
            return $result;
        } catch (\Throwable $e) {
            $this->handleFailure($context, $e);
            throw $e;
        }
    }

    private function createContext(string $operation): MonitoringContext
    {
        return new MonitoringContext([
            'operation' => $operation,
            'start_time' => microtime(true),
            'memory_start' => memory_get_usage(true),
            'trace_id' => $this->security->generateTraceId()
        ]);
    }

    private function startMonitoring(MonitoringContext $context): void
    {
        $this->metrics->startOperation($context);
        $this->logger->debug('Operation started', $context->toArray());
    }

    private function endMonitoring(MonitoringContext $context): void
    {
        $metrics = [
            'duration' => microtime(true) - $context->start_time,
            'memory' => memory_get_usage(true) - $context->memory_start
        ];

        $this->metrics->endOperation($context, $metrics);
        
        if ($metrics['duration'] > config('monitoring.thresholds.duration')) {
            $this->alerts->performanceWarning($context, $metrics);
        }
    }

    private function handleFailure(MonitoringContext $context, \Throwable $e): void
    {
        $this->logger->error('Operation failed', [
            'context' => $context->toArray(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->alerts->criticalError($context, $e);
        $this->metrics->incrementFailure($context->operation);
    }
}

class AlertSystem
{
    private NotificationService $notifications;
    private SecurityManager $security;
    private ConfigManager $config;

    public function criticalError(MonitoringContext $context, \Throwable $e): void
    {
        $alert = new CriticalAlert([
            'type' => 'error',
            'operation' => $context->operation,
            'message' => $e->getMessage(),
            'severity' => AlertSeverity::CRITICAL,
            'timestamp' => now()
        ]);

        $this->security->validateAlert($alert);
        
        $this->notifications->sendToTeam(
            $this->formatAlertMessage($alert),
            $this->getRecipients(AlertSeverity::CRITICAL)
        );
    }

    public function performanceWarning(MonitoringContext $context, array $metrics): void
    {
        if ($metrics['duration'] > $this->config->get('monitoring.thresholds.critical')) {
            $this->escalateToTeam($context, $metrics);
        }
    }

    private function escalateToTeam(MonitoringContext $context, array $metrics): void
    {
        $this->notifications->sendUrgent(
            $this->formatPerformanceAlert($context, $metrics),
            $this->getRecipients(AlertSeverity::HIGH)
        );
    }
}

class LogManager implements LogInterface
{
    private array $handlers = [];
    private SecurityManager $security;

    public function error(string $message, array $context = []): void
    {
        $entry = new LogEntry([
            'level' => LogLevel::ERROR,
            'message' => $message,
            'context' => $this->security->sanitizeContext($context),
            'timestamp' => now(),
            'trace_id' => $context['trace_id'] ?? null
        ]);

        foreach ($this->handlers as $handler) {
            $handler->handle($entry);
        }
    }

    public function critical(string $message, array $context = []): void
    {
        $this->security->validateLogEntry($message, $context);
        
        $entry = new LogEntry([
            'level' => LogLevel::CRITICAL,
            'message' => $message,
            'context' => $this->security->sanitizeContext($context),
            'timestamp' => now(),
            'trace_id' => $context['trace_id'] ?? null
        ]);

        $this->processLogEntry($entry);
    }

    private function processLogEntry(LogEntry $entry): void
    {
        foreach ($this->handlers as $handler) {
            if ($handler->shouldHandle($entry)) {
                $handler->handle($entry);
            }
        }
    }
}
```
