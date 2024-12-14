<?php

namespace App\Core\Metrics;

class MetricsCollector
{
    private MetricsStorage $storage;
    private AlertManager $alertManager;
    private array $config;

    public function __construct(
        MetricsStorage $storage,
        AlertManager $alertManager,
        array $config
    ) {
        $this->storage = $storage;
        $this->alertManager = $alertManager;
        $this->config = $config;
    }

    public function recordRequest(Service $service, float $duration, bool $success): void
    {
        $timestamp = time();
        $bucket = $this->getCurrentBucket();

        $metrics = [
            'timestamp' => $timestamp,
            'service_id' => $service->getId(),
            'duration' => $duration,
            'success' => $success
        ];

        $this->storage->store($bucket, $metrics);

        if ($this->shouldCheckThresholds($service)) {
            $this->checkThresholds($service);
        }
    }

    public function getServiceMetrics(Service $service, int $timeRange = 3600): array
    {
        $endTime = time();
        $startTime = $endTime - $timeRange;
        
        $metrics = $this->storage->query([
            'service_id' => $service->getId(),
            'start_time' => $startTime,
            'end_time' => $endTime
        ]);

        return [
            'request_count' => count($metrics),
            'error_rate' => $this->calculateErrorRate($metrics),
            'average_duration' => $this->calculateAverageDuration($metrics),
            'percentiles' => $this->calculatePercentiles($metrics),
            'throughput' => $this->calculateThroughput($metrics, $timeRange)
        ];
    }

    public function recordNodeMetrics(Node $node, array $metrics): void
    {
        $timestamp = time();
        $bucket = $this->getCurrentBucket();

        $nodeMetrics = array_merge($metrics, [
            'timestamp' => $timestamp,
            'node_id' => $node->getId()
        ]);

        $this->storage->store($bucket, $nodeMetrics);

        if ($this->shouldCheckNodeHealth($node)) {
            $this->checkNodeHealth($node);
        }
    }

    protected function getCurrentBucket(): string
    {
        return date('Y-m-d-H-i');
    }

    protected function calculateErrorRate(array $metrics): float
    {
        if (empty($metrics)) return 0.0;

        $errorCount = count(array_filter($metrics, fn($m) => !$m['success']));
        return $errorCount / count($metrics);
    }

    protected function calculateAverageDuration(array $metrics): float
    {
        if (empty($metrics)) return 0.0;

        $total = array_sum(array_column($metrics, 'duration'));
        return $total / count($metrics);
    }

    protected function calculatePercentiles(array $metrics): array
    {
        if (empty($metrics)) {
            return [
                'p50' => 0,
                'p90' => 0,
                'p95' => 0,
                'p99' => 0
            ];
        }

        $durations = array_column($metrics, 'duration');
        sort($durations);
        $count = count($durations);

        return [
            'p50' => $durations[(int)($count * 0.5)],
            'p90' => $durations[(int)($count * 0.9)],
            'p95' => $durations[(int)($count * 0.95)],
            'p99' => $durations[(int)($count * 0.99)]
        ];
    }

    protected function calculateThroughput(array $metrics, int $timeRange): float
    {
        return count($metrics) / ($timeRange / 3600);
    }

    protected function shouldCheckThresholds(Service $service): bool
    {
        $lastCheck = $this->storage->getLastCheck($service->getId());
        return !$lastCheck || (time() - $lastCheck) >= $this->config['check_interval'];
    }

    protected function checkThresholds(Service $service): void
    {
        $metrics = $this->getServiceMetrics($service, 300);

        if ($metrics['error_rate'] > $this->config['error_threshold']) {
            $this->alertManager->triggerAlert(
                $service,
                AlertType::HIGH_ERROR_RATE,
                $metrics
            );
        }

        if ($metrics['average_duration'] > $this->config['latency_threshold']) {
            $this->alertManager->triggerAlert(
                $service,
                AlertType::HIGH_LATENCY,
                $metrics
            );
        }

        $this->storage->updateLastCheck($service->getId(), time());
    }

    protected function shouldCheckNodeHealth(Node $node): bool
    {
        $lastCheck = $this->storage->getLastNodeCheck($node->getId());
        return !$lastCheck || (time() - $lastCheck) >= $this->config['node_check_interval'];
    }

    protected function checkNodeHealth(Node $node): void
    {
        $metrics = $this->storage->getNodeMetrics($node->getId(), 300);
        
        if ($this->isNodeUnhealthy($metrics)) {
            $this->alertManager->triggerAlert(
                $node,
                AlertType::UNHEALTHY_NODE,
                $metrics
            );
        }

        $this->storage->updateLastNodeCheck($node->getId(), time());
    }

    protected function isNodeUnhealthy(array $metrics): bool
    {
        return $metrics['error_rate'] > $this->config['node_error_threshold'] ||
               $metrics['cpu_usage'] > $this->config['node_cpu_threshold'] ||
               $metrics['memory_usage'] > $this->config['node_memory_threshold'];
    }
}
