<?php

namespace App\Core\Monitoring;

class SystemMonitor implements MonitorInterface
{
    protected MetricsCollector $metrics;
    protected ConfigManager $config;
    protected AlertManager $alerts;
    protected array $thresholds;

    public function trackPerformance(string $operation, float $duration): void
    {
        $this->metrics->record('operation.duration', $duration, [
            'operation' => $operation
        ]);

        $threshold = $this->thresholds[$operation] ?? $this->thresholds['default'];
        
        if ($duration > $threshold) {
            $this->alerts->performance([
                'operation' => $operation,
                'duration' => $duration,
                'threshold' => $threshold
            ]);
        }
    }

    public function trackMemory(string $operation): void
    {
        $usage = memory_get_peak_usage(true);
        
        $this->metrics->record('memory.usage', $usage, [
            'operation' => $operation
        ]);

        if ($usage > $this->thresholds['memory']) {
            $this->alerts->memory([
                'operation' => $operation,
                'usage' => $usage,
                'threshold' => $this->thresholds['memory']
            ]);
        }
    }

    public function trackErrors(\Exception $e, string $context): void
    {
        $this->metrics->increment('error.count', [
            'type' => get_class($e),
            'context' => $context
        ]);

        $this->alerts->error([
            'exception' => $e,
            'context' => $context
        ]);
    }
}

class AlertManager implements AlertInterface
{
    protected NotificationService $notifications;
    protected LoggerService $logger;
    protected array $config;

    public function performance(array $data): void
    {
        $this->alert('performance', AlertLevel::Warning, $data);
    }

    public function memory(array $data): void
    {
        $this->alert('memory', AlertLevel::Warning, $data);
    }

    public function error(array $data): void
    {
        $this->alert('error', AlertLevel::Critical, $data);
    }

    protected function alert(string $type, AlertLevel $level, array $data): void
    {
        $this->logger->log($level, "{$type}_alert", $data);

        if ($this->shouldNotify($type, $level)) {
            $this->notifications->send(
                $this->getRecipients($type, $level),
                $this->formatAlert($type, $level, $data)
            );
        }
    }

    protected function shouldNotify(string $type, AlertLevel $level): bool
    {
        return $level->value >= $this->config['alert_threshold']
            && !$this->isThrottled($type);
    }

    protected function isThrottled(string $type): bool
    {
        $key = "alert_throttle:$type";
        return Cache::has($key);
    }
}

class MetricsCollector implements MetricsInterface
{
    protected StorageManager $storage;
    protected array $buffer = [];
    protected int $flushSize;

    public function record(string $metric, mixed $value, array $tags = []): void
    {
        $this->buffer[] = [
            'metric' => $metric,
            'value' => $value,
            'tags' => $tags,
            'timestamp' => microtime(true)
        ];

        if (count($this->buffer) >= $this->flushSize) {
            $this->flush();
        }
    }

    public function increment(string $metric, array $tags = []): void
    {
        $this->record($metric, 1, $tags);
    }

    protected function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        $this->storage->store($this->buffer);
        $this->buffer = [];
    }

    public function __destruct()
    {
        $this->flush();
    }
}

enum AlertLevel: int
{
    case Info = 0;
    case Warning = 1;
    case Critical = 2;
}

interface MonitorInterface
{
    public function trackPerformance(string $operation, float $duration): void;
    public function trackMemory(string $operation): void;
    public function trackErrors(\Exception $e, string $context): void;
}

interface AlertInterface
{
    public function performance(array $data): void;
    public function memory(array $data): void;
    public function error(array $data): void;
}

interface MetricsInterface
{
    public function record(string $metric, mixed $value, array $tags = []): void;
    public function increment(string $metric, array $tags = []): void;
}
