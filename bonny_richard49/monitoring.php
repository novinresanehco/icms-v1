<?php
namespace App\Monitoring;

class SystemMonitor {
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private LogManager $logs;
    private array $thresholds;

    public function monitor(): void {
        $metrics = $this->metrics->collect();
        
        foreach ($metrics as $metric => $value) {
            if ($this->isThresholdExceeded($metric, $value)) {
                $this->alerts->trigger($metric, $value);
                $this->logs->critical("Threshold exceeded for $metric: $value");
            }
        }
    }

    private function isThresholdExceeded(string $metric, $value): bool {
        return $value > ($this->thresholds[$metric] ?? 0);
    }
}

class MetricsCollector {
    private array $collectors = [];
    private TimeService $time;

    public function collect(): array {
        $metrics = [];
        $timestamp = $this->time->now();

        foreach ($this->collectors as $name => $collector) {
            $metrics[$name] = [
                'value' => $collector->getValue(),
                'timestamp' => $timestamp
            ];
        }

        return $metrics;
    }
}

class PerformanceMonitor {
    private array $metrics = [];
    private AlertService $alerts;

    public function trackOperation(string $operation): void {
        $start = microtime(true);
        register_shutdown_function(function() use ($operation, $start) {
            $duration = microtime(true) - $start;
            $this->recordMetric($operation, $duration);
        });
    }

    private function recordMetric(string $operation, float $duration): void {
        $this->metrics[$operation][] = $duration;
        
        if ($this->isPerformanceDegraded($operation)) {
            $this->alerts->performance($operation, $duration);
        }
    }

    private function isPerformanceDegraded(string $operation): bool {
        $avg = array_sum($this->metrics[$operation]) / count($this->metrics[$operation]);
        return $avg > MonitoringConfig::PERFORMANCE_THRESHOLD;
    }
}

class SecurityMonitor {
    private AccessLogger $accessLog;
    private ThreatDetector $detector;
    private AlertService $alerts;

    public function monitorAccess(): void {
        $suspicious = $this->detector->analyze($this->accessLog->recent());
        
        foreach ($suspicious as $access) {
            $this->alerts->security($access);
        }
    }
}

class ResourceMonitor {
    private SystemStats $stats;
    private AlertService $alerts;
    private array $thresholds;

    public function checkResources(): void {
        $usage = $this->stats->current();
        
        if ($usage['cpu'] > $this->thresholds['cpu']) {
            $this->alerts->resource('cpu', $usage['cpu']);
        }
        
        if ($usage['memory'] > $this->thresholds['memory']) {
            $this->alerts->resource('memory', $usage['memory']);
        }
        
        if ($usage['disk'] > $this->thresholds['disk']) {
            $this->alerts->resource('disk', $usage['disk']);
        }
    }
}

class LogManager {
    private LogStorage $storage;
    private LogFormatter $formatter;
    private array $config;

    public function log(string $level, string $message, array $context = []): void {
        $entry = $this->formatter->format([
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'timestamp' => time()
        ]);
        
        $this->storage->store($entry);
    }

    public function critical(string $message, array $context = []): void {
        $this->log('CRITICAL', $message, $context);
    }
}

class AlertManager {
    private NotificationService $notifications;
    private EscalationService $escalation;
    private array $config;

    public function trigger(string $type, $data): void {
        $alert = [
            'type' => $type,
            'data' => $data,
            'timestamp' => time()
        ];

        $this->notifications->send($alert);
        
        if ($this->requiresEscalation($type)) {
            $this->escalation->escalate($alert);
        }
    }

    private function requiresEscalation(string $type): bool {
        return in_array($type, $this->config['escalation_types']);
    }
}

// Monitoring Interfaces
interface MetricCollector {
    public function getValue(): mixed;
    public function reset(): void;
}

interface AlertService {
    public function performance(string $operation, float $duration): void;
    public function security(array $access): void;
    public function resource(string $type, float $usage): void;
}

interface LogStorage {
    public function store(array $