<?php

namespace App\Core\Security\Monitoring;

use App\Core\Security\Models\{MetricData, MonitoringContext};
use Illuminate\Support\Facades\{Cache, Redis, Log};

class SecurityMetricsSystem
{
    private MetricsStore $store;
    private AlertManager $alerts;
    private SecurityConfig $config;
    private AuditLogger $logger;

    public function __construct(
        MetricsStore $store,
        AlertManager $alerts,
        SecurityConfig $config,
        AuditLogger $logger
    ) {
        $this->store = $store;
        $this->alerts = $alerts;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function trackMetric(string $name, $value, array $tags = []): void
    {
        try {
            $metric = new MetricData([
                'name' => $name,
                'value' => $value,
                'tags' => $tags,
                'timestamp' => microtime(true)
            ]);

            $this->storeMetric($metric);
            $this->checkThresholds($metric);
            $this->updateAggregates($metric);

        } catch (\Exception $e) {
            $this->handleMetricFailure($name, $e);
        }
    }

    public function startOperation(string $operation, array $context = []): string
    {
        $id = $this->generateOperationId();
        
        Redis::hset(
            "operation:{$id}",
            [
                'operation' => $operation,
                'start_time' => microtime(true),
                'context' => serialize($context)
            ]
        );
        
        return $id;
    }

    public function endOperation(string $operationId, array $result = []): void
    {
        $startData = Redis::hgetall("operation:{$operationId}");
        
        if (!$startData) {
            throw new MonitoringException('Operation not found');
        }

        $duration = microtime(true) - (float)$startData['start_time'];
        
        $this->trackMetric('operation_duration', $duration, [
            'operation' => $startData['operation'],
            'result' => isset($result['status']) ? $result['status'] : 'unknown'
        ]);

        $this->storeOperationResult($operationId, $result, $duration);
    }

    public function monitorResource(string $resource, callable $check): void
    {
        $startTime = microtime(true);
        
        try {
            $result = $check();
            
            $this->trackMetric("resource_{$resource}", $result, [
                'type' => 'health_check',
                'duration' => microtime(true) - $startTime
            ]);

        } catch (\Exception $e) {
            $this->handleResourceFailure($resource, $e);
        }
    }

    private function storeMetric(MetricData $metric): void
    {
        // Real-time metrics
        Redis::zadd(
            "metrics:{$metric->name}:real_time",
            $metric->timestamp,
            serialize($metric)
        );

        // Persistent storage
        $this->store->save($metric);

        // Update counters
        if ($this->isCounterMetric($metric->name)) {
            Redis::hincrby(
                "metrics:counters",
                $metric->name,
                (int)$metric->value
            );
        }
    }

    private function checkThresholds(MetricData $metric): void
    {
        $thresholds = $this->config->getMetricThresholds($metric->name);
        
        foreach ($thresholds as $threshold) {
            if ($this->isThresholdViolated($metric, $threshold)) {
                $this->handleThresholdViolation($metric, $threshold);
            }
        }
    }

    private function updateAggregates(MetricData $metric): void
    {
        $aggregates = [
            'sum' => Redis::hincrbyfloat(
                "metrics:{$metric->name}:sum",
                $this->getAggregateKey($metric),
                $metric->value
            ),
            'count' => Redis::hincrby(
                "metrics:{$metric->name}:count",
                $this->getAggregateKey($metric),
                1
            )
        ];

        $this->updateAggregateStats($metric, $aggregates);
    }

    private function storeOperationResult(
        string $operationId,
        array $result,
        float $duration
    ): void {
        $data = [
            'result' => $result,
            'duration' => $duration,
            'end_time' => microtime(true)
        ];

        Redis::hmset("operation_result:{$operationId}", $data);
        Redis::expire("operation_result:{$operationId}", 3600);
        
        $this->updateOperationStats($operationId, $data);
    }

    private function handleThresholdViolation(
        MetricData $metric,
        array $threshold
    ): void {
        $this->alerts->trigger(
            $threshold['level'],
            "Metric {$metric->name} threshold violated",
            [
                'metric' => $metric->name,
                'value' => $metric->value,
                'threshold' => $threshold['value'],
                'tags' => $metric->tags
            ]
        );

        $this->logger->logSecurityEvent(
            'metric_threshold_violation',
            [
                'metric' => $metric->name,
                'value' => $metric->value,
                'threshold' => $threshold
            ]
        );
    }

    private function handleResourceFailure(string $resource, \Exception $e): void
    {
        $this->alerts->trigger(
            'critical',
            "Resource {$resource} check failed",
            [
                'resource' => $resource,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]
        );

        $this->trackMetric("resource_{$resource}_failure", 1, [
            'error' => $e->getMessage()
        ]);
    }

    private function generateOperationId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function getAggregateKey(MetricData $metric): string
    {
        return implode(':', array_merge(
            [date('Y-m-d-H')],
            $metric->tags
        ));
    }

    private function isCounterMetric(string $name): bool
    {
        return in_array($name, $this->config->getCounterMetrics());
    }
}
