<?php

namespace App\Core\Audit\Monitors;

class PerformanceMonitor
{
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private array $thresholds;
    private LoggerInterface $logger;

    public function __construct(
        MetricsCollector $metrics,
        AlertManager $alerts,
        array $thresholds,
        LoggerInterface $logger
    ) {
        $this->metrics = $metrics;
        $this->alerts = $alerts;
        $this->thresholds = $thresholds;
        $this->logger = $logger;
    }

    public function recordMetric(string $name, $value, array $tags = []): void
    {
        $this->metrics->record($name, $value, $tags);
        
        if ($this->exceedsThreshold($name, $value)) {
            $this->alerts->send(new ThresholdAlert($name, $value, $this->thresholds[$name]));
        }
    }

    public function startTimer(string $name, array $tags = []): void
    {
        $this->metrics->startTimer($name, $tags);
    }

    public function endTimer(string $name, array $tags = []): float
    {
        $duration = $this->metrics->endTimer($name, $tags);
        
        if ($this->exceedsThreshold($name, $duration)) {
            $this->alerts->send(new PerformanceAlert($name, $duration));
        }
        
        return $duration;
    }

    private function exceedsThreshold(string $name, $value): bool
    {
        return isset($this->thresholds[$name]) && $value > $this->thresholds[$name];
    }
}

class ResourceMonitor
{
    private MetricsCollector $metrics;
    private ResourceLimiter $limiter;
    private LoggerInterface $logger;

    public function __construct(
        MetricsCollector $metrics,
        ResourceLimiter $limiter,
        LoggerInterface $logger
    ) {
        $this->metrics = $metrics;
        $this->limiter = $limiter;
        $this->logger = $logger;
    }

    public function checkResources(): void
    {
        $memory = memory_get_usage(true);
        $cpu = sys_getloadavg()[0];
        
        $this->metrics->gauge('memory_usage', $memory);
        $this->metrics->gauge('cpu_usage', $cpu);
        
        if ($this->limiter->isMemoryExceeded($memory)) {
            $this->logger->warning('Memory usage high', ['memory' => $memory]);
            $this->limiter->enforceMemoryLimit();
        }
        
        if ($this->limiter->isCpuExceeded($cpu)) {
            $this->logger->warning('CPU usage high', ['cpu' => $cpu]);
            $this->limiter->enforceCpuLimit();
        }
    }
}

class HealthMonitor
{
    private array $checks;
    private HealthReporter $reporter;
    private LoggerInterface $logger;
    private int $interval;

    public function __construct(
        array $checks,
        HealthReporter $reporter,
        LoggerInterface $logger,
        int $interval = 60
    ) {
        $this->checks = $checks;
        $this->reporter = $reporter;
        $this->logger = $logger;
        $this->interval = $interval;
    }

    public function monitor(): void
    {
        while (true) {
            $results = [];
            
            foreach ($this->checks as $check) {
                try {
                    $results[$check->getName()] = $check->run();
                } catch (\Exception $e) {
                    $this->logger->error('Health check failed', [
                        'check' => $check->getName(),
                        'error' => $e->getMessage()
                    ]);
                    $results[$check->getName()] = new HealthCheckResult(false, $e->getMessage());
                }
            }
            
            $this->reporter->report($results);
            sleep($this->interval);
        }
    }
}

class ActivityMonitor
{
    private EventDispatcher $dispatcher;
    private MetricsCollector $metrics;
    private array $config;

    public function __construct(
        EventDispatcher $dispatcher,
        MetricsCollector $metrics,
        array $config = []
    ) {
        $this->dispatcher = $dispatcher;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function recordActivity(string $type, array $data = []): void
    {
        $activity = new Activity($type, $data);
        
        $this->dispatcher->dispatch(new ActivityRecordedEvent($activity));
        $this->metrics->increment("activity.{$type}");
        
        if ($this->shouldLog($type)) {
            $this->logActivity($activity);
        }
    }

    private function shouldLog(string $type): bool
    {
        return in_array($type, $this->config['logged_types'] ?? []);
    }

    private function logActivity(Activity $activity): void
    {
        $logger = $this->config['logger'] ?? null;
        if ($logger) {
            $logger->info('Activity recorded', [
                'type' => $activity->getType(),
                'data' => $activity->getData()
            ]);
        }
    }
}

class ErrorMonitor
{
    private ErrorHandler $handler;
    private MetricsCollector $metrics;
    private NotificationManager $notifications;
    private LoggerInterface $logger;

    public function __construct(
        ErrorHandler $handler,
        MetricsCollector $metrics,
        NotificationManager $notifications,
        LoggerInterface $logger
    ) {
        $this->handler = $handler;
        $this->metrics = $metrics;
        $this->notifications = $notifications;
        $this->logger = $logger;
    }

    public function handleError(\Throwable $error, array $context = []): void
    {
        $this->metrics->increment('errors', 1, ['type' => get_class($error)]);
        
        $this->logger->error($error->getMessage(), [
            'exception' => $error,
            'context' => $context
        ]);
        
        if ($this->isCritical($error)) {
            $this->notifications->sendCriticalErrorAlert($error);
        }
        
        $this->handler->handle($error, $context);
    }

    private function isCritical(\Throwable $error): bool
    {
        return $error instanceof CriticalException ||
               $error instanceof SecurityException ||
               $error->getCode() >= 500;
    }
}
