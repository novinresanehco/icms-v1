namespace App\Core\Monitoring;

class MetricsCollector implements MetricsInterface 
{
    private MetricsStore $store;
    private AlertManager $alerts;
    private SecurityConfig $config;
    private LogManager $logger;

    private array $thresholds;
    private array $aggregates = [];
    private array $realTimeMetrics = [];

    public function __construct(
        MetricsStore $store,
        AlertManager $alerts,
        SecurityConfig $config,
        LogManager $logger
    ) {
        $this->store = $store;
        $this->alerts = $alerts;
        $this->logger = $logger;
        $this->config = $config;
        $this->thresholds = $config->get('metrics.thresholds');
    }

    public function recordOperation(string $operation, float $duration, array $context = []): void
    {
        $timestamp = microtime(true);
        
        try {
            $metric = [
                'operation' => $operation,
                'duration' => $duration,
                'timestamp' => $timestamp,
                'context' => $context,
                'resource_usage' => $this->captureResourceUsage(),
                'sequence_id' => $this->generateSequenceId()
            ];

            // Store metric
            $this->store->record('operations', $metric);

            // Update real-time aggregates
            $this->updateRealTimeMetrics($operation, $duration);

            // Check thresholds
            $this->checkOperationThresholds($operation, $duration, $context);

            // Update operation statistics
            $this->updateOperationStats($operation, $duration);

        } catch (\Exception $e) {
            $this->handleMetricError('operation_record', $e, $context);
        }
    }

    public function recordSecurityEvent(SecurityEvent $event): void
    {
        try {
            $metric = [
                'type' => $event->getType(),
                'severity' => $event->getSeverity(),
                'timestamp' => microtime(true),
                'context' => $event->getContext(),
                'source' => $event->getSource(),
                'sequence_id' => $this->generateSequenceId()
            ];

            // Store security metric
            $this->store->record('security_events', $metric);

            // Update security statistics
            $this->updateSecurityStats($event);

            // Process critical events
            if ($event->isCritical()) {
                $this->processCriticalEvent($event);
            }

            // Update threat metrics
            $this->updateThreatMetrics($event);

        } catch (\Exception $e) {
            $this->handleMetricError('security_event', $e, $event->getContext());
        }
    }

    public function recordPerformanceMetric(string $metric, float $value, array $tags = []): void
    {
        try {
            $data = [
                'metric' => $metric,
                'value' => $value,
                'timestamp' => microtime(true),
                'tags' => $tags,
                'sequence_id' => $this->generateSequenceId()
            ];

            // Store performance metric
            $this->store->record('performance', $data);

            // Update performance aggregates
            $this->updatePerformanceAggregates($metric, $value);

            // Check performance thresholds
            $this->checkPerformanceThresholds($metric, $value, $tags);

            // Update trending data
            $this->updatePerformanceTrends($metric, $value);

        } catch (\Exception $e) {
            $this->handleMetricError('performance_metric', $e, ['metric' => $metric]);
        }
    }

    public function recordResourceUsage(array $metrics): void
    {
        try {
            $timestamp = microtime(true);
            
            $data = array_merge($metrics, [
                'timestamp' => $timestamp,
                'sequence_id' => $this->generateSequenceId()
            ]);

            // Store resource metrics
            $this->store->record('resources', $data);

            // Check resource thresholds
            foreach ($metrics as $resource => $usage) {
                $this->checkResourceThreshold($resource, $usage);
            }

            // Update resource trends
            $this->updateResourceTrends($metrics);

        } catch (\Exception $e) {
            $this->handleMetricError('resource_usage', $e, $metrics);
        }
    }

    public function getMetricsSummary(string $type, array $filters = []): array
    {
        try {
            return [
                'real_time' => $this->getRealTimeMetrics($type),
                'aggregates' => $this->getAggregateMetrics($type, $filters),
                'trends' => $this->getTrendAnalysis($type, $filters),
                'thresholds' => $this->getThresholdStatus($type)
            ];
        } catch (\Exception $e) {
            $this->handleMetricError('metrics_summary', $e, ['type' => $type]);
            return [];
        }
    }

    protected function checkOperationThresholds(string $operation, float $duration, array $context): void
    {
        if (isset($this->thresholds['operations'][$operation])) {
            $threshold = $this->thresholds['operations'][$operation];
            
            if ($duration > $threshold) {
                $this->alerts->triggerOperationAlert($operation, $duration, $context);
            }
        }
    }

    protected function updateRealTimeMetrics(string $operation, float $duration): void
    {
        if (!isset($this->realTimeMetrics[$operation])) {
            $this->realTimeMetrics[$operation] = [
                'count' => 0,
                'total_duration' => 0,
                'max_duration' => 0,
                'min_duration' => PHP_FLOAT_MAX
            ];
        }

        $metrics = &$this->realTimeMetrics[$operation];
        $metrics['count']++;
        $metrics['total_duration'] += $duration;
        $metrics['max_duration'] = max($metrics['max_duration'], $duration);
        $metrics['min_duration'] = min($metrics['min_duration'], $duration);
    }

    protected function generateSequenceId(): string
    {
        return uniqid('metric_', true);
    }

    protected function captureResourceUsage(): array
    {
        return [
            'memory' => memory_get_usage(true),
            'cpu' => sys_getloadavg()[0],
            'disk_io' => $this->getDiskIOStats()
        ];
    }

    protected function handleMetricError(string $context, \Exception $e, array $data = []): void
    {
        $this->logger->error('Metrics collection failed', [
            'context' => $context,
            'error' => $e->getMessage(),
            'data' => $data
        ]);
    }
}
