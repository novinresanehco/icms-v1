<?php

namespace App\Core\Monitoring;

/**
 * Core system monitoring implementation with critical alert management
 */
class SystemMonitor {
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private LogManager $logs;
    private array $thresholds;
    private array $criticalMetrics = [];
    private bool $emergencyMode = false;

    public function __construct(
        MetricsCollector $metrics,
        AlertManager $alerts, 
        LogManager $logs,
        array $thresholds
    ) {
        $this->metrics = $metrics;
        $this->alerts = $alerts;
        $this->logs = $logs;
        $this->thresholds = $thresholds;
    }

    public function monitor(): void {
        try {
            $this->beginMonitoring();
            $metrics = $this->collectMetrics();
            $this->analyzeMetrics($metrics);
            $this->logMonitoringComplete();
        } catch (\Throwable $e) {
            $this->handleMonitoringFailure($e);
        }
    }

    private function beginMonitoring(): void {
        $this->logs->info('Beginning system monitoring cycle');
        $this->criticalMetrics = [];
    }

    private function collectMetrics(): array {
        $this->logs->debug('Collecting system metrics');
        return $this->metrics->collect();
    }

    private function analyzeMetrics(array $metrics): void {
        foreach ($metrics as $metric => $value) {
            $this->checkMetric($metric, $value);
        }

        if (!empty($this->criticalMetrics)) {
            $this->handleCriticalMetrics();
        }
    }

    private function checkMetric(string $metric, $value): void {
        if ($this->isThresholdExceeded($metric, $value)) {
            $this->criticalMetrics[$metric] = $value;
            
            if ($this->isCriticalThreshold($metric, $value)) {
                $this->triggerCriticalAlert($metric, $value);
            } else {
                $this->triggerWarningAlert($metric, $value);
            }
            
            $this->logs->warning("Threshold exceeded for $metric: $value");
        }
    }

    private function isThresholdExceeded(string $metric, $value): bool {
        return $value > ($this->thresholds[$metric]['warning'] ?? PHP_FLOAT_MAX);
    }

    private function isCriticalThreshold(string $metric, $value): bool {
        return $value > ($this->thresholds[$metric]['critical'] ?? PHP_FLOAT_MAX);
    }

    private function handleCriticalMetrics(): void {
        if (!$this->emergencyMode) {
            $this->enterEmergencyMode();
        }
        
        $this->alerts->triggerEmergencyProtocol($this->criticalMetrics);
        $this->logs->critical('Multiple metrics in critical state', [
            'metrics' => $this->criticalMetrics
        ]);
    }

    private function enterEmergencyMode(): void {
        $this->emergencyMode = true;
        $this->alerts->escalateToEmergency();
        $this->logs->emergency('System entering emergency mode');
    }

    private function handleMonitoringFailure(\Throwable $e): void {
        $this->logs->critical('Monitoring system failure', [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        if (!$this->emergencyMode) {
            $this->enterEmergencyMode();
        }
        
        $this->alerts->triggerSystemFailure($e);
    }

    private function logMonitoringComplete(): void {
        $this->logs->info('Monitoring cycle complete');
    }

    private function triggerCriticalAlert(string $metric, $value): void {
        $this->alerts->triggerCritical($metric, $value);
    }

    private function triggerWarningAlert(string $metric, $value): void {
        $this->alerts->triggerWarning($metric, $value);
    }
}

class MetricsCollector {
    private array $collectors;
    private TimeService $time;

    public function collect(): array {
        $metrics = [];
        $timestamp = $this->time->now();

        foreach ($this->collectors as $name => $collector) {
            try {
                $metrics[$name] = [
                    'value' => $collector->getValue(),
                    'timestamp' => $timestamp
                ];
            } catch (\Throwable $e) {
                // Log collector failure but continue collecting other metrics
                Log::error("Metrics collector failure: {$name}", [
                    'exception' => $e->getMessage()
                ]);
            }
        }

        return $metrics;
    }
}

class ResourceMonitor {
    private SystemStats $stats;
    private AlertManager $alerts;
    private LogManager $logs;
    private array $thresholds;

    public function checkResources(): void {
        try {
            $usage = $this->stats->current();
            
            $this->checkCpuUsage($usage['cpu']);
            $this->checkMemoryUsage($usage['memory']);
            $this->checkDiskUsage($usage['disk']);
            
            $this->logs->info('Resource check complete', [
                'usage' => $usage
            ]);
            
        } catch (\Throwable $e) {
            $this->handleResourceCheckFailure($e);
        }
    }

    private function checkCpuUsage(float $usage): void {
        if ($usage > $this->thresholds['cpu']['critical']) {
            $this->alerts->triggerCritical('cpu', $usage);
            $this->logs->critical("Critical CPU usage: $usage%");
        } elseif ($usage > $this->thresholds['cpu']['warning']) {
            $this->alerts->triggerWarning('cpu', $usage);
            $this->logs->warning("High CPU usage: $usage%");
        }
    }

    private function checkMemoryUsage(float $usage): void {
        if ($usage > $this->thresholds['memory']['critical']) {
            $this->alerts->triggerCritical('memory', $usage);
            $this->logs->critical("Critical memory usage: $usage%");
        } elseif ($usage > $this->thresholds['memory']['warning']) {
            $this->alerts->triggerWarning('memory', $usage);
            $this->logs->warning("High memory usage: $usage%");
        }
    }

    private function checkDiskUsage(float $usage): void {
        if ($usage > $this->thresholds['disk']['critical']) {
            $this->alerts->triggerCritical('disk', $usage);
            $this->logs->critical("Critical disk usage: $usage%");
        } elseif ($usage > $this->thresholds['disk']['warning']) {
            $this->alerts->triggerWarning('disk', $usage);
            $this->logs->warning("High disk usage: $usage%");
        }
    }

    private function handleResourceCheckFailure(\Throwable $e): void {
        $this->logs->critical('Resource check failure', [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        $this->alerts->triggerSystemFailure($e);
    }
}

interface AlertManager {
    public function triggerWarning(string $metric, $value): void;
    public function triggerCritical(string $metric, $value): void;
    public function triggerSystemFailure(\Throwable $e): void;
    public function triggerEmergencyProtocol(array $metrics): void;
    public function escalateToEmergency(): void;
}

interface LogManager {
    public function emergency(string $message, array $context = []): void;
    public function critical(string $message, array $context = []): void;
    public function warning(string $message, array $context = []): void;
    public function info(string $message, array $context = []): void;
    public function debug(string $message, array $context = []): void;
}

interface SystemStats {
    public function current(): array;
}

interface TimeService {
    public function now(): int;
}
