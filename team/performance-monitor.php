<?php

namespace App\Core\Monitoring;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class PerformanceMonitor
{
    protected const METRICS_PREFIX = 'metrics:';
    protected const METRICS_RETENTION = 86400; // 24 hours

    /**
     * Records a performance metric
     *
     * @param string $metric
     * @param mixed $value
     * @param array $context
     */
    public function recordMetric(string $metric, $value, array $context = []): void
    {
        $metricData = [
            'value' => $value,
            'timestamp' => microtime(true),
            'context' => $context
        ];

        // Store in cache for quick access
        Cache::put(
            self::METRICS_PREFIX . $metric . ':' . time(),
            $metricData,
            self::METRICS_RETENTION
        );

        // Log for permanent storage
        Log::channel('performance')->info("Performance metric: {$metric}", $metricData);
    }

    /**
     * Records an error occurrence
     *
     * @param string $error
     * @param array $context
     */
    public function recordError(string $error, array $context = []): void
    {
        $errorData = [
            'timestamp' => microtime(true),
            'context' => $context
        ];

        // Log error
        Log::channel('performance')->error("Performance error: {$error}", $errorData);

        // Update error counter
        $errorKey = self::METRICS_PREFIX . 'errors:' . $error;
        Cache::increment($errorKey);
    }

    /**
     * Gets performance metrics for analysis
     *
     * @param string $metric
     * @param int $duration Seconds to look back
     * @return array
     */
    public function getMetrics(string $metric, int $duration = 3600): array
    {
        $metrics = [];
        $startTime = time() - $duration;

        // Collect metrics from cache
        for ($t = time(); $t >= $startTime; $t--) {
            $key = self::METRICS_PREFIX . $metric . ':' . $t;
            if ($data = Cache::get($key)) {
                $metrics[] = $data;
            }
        }

        return $metrics;
    }

    /**
     * Analyzes performance metrics
     *
     * @param string $metric
     * @param int $duration
     * @return array
     */
    public function analyzePerformance(string $metric, int $duration = 3600): array
    {
        $metrics = $this->getMetrics($metric, $duration);
        
        if (empty($metrics)) {
            return [
                'count' => 0,
                'avg' => 0,
                'min' => 0,
                'max' => 0,
                'p95' => 0
            ];
        }

        $values = array_column($metrics, 'value');
        sort($values);

        return [
            'count' => count($values),
            'avg' => array_sum($values) / count($values),
            'min' => min($values),
            'max' => max($values),
            'p95' => $values[(int) (count($values) * 0.95)]
        ];
    }
}
