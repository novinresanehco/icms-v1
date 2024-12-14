<?php

namespace App\Core\Notification\Analytics\Observer;

class EventManager
{
    private array $listeners = [];
    private array $timers = [];
    private array $metrics = [];
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function registerListener(string $event, callable $listener, array $options = []): void
    {
        if (!isset($this->listeners[$event])) {
            $this->listeners[$event] = [];
        }

        $this->listeners[$event][] = [
            'callback' => $listener,
            'options' => $options
        ];
    }

    public function dispatch(string $event, array $data = []): void
    {
        $this->startTimer($event);

        if (isset($this->listeners[$event])) {
            foreach ($this->listeners[$event] as $listener) {
                try {
                    call_user_func($listener['callback'], $data);
                    $this->recordSuccess($event, $listener);
                } catch (\Exception $e) {
                    $this->handleError($event, $e, $listener);
                }
            }
        }

        $this->stopTimer($event);
        $this->recordMetrics($event);
    }

    public function getMetrics(): array
    {
        return [
            'events' => $this->metrics,
            'timers' => $this->calculateTimerStats()
        ];
    }

    private function startTimer(string $event): void
    {
        $this->timers[$event] = [
            'start' => microtime(true),
            'memory_start' => memory_get_usage(true)
        ];
    }

    private function stopTimer(string $event): void
    {
        if (isset($this->timers[$event])) {
            $this->timers[$event]['end'] = microtime(true);
            $this->timers[$event]['memory_end'] = memory_get_usage(true);
            $this->timers[$event]['duration'] = $this->timers[$event]['end'] - $this->timers[$event]['start'];
            $this->timers[$event]['memory_used'] = $this->timers[$event]['memory_end'] - $this->timers[$event]['memory_start'];
        }
    }

    private function recordSuccess(string $event, array $listener): void
    {
        if (!isset($this->metrics[$event])) {
            $this->metrics[$event] = [
                'success' => 0,
                'error' => 0,
                'total_time' => 0
            ];
        }

        $this->metrics[$event]['success']++;
        $this->metrics[$event]['total_time'] += $this->timers[$event]['duration'] ?? 0;
    }

    private function handleError(string $event, \Exception $error, array $listener): void
    {
        if (!isset($this->metrics[$event])) {
            $this->metrics[$event] = [
                'success' => 0,
                'error' => 0,
                'total_time' => 0
            ];
        }

        $this->metrics[$event]['error']++;

        event(new EventProcessingError($event, $error->getMessage(), [
            'listener' => get_class($listener['callback']),
            'trace' => $error->getTraceAsString()
        ]));
    }

    private function recordMetrics(string $event): void
    {
        $timer = $this->timers[$event] ?? [];
        
        if (!empty($timer)) {
            MetricsCollector::record("event.{$event}.duration", $timer['duration']);
            MetricsCollector::record("event.{$event}.memory", $timer['memory_used']);
        }
    }

    private function calculateTimerStats(): array
    {
        $stats = [];
        
        foreach ($this->timers as $event => $timer) {
            $stats[$event] = [
                'avg_duration' => $timer['duration'] ?? 0,
                'memory_used' => $timer['memory_used'] ?? 0,
                'total_time' => $this->metrics[$event]['total_time'] ?? 0
            ];
        }
        
        return $stats;
    }
}

class MetricsCollector
{
    private static array $metrics = [];

    public static function record(string $metric, $value): void
    {
        if (!isset(self::$metrics[$metric])) {
            self::$metrics[$metric] = [];
        }

        self::$metrics[$metric][] = [
            'value' => $value,
            'timestamp' => microtime(true)
        ];
    }

    public static function getMetrics(string $metric = null): array
    {
        if ($metric !== null) {
            return self::$metrics[$metric] ?? [];
        }

        return self::$metrics;
    }

    public static function clear(): void
    {
        self::$metrics = [];
    }
}
