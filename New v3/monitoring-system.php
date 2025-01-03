<?php

namespace App\Core\Monitoring;

class MetricsCollector implements MetricsInterface
{
    private MetricsStore $store;
    private int $flushInterval = 60;
    private array $buffer = [];

    public function record(string $metric, float $value): void
    {
        $this->buffer[] = [
            'metric' => $metric,
            'value' => $value,
            'timestamp' => microtime(true)
        ];

        if (count($this->buffer) >= 100) {
            $this->flush();
        }
    }

    public function increment(string $metric): void
    {
        $this->record($metric, 1);
    }

    public function gauge(string $metric, float $value): void
    {
        $this->store->gauge($metric, $value);
    }

    private function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        $this->store->batchStore($this->buffer);
        $this->buffer = [];
    }
}

class SystemMonitor
{
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private float $startTime;

    public function startRequest(): void
    {
        $this->startTime = microtime(true);
    }

    public function endRequest(Request $request): void
    {
        $duration = microtime(true) - $this->startTime;
        
        $this->metrics->record('request_duration', $duration);
        $this->metrics->gauge('memory_usage', memory_get_peak_usage(true));
        
        if ($duration > 0.5) {
            $this->alerts->warning('slow_request', [
                'duration' => $duration,
                'url' => $request->fullUrl()
            ]);
        }
    }

    public function checkSystem(): array
    {
        return [
            'memory' => $this->checkMemory(),
            'cpu' => $this->checkCpu(),
            'disk' => $this->checkDisk(),
            'services' => $this->checkServices()
        ];
    }

    private function checkMemory(): array
    {
        $usage = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        
        $this->metrics->gauge('memory_current', $usage);
        $this->metrics->gauge('memory_peak', $peak);
        
        return ['current' => $usage, 'peak' => $peak];
    }

    private function checkCpu(): float
    {
        $load = sys_getloadavg()[0];
        $this->metrics->gauge('cpu_load', $load);
        return $load;
    }

    private function checkDisk(): array
    {
        $total = disk_total_space('/');
        $free = disk_free_space('/');
        $used = $total - $free;
        
        $this->metrics->gauge('disk_used', $used);
        $this->metrics->gauge('disk_free', $free);
        
        return [
            'total' => $total,
            'used' => $used,
            'free' => $free
        ];
    }

    private function checkServices(): array
    {
        $services = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'queue' => $this->checkQueue()
        ];

        foreach ($services as $name => $status) {
            $this->metrics->gauge("service_{$name}", $status ? 1 : 0);
        }

        return $services;
    }
}

class AlertManager
{
    private NotificationService $notifications;
    private LoggerInterface $logger;
    private array $levels = ['emergency', 'alert', 'critical', 'error', 'warning'];

    public function emergency(string $message, array $context = []): void
    {
        $this->alert('emergency', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->alert('critical', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->alert('warning', $message, $context);
    }

    private function alert(string $level, string $message, array $context): void
    {
        $this->logger->log($level, $message, $context);

        if (in_array($level, ['emergency', 'critical'])) {
            $this->notifications->send([
                'level' => $level,
                'message' => $message,
                'context' => $context
            ]);
        }
    }
}

class PerformanceProfiler
{
    private array $timers = [];
    private MetricsCollector $metrics;

    public function start(string $operation): void
    {
        $this->timers[$operation] = microtime(true);
    }

    public function end(string $operation): float
    {
        if (!isset($this->timers[$operation])) {
            return 0;
        }

        $duration = microtime(true) - $this->timers[$operation];
        unset($this->timers[$operation]);

        $this->metrics->record("operation_{$operation}", $duration);
        return $duration;
    }

    public function wrap(string $operation, callable $callback): mixed
    {
        $this->start($operation);
        try {
            return $callback();
        } finally {
            $this->end($operation);
        }
    }
}
