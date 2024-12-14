<?php

namespace App\Core\Monitoring;

use Illuminate\Support\Facades\{Log, Cache, Event};
use App\Core\Interfaces\{MonitorInterface, AlertInterface};
use App\Core\Exceptions\{MonitoringException, ThresholdException};

class MonitoringSystem implements MonitorInterface
{
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private PerformanceAnalyzer $analyzer;
    private StorageManager $storage;
    private array $config;
    private array $activeMonitors = [];

    public function __construct(
        MetricsCollector $metrics,
        AlertManager $alerts,
        PerformanceAnalyzer $analyzer,
        StorageManager $storage,
        array $config
    ) {
        $this->metrics = $metrics;
        $this->alerts = $alerts;
        $this->analyzer = $analyzer;
        $this->storage = $storage;
        $this->config = $config;
    }

    public function startOperation(string $operationId, array $context = []): void
    {
        $monitor = new OperationMonitor($operationId, $context);
        $this->activeMonitors[$operationId] = $monitor;

        $this->metrics->record('operation_start', [
            'operation_id' => $operationId,
            'context' => $context,
            'timestamp' => microtime(true)
        ]);
    }

    public function endOperation(string $operationId, string $status = 'success'): void
    {
        if (!isset($this->activeMonitors[$operationId])) {
            throw new MonitoringException('Operation not found');
        }

        $monitor = $this->activeMonitors[$operationId];
        $duration = $monitor->getDuration();

        $this->metrics->record('operation_end', [
            'operation_id' => $operationId,
            'status' => $status,
            'duration' => $duration,
            'metrics' => $monitor->getMetrics()
        ]);

        $this->validateOperationMetrics($operationId, $monitor);
        unset($this->activeMonitors[$operationId]);
    }

    public function trackMetric(string $name, $value, array $tags = []): void
    {
        try {
            $metric = [
                'name' => $name,
                'value' => $value,
                'tags' => $tags,
                'timestamp' => microtime(true)
            ];

            $this->metrics->record($name, $metric);
            $this->checkThresholds($name, $value, $tags);
            $this->updateAggregates($name, $value, $tags);

        } catch (\Exception $e) {
            $this->handleMetricFailure($name, $e);
        }
    }

    public function recordError(string $type, \Throwable $error, array $context = []): void
    {
        $errorData = [
            'type' => $type,
            'message' => $error->getMessage(),
            'code' => $error->getCode(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'trace' => $error->getTraceAsString(),
            'context' => $context,
            'timestamp' => microtime(true)
        ];

        $this->metrics->record('error', $errorData);
        $this->alerts->handleError($errorData);

        if ($this->isHighSeverityError($type)) {
            $this->triggerEmergencyProtocol($errorData);
        }
    }

    public function getSystemMetrics(): array
    {
        return [
            'cpu_usage' => $this->getCPUUsage(),
            'memory_usage' => $this->getMemoryUsage(),
            'disk_usage' => $this->getDiskUsage(),
            'active_operations' => count($this->activeMonitors),
            'error_rate' => $this->calculateErrorRate(),
            'response_times' => $this->getResponseTimes()
        ];
    }

    public function checkSystemHealth(): bool
    {
        $metrics = $this->getSystemMetrics();
        
        foreach ($this->config['health_checks'] as $check => $threshold) {
            if (!$this->validateMetric($metrics[$check], $threshold)) {
                $this->alerts->sendHealthAlert($check, $metrics[$check], $threshold);
                return false;
            }
        }
        
        return true;
    }

    protected function validateOperationMetrics(string $operationId, OperationMonitor $monitor): void
    {
        $metrics = $monitor->getMetrics();
        $thresholds = $this->config['operation_thresholds'];

        foreach ($metrics as $name => $value) {
            if (isset($thresholds[$name]) && $value > $thresholds[$name]) {
                $this->handleThresholdViolation($operationId, $name, $value, $thresholds[$name]);
            }
        }
    }

    protected function checkThresholds(string $name, $value, array $tags): void
    {
        $thresholds = $this->config['metric_thresholds'][$name] ?? null;
        
        if ($thresholds && !$this->validateMetric($value, $thresholds)) {
            $this->handleThresholdViolation($name, $value, $thresholds, $tags);
        }
    }

    protected function updateAggregates(string $name, $value, array $tags): void
    {
        $key = "metrics:{$name}:" . md5(serialize($tags));
        
        Cache::tags(['metrics', $name])->remember($key, $this->config['aggregate_ttl'], function() {
            return [
                'count' => 0,
                'sum' => 0,
                'min' => PHP_FLOAT_MAX,
                'max' => PHP_FLOAT_MIN
            ];
        });

        Cache::tags(['metrics', $name])->increment("{$key}:count");
        Cache::tags(['metrics', $name])->increment("{$key}:sum", $value);
        Cache::tags(['metrics', $name])->put("{$key}:min", min(Cache::get("{$key}:min"), $value));
        Cache::tags(['metrics', $name])->put("{$key}:max", max(Cache::get("{$key}:max"), $value));
    }

    protected function handleThresholdViolation(string $name, $value, $threshold, array $context = []): void
    {
        $violation = [
            'metric' => $name,
            'value' => $value,
            'threshold' => $threshold,
            'context' => $context,
            'timestamp' => microtime(true)
        ];

        $this->metrics->record('threshold_violation', $violation);
        $this->alerts->sendThresholdAlert($violation);

        if ($this->isCriticalViolation($name, $value, $threshold)) {
            $this->triggerEmergencyProtocol($violation);
        }
    }

    protected function handleMetricFailure(string $name, \Exception $e): void
    {
        Log::error('Metric recording failed', [
            'metric' => $name,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    protected function validateMetric($value, $threshold): bool
    {
        if (is_array($threshold)) {
            return $value >= $threshold['min'] && $value <= $threshold['max'];
        }
        return $value <= $threshold;
    }

    protected function isHighSeverityError(string $type): bool
    {
        return in_array($type, $this->config['high_severity_errors'] ?? []);
    }

    protected function isCriticalViolation(string $name, $value, $threshold): bool
    {
        return in_array($name, $this->config['critical_metrics'] ?? []) &&
               $value > $threshold * $this->config['critical_multiplier'];
    }

    protected function triggerEmergencyProtocol(array $data): void
    {
        Event::dispatch(new EmergencyProtocolTriggered($data));
        
        if ($this->config['emergency_shutdown_enabled']) {
            $this->initiateEmergencyShutdown($data);
        }
    }

    protected function initiateEmergencyShutdown(array $context): void
    {
        Log::critical('Emergency shutdown initiated', $context);
        
        foreach ($this->activeMonitors as $monitor) {
            $monitor->markAsEmergencyStopped();
        }
        
        Cache::tags(['system', 'emergency'])->flush();
        Event::dispatch(new SystemEmergencyShutdown($context));
    }
}
