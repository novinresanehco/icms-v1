namespace App\Core\Metrics;

class MetricsCollector implements MetricsInterface
{
    private StorageManager $storage;
    private QueueManager $queue;
    private CacheManager $cache;
    private LogManager $logger;
    private MetricsConfig $config;
    private array $buffer = [];

    public function __construct(
        StorageManager $storage,
        QueueManager $queue,
        CacheManager $cache,
        LogManager $logger,
        MetricsConfig $config
    ) {
        $this->storage = $storage;
        $this->queue = $queue;
        $this->cache = $cache;
        $this->logger = $logger;
        $this->config = $config;
    }

    public function record(string $metric, $value, array $tags = []): void
    {
        $metricData = $this->createMetricData($metric, $value, $tags);
        
        try {
            if ($this->isHighPriorityMetric($metric)) {
                $this->processImmediately($metricData);
            } else {
                $this->bufferMetric($metricData);
            }

            $this->updateRealTimeStats($metric, $value);
            
        } catch (\Exception $e) {
            $this->handleMetricFailure($e, $metricData);
        }
    }

    public function increment(string $metric, array $tags = []): void
    {
        $this->record($metric, 1, $tags);
        $this->incrementCachedCounter($metric);
    }

    public function timing(string $metric, float $duration, array $tags = []): void
    {
        $this->record($metric, $duration, array_merge($tags, ['type' => 'timing']));
        $this->updateTimingStats($metric, $duration);
    }

    public function gauge(string $metric, float $value, array $tags = []): void
    {
        $this->record($metric, $value, array_merge($tags, ['type' => 'gauge']));
        $this->updateGaugeStats($metric, $value);
    }

    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        try {
            $this->processBatch($this->buffer);
            $this->buffer = [];
        } catch (\Exception $e) {
            $this->handleBatchFailure($e);
        }
    }

    private function createMetricData(string $metric, $value, array $tags): array
    {
        return [
            'metric' => $metric,
            'value' => $value,
            'tags' => $tags,
            'timestamp' => microtime(true),
            'hostname' => gethostname(),
            'environment' => $this->config->getEnvironment(),
            'version' => $this->config->getVersion()
        ];
    }

    private function isHighPriorityMetric(string $metric): bool
    {
        return in_array($metric, $this->config->getHighPriorityMetrics());
    }

    private function processImmediately(array $metricData): void
    {
        $this->queue->dispatch(
            new ProcessMetricJob($metricData),
            QueuePriority::HIGH
        );

        if ($this->config->hasAlertingEnabled() && 
            $this->shouldTriggerAlert($metricData)) {
            $this->triggerAlert($metricData);
        }
    }

    private function bufferMetric(array $metricData): void
    {
        $this->buffer[] = $metricData;

        if (count($this->buffer) >= $this->config->getBatchSize()) {
            $this->flush();
        }
    }

    private function processBatch(array $metrics): void
    {
        $this->queue->dispatch(
            new ProcessMetricBatchJob($metrics),
            QueuePriority::MEDIUM
        );
    }

    private function updateRealTimeStats(string $metric, $value): void
    {
        $key = "metrics:realtime:{$metric}";
        
        $stats = $this->cache->remember($key, function() {
            return [
                'count' => 0,
                'sum' => 0,
                'min' => PHP_FLOAT_MAX,
                'max' => PHP_FLOAT_MIN
            ];
        }, $this->config->getStatsWindow());

        $stats['count']++;
        $stats['sum'] += $value;
        $stats['min'] = min($stats['min'], $value);
        $stats['max'] = max($stats['max'], $value);

        $this->cache->put($key, $stats, $this->config->getStatsWindow());
    }

    private function incrementCachedCounter(string $metric): void
    {
        $key = "metrics:counter:{$metric}";
        $this->cache->increment($key, 1);
    }

    private function updateTimingStats(string $metric, float $duration): void
    {
        $key = "metrics:timing:{$metric}";
        
        $stats = $this->cache->remember($key, function() {
            return [
                'count' => 0,
                'sum' => 0,
                'sum_squares' => 0,
                'min' => PHP_FLOAT_MAX,
                'max' => PHP_FLOAT_MIN
            ];
        }, $this->config->getStatsWindow());

        $stats['count']++;
        $stats['sum'] += $duration;
        $stats['sum_squares'] += ($duration * $duration);
        $stats['min'] = min($stats['min'], $duration);
        $stats['max'] = max($stats['max'], $duration);

        $this->cache->put($key, $stats, $this->config->getStatsWindow());
    }

    private function updateGaugeStats(string $metric, float $value): void
    {
        $key = "metrics:gauge:{$metric}";
        $this->cache->put($key, $value, $this->config->getStatsWindow());
    }

    private function shouldTriggerAlert(array $metricData): bool
    {
        foreach ($this->config->getAlertRules() as $rule) {
            if ($rule->evaluate($metricData)) {
                return true;
            }
        }
        return false;
    }

    private function triggerAlert(array $metricData): void
    {
        $this->queue->dispatch(
            new MetricAlertJob($metricData),
            QueuePriority::HIGH
        );
    }

    private function handleMetricFailure(\Exception $e, array $metricData): void
    {
        $this->logger->error('Failed to process metric', [
            'exception' => $e->getMessage(),
            'metric' => $metricData,
            'trace' => $e->getTraceAsString()
        ]);

        if ($this->config->hasFailoverEnabled()) {
            $this->storage->storeFailedMetric($metricData);
        }
    }

    private function handleBatchFailure(\Exception $e): void
    {
        $this->logger->error('Failed to process metric batch', [
            'exception' => $e->getMessage(),
            'batch_size' => count($this->buffer),
            'trace' => $e->getTraceAsString()
        ]);

        if ($this->config->hasFailoverEnabled()) {
            $this->storage->storeFailedBatch($this->buffer);
        }
    }
}
