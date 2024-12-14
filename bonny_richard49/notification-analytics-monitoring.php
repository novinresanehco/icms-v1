<?php

namespace App\Core\Notification\Analytics\Monitoring;

class PerformanceMonitor 
{
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private array $thresholds;

    public function __construct(MetricsCollector $metrics, AlertManager $alerts, array $thresholds)
    {
        $this->metrics = $metrics;
        $this->alerts = $alerts;
        $this->thresholds = $thresholds;
    }

    public function recordMetric(string $name, $value, array $tags = []): void
    {
        $this->metrics->record($name, $value, $tags);
        $this->checkThresholds($name, $value, $tags);
    }

    public function getMetrics(array $filter = []): array
    {
        return $this->metrics->query($filter);
    }

    private function checkThresholds(string $name, $value, array $tags): void
    {
        if (!isset($this->thresholds[$name])) {
            return;
        }

        $threshold = $this->thresholds[$name];
        if ($value > $threshold['critical']) {
            $this->alerts->critical("{$name} exceeded critical threshold", [
                'metric' => $name,
                'value' => $value,
                'threshold' => $threshold['critical'],
                'tags' => $tags
            ]);
        } elseif ($value > $threshold['warning']) {
            $this->alerts->warning("{$name} exceeded warning threshold", [
                'metric' => $name,
                'value' => $value,
                'threshold' => $threshold['warning'],
                'tags' => $tags
            ]);
        }
    }
}

class AlertManager
{
    private array $handlers = [];
    private array $alertHistory = [];

    public function registerHandler(string $level, callable $handler): void
    {
        $this->handlers[$level][] = $handler;
    }

    public function critical(string $message, array $context = []): void
    {
        $this->alert('critical', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->alert('warning', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->alert('info', $message, $context);
    }

    public function getAlertHistory(): array
    {
        return $this->alertHistory;
    }

    private function alert(string $level, string $message, array $context): void
    {
        $alert = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'timestamp' => microtime(true)
        ];

        $this->alertHistory[] = $alert;

        if (isset($this->handlers[$level])) {
            foreach ($this->handlers[$level] as $handler) {
                $handler($alert);
            }
        }
    }
}

class MetricsCollector
{
    private array $metrics = [];
    private string $prefix;

    public function __construct(string $prefix = '')
    {
        $this->prefix = $prefix;
    }

    public function record(string $name, $value, array $tags = []): void
    {
        $metricName = $this->prefix ? "{$this->prefix}.{$name}" : $name;

        $this->metrics[] = [
            'name' => $metricName,
            'value' => $value,
            'tags' => $tags,
            'timestamp' => microtime(true)
        ];
    }

    public function query(array $filter = []): array
    {
        return array_filter($this->metrics, function($metric) use ($filter) {
            foreach ($filter as $key => $value) {
                if (!isset($metric[$key]) || $metric[$key] !== $value) {
                    return false;
                }
            }
            return true;
        });
    }

    public function clear(): void
    {
        $this->metrics = [];
    }

    public function summarize(array $filter = []): array
    {
        $metrics = $this->query($filter);
        $summary = [];

        foreach ($metrics as $metric) {
            $name = $metric['name'];
            if (!isset($summary[$name])) {
                $summary[$name] = [
                    'count' => 0,
                    'sum' => 0,
                    'min' => PHP_FLOAT_MAX,
                    'max' => PHP_FLOAT_MIN
                ];
            }

            $summary[$name]['count']++;
            $summary[$name]['sum'] += $metric['value'];
            $summary[$name]['min'] = min($summary[$name]['min'], $metric['value']);
            $summary[$name]['max'] = max($summary[$name]['max'], $metric['value']);
            $summary[$name]['avg'] = $summary[$name]['sum'] / $summary[$name]['count'];
        }

        return $summary;
    }
}
