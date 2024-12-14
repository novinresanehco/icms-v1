<?php

namespace App\Core\Services;

use App\Core\Interfaces\MonitoringServiceInterface;
use Psr\Log\LoggerInterface;
use Illuminate\Support\Facades\Cache;
use App\Core\Exceptions\MonitoringException;

class MonitoringService implements MonitoringServiceInterface
{
    private LoggerInterface $logger;
    private array $config;
    private array $metrics = [];

    private const CACHE_PREFIX = 'monitor:';
    private const ALERT_THRESHOLD = 0.8;
    private const METRIC_TTL = 3600;
    private const BATCH_SIZE = 100;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->config = config('monitoring');
    }

    public function trackMetric(string $name, float $value, array $tags = []): void
    {
        try {
            $metric = [
                'value' => $value,
                'tags' => $tags,
                'timestamp' => microtime(true)
            ];

            $key = $this->getMetricKey($name);
            $this->metrics[$key][] = $metric;

            if (count($this->metrics[$key]) >= self::BATCH_SIZE) {
                $this->flushMetrics($key);
            }

            $this->checkThresholds($name, $value, $tags);
        } catch (\Exception $e) {
            $this->handleError('Failed to track metric', $e);
        }
    }

    public function trackPerformance(string $operation, float $duration, array $context = []): void
    {
        $this->trackMetric("performance.$operation", $duration, [
            'operation' => $operation,
            'context' => json_encode($context)
        ]);

        if ($duration > $this->config['thresholds'][$operation] ?? 1000) {
            $this->alert('performance_degradation', [
                'operation' => $operation,
                'duration' => $duration,
                'threshold' => $this->config['thresholds'][$operation] ?? 1000,
                'context' => $context
            ]);
        }
    }

    public function trackResourceUsage(): void
    {
        $memory = memory_get_usage(true);
        $cpu = sys_getloadavg()[0];
        
        $this->trackMetric('system.memory', $memory);
        $this->trackMetric('system.cpu', $cpu);

        if ($memory > $this->config['memory_limit'] || $cpu > $this->config['cpu_limit']) {
            $this->alert('resource_exhaustion', [
                'memory' => $memory,
                'cpu' => $cpu,
                'memory_limit' => $this->config['memory_limit'],
                'cpu_limit' => $this->config['cpu_limit']
            ]);
        }
    }

    public function trackError(\Throwable $error, array $context = []): void
    {
        $this->trackMetric('errors', 1, [
            'type' => get_class($error),
            'code' => $error->getCode(),
            'file' => $error->getFile(),
            'line' => $error->getLine()
        ]);

        $this->logger->error($error->getMessage(), [
            'exception' => $error,
            'context' => $context
        ]);
    }

    public function getMetrics(string $name, array $criteria = []): array
    {
        try {
            $key = $this->getMetricKey($name);
            $metrics = Cache::get($key, []);

            if (!empty($criteria)) {
                $metrics = array_filter($metrics, function($metric) use ($criteria) {
                    foreach ($criteria as $k => $v) {
                        if (!isset($metric['tags'][$k]) || $metric['tags'][$k] !== $v) {
                            return false;
                        }
                    }
                    return true;
                });
            }

            return array_values($metrics);
        } catch (\Exception $e) {
            $this->handleError('Failed to retrieve metrics', $e);
        }
    }

    public function flushMetrics(?string $key = null): void
    {
        try {
            if ($key) {
                if (isset($this->metrics[$key])) {
                    $this->persistMetrics($key, $this->metrics[$key]);
                    unset($this->metrics[$key]);
                }
            } else {
                foreach ($this->metrics as $k => $metrics) {
                    $this->persistMetrics($k, $metrics);
                }
                $this->metrics = [];
            }
        } catch (\Exception $e) {
            $this->handleError('Failed to flush metrics', $e);
        }
    }

    private function persistMetrics(string $key, array $metrics): void
    {
        $existing = Cache::get($key, []);
        $combined = array_merge($existing, $metrics);

        // Keep only recent metrics
        $cutoff = microtime(true) - self::METRIC_TTL;
        $filtered = array_filter($combined, fn($m) => $m['timestamp'] > $cutoff);

        Cache::put($key, $filtered, self::METRIC_TTL);
    }

    private function getMetricKey(string $name): string
    {
        return self::CACHE_PREFIX . $name;
    }

    private function checkThresholds(string $name, float $value, array $tags): void
    {
        if (!isset($this->config['thresholds'][$name])) {
            return;
        }

        $threshold = $this->config['thresholds'][$name];
        
        if ($value >= $threshold * self::ALERT_THRESHOLD) {
            $this->alert('threshold_approaching', [
                'metric' => $name,
                'value' => $value,
                'threshold' => $threshold,
                'tags' => $tags
            ]);
        }

        if ($value >= $threshold) {
            $this->alert('threshold_exceeded', [
                'metric' => $name,
                'value' => $value,
                'threshold' => $threshold,
                'tags' => $tags
            ]);
        }
    }

    private function alert(string $type, array $data): void
    {
        try {
            event(new MonitoringAlert($type, $data));
            
            $this->logger->warning("Monitoring alert: $type", $data);

            if ($this->config['alert_notifications']) {
                // Send notifications based on configuration
                // Implementation depends on notification system
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to send alert', [
                'type' => $type,
                'data' => $data,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function handleError(string $message, \Exception $e): void
    {
        $this->logger->error($message, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        throw new MonitoringException($message, 0, $e);
    }
}
