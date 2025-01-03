<?php

namespace App\Core\Monitoring;

class MetricsCollector implements MetricsInterface
{
    private MetricsStorage $storage;
    private AlertSystem $alerts;
    private LogManager $logs;
    private array $config;

    public function __construct(
        MetricsStorage $storage,
        AlertSystem $alerts,
        LogManager $logs,
        array $config
    ) {
        $this->storage = $storage;
        $this->alerts = $alerts;
        $this->logs = $logs;
        $this->config = $config;
    }

    public function initializeMetrics(string $id, array $initialData): void
    {
        $metrics = [
            'id' => $id,
            'start_time' => microtime(true),
            'initial_state' => $initialData,
            'checkpoints' => [],
            'alerts' => [],
            'status' => 'active'
        ];

        $this->storage->store($id, $metrics);
    }

    public function trackOperation(string $id, callable $operation): mixed
    {
        $startTime = microtime(true);
        $memoryBefore = memory_get_usage(true);

        try {
            $result = $operation();

            $this->recordCheckpoint($id, [
                'type' => 'operation_complete',
                'duration' => microtime(true) - $startTime,
                'memory_delta' => memory_get_usage(true) - $memoryBefore,
                'status' => 'success'
            ]);

            return $result;

        } catch (\Throwable $e) {
            $this->recordCheckpoint($id, [
                'type' => 'operation_failed',
                'duration' => microtime(true) - $startTime,
                'memory_delta' => memory_get_usage(true) - $memoryBefore,
                'status' => 'failed',
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    public function recordMetrics(string $id, array $metrics): void
    {
        $currentMetrics = $this->storage->get($id);
        
        if (!$currentMetrics) {
            throw new MetricsException("No metrics found for ID: {$id}");
        }

        $this->validateMetrics($metrics);
        $this->checkThresholds($id, $metrics);

        $currentMetrics['checkpoints'][] = [
            'timestamp' => microtime(true),
            'metrics' => $metrics
        ];

        $this->storage->update($id, $currentMetrics);
    }

    public function recordFailure(string $id, array $failureData): void
    {
        $metrics = $this->storage->get($id);
        
        if (!$metrics) {
            throw new MetricsException("No metrics found for ID: {$id}");
        }

        $metrics['status'] = 'failed';
        $metrics['failure'] = [
            'timestamp' => microtime(true),
            'data' => $failureData
        ];

        $this->storage->update($id, $metrics);
        $this->alerts->criticalMetricFailure($id, $failureData);
    }

    public function finalizeMetrics(string $id, array $finalData): void
    {
        $metrics = $this->storage->get($id);
        
        if (!$metrics) {
            throw new MetricsException("No metrics found for ID: {$id}");
        }

        $metrics['end_time'] = microtime(true);
        $metrics['final_state'] = $finalData;
        $metrics['duration'] = $metrics['end_time'] - $metrics['start_time'];
        $metrics['status'] = 'completed';

        $this->analyzeMetrics($id, $metrics);
        $this->storage->update($id, $metrics);
    }

    protected function validateMetrics(array $metrics): void
    {
        $requiredFields = ['timestamp', 'type', 'values'];
        
        foreach ($requiredFields as $field) {
            if (!isset($metrics[$field])) {
                throw new MetricsException("Missing required field: {$field}");
            }
        }
    }

    protected function checkThresholds(string $id, array $metrics): void
    {
        foreach ($metrics as $key => $value) {
            if (isset($this->config['thresholds'][$key])) {
                $threshold = $this->config['thresholds'][$key];
                
                if ($value > $threshold) {
                    $this->alerts->thresholdExceeded($id, $key, $value, $threshold);
                }
            }
        }
    }

    protected function analyzeMetrics(string $id, array $metrics): void
    {
        $analysis = [
            'duration' => $metrics['duration'],
            'memory_peak' => memory_get_peak_usage(true),
            'checkpoint_count' => count($metrics['checkpoints']),
            'alert_count' => count($metrics['alerts'])
        ];

        if ($analysis['duration'] > $this->config['critical_duration']) {
            $this->alerts->performanceCritical($id, $analysis);
        }

        $this->logs->info('Metrics analysis complete', [
            'id' => $id,
            'analysis' => $analysis
        ]);
    }

    protected function recordCheckpoint(string $id, array $data): void
    {
        $metrics = $this->storage->get($id);
        
        if (!$metrics) {
            throw new MetricsException("No metrics found for ID: {$id}");
        }

        $metrics['checkpoints'][] = [
            'timestamp' => microtime(true),
            'data' => $data
        ];

        $this->storage->update($id, $metrics);
    }
}

interface MetricsInterface
{
    public function initializeMetrics(string $id, array $initialData): void;
    public function trackOperation(string $id, callable $operation): mixed;
    public function recordMetrics(string $id, array $metrics): void;
    public function recordFailure(string $id, array $failureData): void;
    public function finalizeMetrics(string $id, array $finalData): void;
}
