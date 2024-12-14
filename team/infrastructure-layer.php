namespace App\Core\Infrastructure;

class SystemMonitor implements MonitoringInterface
{
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private PerformanceAnalyzer $analyzer;
    private AuditLogger $audit;

    public function __construct(
        MetricsCollector $metrics,
        AlertManager $alerts,
        PerformanceAnalyzer $analyzer,
        AuditLogger $audit
    ) {
        $this->metrics = $metrics;
        $this->alerts = $alerts;
        $this->analyzer = $analyzer;
        $this->audit = $audit;
    }

    public function trackOperation(string $operation, callable $callback): mixed
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        try {
            $result = $callback();

            $this->recordMetrics($operation, [
                'duration' => microtime(true) - $startTime,
                'memory' => memory_get_usage() - $startMemory,
                'status' => 'success'
            ]);

            return $result;

        } catch (\Exception $e) {
            $this->recordFailure($operation, $e);
            throw $e;
        }
    }

    private function recordMetrics(string $operation, array $metrics): void
    {
        $this->metrics->record($operation, $metrics);
        
        if ($this->analyzer->isPerformanceDegraded($metrics)) {
            $this->alerts->notify(
                new PerformanceAlert($operation, $metrics)
            );
        }
    }

    private function recordFailure(string $operation, \Exception $e): void
    {
        $this->metrics->recordFailure($operation);
        $this->alerts->notifyFailure($operation, $e);
        $this->audit->logFailure($operation, $e);
    }
}

class CacheManager implements CacheInterface
{
    private Cache $cache;
    private MetricsCollector $metrics;
    private string $prefix;

    public function get(string $key, callable $callback): mixed
    {
        $cacheKey = $this->prefix . $key;
        
        $startTime = microtime(true);
        
        if ($cached = $this->cache->get($cacheKey)) {
            $this->recordHit($key, microtime(true) - $startTime);
            return $cached;
        }

        $value = $callback();
        
        $this->cache->put($cacheKey, $value);
        $this->recordMiss($key, microtime(true) - $startTime);
        
        return $value;
    }

    private function recordHit(string $key, float $duration): void
    {
        $this->metrics->record('cache.hit', [
            'key' => $key,
            'duration' => $duration
        ]);
    }

    private function recordMiss(string $key, float $duration): void
    {
        $this->metrics->record('cache.miss', [
            'key' => $key,
            'duration' => $duration
        ]);
    }
}

class PerformanceAnalyzer
{
    private MetricsRepository $metrics;
    private array $thresholds;

    public function isPerformanceDegraded(array $metrics): bool
    {
        return 
            $metrics['duration'] > $this->thresholds['max_duration'] ||
            $metrics['memory'] > $this->thresholds['max_memory'];
    }

    public function analyzeSystemHealth(): SystemHealth
    {
        $metrics = $this->metrics->getRecentMetrics();
        
        return new SystemHealth(
            $this->calculateResponseTimes($metrics),
            $this->calculateErrorRates($metrics),
            $this->calculateResourceUsage($metrics)
        );
    }

    private function calculateResponseTimes(array $metrics): array
    {
        return [
            'avg' => $this->average($metrics, 'duration'),
            'p95' => $this->percentile($metrics, 'duration', 95),
            'p99' => $this->percentile($metrics, 'duration', 99)
        ];
    }

    private function calculateErrorRates(array $metrics): float
    {
        $total = count($metrics);
        $failures = count(array_filter($metrics, fn($m) => $m['status'] === 'failure'));
        
        return $total > 0 ? ($failures / $total) * 100 : 0;
    }

    private function calculateResourceUsage(array $metrics): array
    {
        return [
            'memory' => $this->average($metrics, 'memory'),
            'cpu' => sys_getloadavg()[0]
        ];
    }
}
