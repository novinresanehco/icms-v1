namespace App\Core\Monitoring;

class MonitoringService implements MonitoringInterface 
{
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private AuditLogger $audit;
    private PerformanceTracker $performance;
    private array $thresholds;

    public function startOperation(string $type): string
    {
        $operationId = $this->generateOperationId();
        
        $this->performance->startTracking($operationId);
        
        $this->audit->logOperationStart([
            'operation_id' => $operationId,
            'type' => $type,
            'timestamp' => microtime(true)
        ]);

        return $operationId;
    }

    public function track(string $operationId, callable $operation)
    {
        try {
            $result = $this->performance->measureOperation(
                $operationId, 
                $operation
            );

            $metrics = $this->performance->getMetrics($operationId);
            $this->validatePerformance($metrics);
            
            return $result;

        } catch (\Exception $e) {
            $this->handleFailure($operationId, $e);
            throw $e;
        }
    }

    private function validatePerformance(array $metrics): void
    {
        foreach ($metrics as $metric => $value) {
            if ($value > $this->thresholds[$metric]) {
                $this->alerts->performanceThresholdExceeded($metric, $value);
            }
        }
    }

    public function logMetrics(string $operationId, array $metrics): void
    {
        $this->metrics->record($operationId, $metrics);
        
        if ($this->detectAnomaly($metrics)) {
            $this->alerts->anomalyDetected($operationId, $metrics);
        }
    }

    public function endOperation(string $operationId): void
    {
        $metrics = $this->performance->stopTracking($operationId);
        
        $this->audit->logOperationEnd([
            'operation_id' => $operationId,
            'duration' => $metrics['duration'],
            'memory_peak' => $metrics['memory_peak'],
            'status' => 'completed'
        ]);
    }

    private function handleFailure(string $operationId, \Exception $e): void
    {
        $this->performance->stopTracking($operationId);
        
        $this->audit->logOperationFailure([
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->alerts->operationFailed($operationId, $e);
    }

    private function detectAnomaly(array $metrics): bool
    {
        return $this->metrics->analyzeAnomaly($metrics);
    }

    private function generateOperationId(): string
    {
        return uniqid('op_', true);
    }
}

class PerformanceTracker
{
    private array $operations = [];
    
    public function startTracking(string $operationId): void
    {
        $this->operations[$operationId] = [
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(true)
        ];
    }

    public function measureOperation(string $operationId, callable $operation)
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        $result = $operation();

        $this->operations[$operationId]['metrics'][] = [
            'duration' => microtime(true) - $startTime,
            'memory_used' => memory_get_usage(true) - $startMemory
        ];

        return $result;
    }

    public function stopTracking(string $operationId): array
    {
        $operation = $this->operations[$operationId];
        
        $metrics = [
            'duration' => microtime(true) - $operation['start_time'],
            'memory_peak' => memory_get_peak_usage(true) - $operation['start_memory']
        ];

        unset($this->operations[$operationId]);
        
        return $metrics;
    }

    public function getMetrics(string $operationId): array
    {
        return $this->operations[$operationId]['metrics'] ?? [];
    }
}

class MetricsCollector
{
    private MetricsStorage $storage;
    private AnomalyDetector $anomalyDetector;

    public function record(string $operationId, array $metrics): void
    {
        $this->storage->store($operationId, $this->normalizeMetrics($metrics));
    }

    public function analyzeAnomaly(array $metrics): bool
    {
        return $this->anomalyDetector->detect($metrics);
    }

    private function normalizeMetrics(array $metrics): array
    {
        return array_map(function($value) {
            return is_numeric($value) ? round($value, 4) : $value;
        }, $metrics);
    }
}

class AlertManager 
{
    private NotificationService $notifications;
    private AlertConfig $config;
    private AuditLogger $audit;

    public function performanceThresholdExceeded(string $metric, $value): void
    {
        $alert = [
            'type' => 'performance_threshold',
            'metric' => $metric,
            'value' => $value,
            'threshold' => $this->config->getThreshold($metric),
            'timestamp' => time()
        ];

        $this->notify($alert);
    }

    public function operationFailed(string $operationId, \Exception $e): void
    {
        $alert = [
            'type' => 'operation_failed',
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'timestamp' => time()
        ];

        $this->notify($alert, 'critical');
    }

    public function anomalyDetected(string $operationId, array $metrics): void
    {
        $alert = [
            'type' => 'anomaly_detected',
            'operation_id' => $operationId,
            'metrics' => $metrics,
            'timestamp' => time()
        ];

        $this->notify($alert, 'warning');
    }

    private function notify(array $alert, string $severity = 'info'): void
    {
        $this->notifications->send($alert, $this->config->getRecipients($severity));
        $this->audit->logAlert($alert);
    }
}
