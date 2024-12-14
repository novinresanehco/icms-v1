```php
namespace App\Core\Metrics;

class CriticalMetricsCollector {
    private MetricsStorage $storage;
    private AlertSystem $alerts;
    private ThresholdManager $thresholds;

    public function collectMetrics(string $operation, array $context): void {
        try {
            $metrics = $this->gatherMetrics($operation, $context);
            $this->validateMetrics($metrics);
            $this->storeMetrics($metrics);
            $this->checkThresholds($metrics);
        } catch (\Exception $e) {
            $this->handleMetricsFailure($e, $operation);
        }
    }

    private function gatherMetrics(string $operation, array $context): array {
        return [
            'operation' => $operation,
            'execution_time' => microtime(true) - ($context['start_time'] ?? 0),
            'memory_usage' => memory_get_usage(true),
            'cpu_usage' => sys_getloadavg()[0],
            'timestamp' => microtime(true),
            'server_id' => gethostname(),
            'context' => $context
        ];
    }

    private function validateMetrics(array $metrics): void {
        foreach ($metrics as $key => $value) {
            if (!$this->isValidMetric($key, $value)) {
                throw new MetricsException("Invalid metric: $key");
            }
        }
    }

    private function checkThresholds(array $metrics): void {
        $thresholds = $this->thresholds->getThresholds();
        
        foreach ($thresholds as $metric => $threshold) {
            if (isset($metrics[$metric]) && $metrics[$metric] > $threshold) {
                $this->alerts->notifyThresholdExceeded($metric, $metrics[$metric]);
            }
        }
    }
}

interface MetricsStorage {
    public function store(string $category, array $metrics): void;
    public function retrieve(string $category, array $filters = []): array;
}

class MetricsException extends \Exception {}
```
