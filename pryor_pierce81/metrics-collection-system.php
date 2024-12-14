namespace App\Core\Metrics;

class MetricsCollector implements MetricsInterface
{
    private CacheManager $cache;
    private StorageService $storage;
    private SecurityManager $security;
    private EventDispatcher $events;
    
    private array $metrics = [];
    private array $thresholds;
    private float $startTime;

    public function __construct(
        CacheManager $cache,
        StorageService $storage,
        SecurityManager $security,
        EventDispatcher $events
    ) {
        $this->cache = $cache;
        $this->storage = $storage;
        $this->security = $security;
        $this->events = $events;
        $this->startTime = microtime(true);
        $this->loadThresholds();
    }

    public function increment(string $metric, int $value = 1): void
    {
        $key = $this->formatMetricKey($metric);
        
        $this->security->executeCriticalOperation(
            new MetricIncrementOperation(
                $key,
                $value,
                $this->cache
            ),
            SecurityContext::fromRequest()
        );

        $this->checkThreshold($key, $this->getMetricValue($key));
    }

    public function timing(string $metric, float $time): void
    {
        $key = $this->formatMetricKey($metric);
        
        $this->security->executeCriticalOperation(
            new MetricTimingOperation(
                $key,
                $time,
                $this->cache
            ),
            SecurityContext::fromRequest()
        );

        $this->checkPerformanceThreshold($key, $time);
    }

    public function gauge(string $metric, float $value): void
    {
        $key = $this->formatMetricKey($metric);
        
        $this->security->executeCriticalOperation(
            new MetricGaugeOperation(
                $key,
                $value,
                $this->cache
            ),
            SecurityContext::fromRequest()
        );

        $this->checkResourceThreshold($key, $value);
    }

    public function record(array $metrics): void
    {
        foreach ($metrics as $metric => $value) {
            $this->recordMetric($metric, $value);
        }
        
        $this->flushIfNeeded();
    }

    private function recordMetric(string $metric, $value): void
    {
        $key = $this->formatMetricKey($metric);
        $this->metrics[$key] = [
            'value' => $value,
            'timestamp' => microtime(true),
            'type' => $this->getMetricType($value)
        ];
    }

    private function getMetricType($value): string
    {
        return match(true) {
            is_int($value) => 'counter',
            is_float($value) => 'gauge',
            is_array($value) => 'histogram',
            default => 'counter'
        };
    }

    private function checkThreshold(string $metric, $value): void
    {
        if (isset($this->thresholds[$metric])) {
            $threshold = $this->thresholds[$metric];
            
            if ($value > $threshold['critical']) {
                $this->events->dispatch(
                    new CriticalThresholdExceededEvent($metric, $value)
                );
            } elseif ($value > $threshold['warning']) {
                $this->events->dispatch(
                    new WarningThresholdExceededEvent($metric, $value)
                );
            }
        }
    }

    private function checkPerformanceThreshold(string $metric, float $time): void
    {
        $threshold = config("metrics.performance.{$metric}", 1000);
        
        if ($time > $threshold) {
            $this->events->dispatch(
                new PerformanceThresholdExceededEvent($metric, $time)
            );
        }
    }

    private function checkResourceThreshold(string $metric, float $value): void
    {
        $threshold = config("metrics.resources.{$metric}", 90);
        
        if ($value > $threshold) {
            $this->events->dispatch(
                new ResourceThresholdExceededEvent($metric, $value)
            );
        }
    }

    private function flushIfNeeded(): void
    {
        if (count($this->metrics) >= 1000 || 
            microtime(true) - $this->startTime > 60) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if (empty($this->metrics)) {
            return;
        }

        $this->security->executeCriticalOperation(
            new MetricsPersistOperation(
                $this->metrics,
                $this->storage
            ),
            SecurityContext::fromRequest()
        );

        $this->metrics = [];
        $this->startTime = microtime(true);
    }

    private function formatMetricKey(string $metric): string
    {
        return sprintf(
            '%s:%s:%s',
            config('app.env'),
            config('app.name'),
            $metric
        );
    }

    private function loadThresholds(): void
    {
        $this->thresholds = $this->cache->remember(
            'metrics:thresholds',
            3600,
            fn() => config('metrics.thresholds', [])
        );
    }

    private function getMetricValue(string $key): int
    {
        return (int) $this->cache->get($key, 0);
    }

    public function getMetrics(array $filter = []): array
    {
        return $this->security->executeCriticalOperation(
            new MetricsRetrievalOperation(
                $filter,
                $this->storage
            ),
            SecurityContext::fromRequest()
        );
    }
}
