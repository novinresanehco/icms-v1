<?php
namespace App\Core\Monitoring;

class MonitoringManager implements MonitoringInterface
{
    private SecurityManager $security;
    private MetricsCollector $metrics;
    private LogManager $logs;
    private AlertSystem $alerts;
    private PerformanceMonitor $performance;

    public function __construct(
        SecurityManager $security,
        MetricsCollector $metrics,
        LogManager $logs,
        AlertSystem $alerts,
        PerformanceMonitor $performance
    ) {
        $this->security = $security;
        $this->metrics = $metrics;
        $this->logs = $logs;
        $this->alerts = $alerts;
        $this->performance = $performance;
    }

    public function monitor(string $operation, callable $callback): mixed
    {
        $context = new MonitoringContext($operation);
        
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

    private function startMonitoring(MonitoringContext $context): void
    {
        $this->metrics->startOperation($context);
        $this->performance->startTracking($context);
        $this->logs->info("Starting operation: {$context->operation}");
    }

    private function endMonitoring(MonitoringContext $context): void
    {
        $metrics = $this->metrics->endOperation($context);
        $performance = $this->performance->endTracking($context);
        
        $this->logs->info("Operation complete: {$context->operation}", [
            'metrics' => $metrics,
            'performance' => $performance
        ]);

        $this->checkThresholds($metrics, $performance);
    }

    private function handleFailure(MonitoringContext $context, \Throwable $e): void
    {
        $this->metrics->recordFailure($context, $e);
        $this->logs->error("Operation failed: {$context->operation}", [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        $this->alerts->sendFailureAlert($context, $e);
    }

    private function checkThresholds(array $metrics, array $performance): void
    {
        if ($this->thresholdsExceeded($metrics, $performance)) {
            $this->alerts->sendThresholdAlert($metrics, $performance);
        }
    }
}

class MetricsCollector
{
    private array $activeOperations = [];
    private MetricsRepository $repository;

    public function startOperation(MonitoringContext $context): void
    {
        $this->activeOperations[$context->id] = [
            'start_time' => microtime(true),
            'memory_start' => memory_get_usage(true),
            'context' => $context
        ];
    }

    public function endOperation(MonitoringContext $context): array
    {
        $op = $this->activeOperations[$context->id];
        
        $metrics = [
            'duration' => microtime(true) - $op['start_time'],
            'memory_peak' => memory_get_peak_usage(true),
            'memory_used' => memory_get_usage(true) - $op['memory_start']
        ];

        $this->repository->store($context, $metrics);
        unset($this->activeOperations[$context->id]);
        
        return $metrics;
    }

    public function recordFailure(MonitoringContext $context, \Throwable $e): void
    {
        $this->repository->storeFailure($context, [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode()
        ]);
    }
}

class PerformanceMonitor
{
    private array $trackers = [];
    private PerformanceRepository $repository;

    public function startTracking(MonitoringContext $context): void
    {
        $this->trackers[$context->id] = [
            'queries' => $this->captureQueryCount(),
            'time' => microtime(true),
            'cpu' => $this->getCpuUsage()
        ];
    }

    public function endTracking(MonitoringContext $context): array
    {
        $start = $this->trackers[$context->id];
        
        $metrics = [
            'query_count' => $this->captureQueryCount() - $start['queries'],
            'execution_time' => microtime(true) - $start['time'],
            'cpu_usage' => $this->getCpuUsage() - $start['cpu']
        ];

        $this->repository->store($context, $metrics);
        unset($this->trackers[$context->id]);
        
        return $metrics;
    }

    private function captureQueryCount(): int
    {
        return DB::getQueryLog()->count();
    }

    private function getCpuUsage(): float
    {
        return sys_getloadavg()[0];
    }
}

class AlertSystem
{
    private NotificationService $notifications;
    private ThresholdConfig $thresholds;

    public function sendFailureAlert(MonitoringContext $context, \Throwable $e): void
    {
        $this->notifications->sendCritical(
            "Operation Failed: {$context->operation}",
            [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'context' => $context
            ]
        );
    }

    public function sendThresholdAlert(array $metrics, array $performance): void
    {
        $this->notifications->sendWarning(
            "Performance Thresholds Exceeded",
            [
                'metrics' => $metrics,
                'performance' => $performance,
                'thresholds' => $this->thresholds->getExceeded($metrics, $performance)
            ]
        );
    }
}

class LogManager
{
    private array $channels;
    private LogRepository $repository;

    public function emergency(string $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    private function log(string $level, string $message, array $context): void
    {
        $entry = new LogEntry($level, $message, $context);
        
        foreach ($this->channels as $channel) {
            $channel->log($entry);
        }

        $this->repository->store($entry);
    }
}

class LogEntry
{
    public string $level;
    public string $message;
    public array $context;
    public string $timestamp;

    public function __construct(string $level, string $message, array $context)
    {
        $this->level = $level;
        $this->message = $message;
        $this->context = $context;
        $this->timestamp = now()->toIso8601String();
    }
}

interface LogLevel
{
    const EMERGENCY = 'emergency';
    const ALERT     = 'alert';
    const CRITICAL  = 'critical';
    const ERROR     = 'error';
    const WARNING   = 'warning';
    const INFO      = 'info';
}
