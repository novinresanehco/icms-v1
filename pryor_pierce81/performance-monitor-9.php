<?php

namespace App\Core\Performance;

class PerformanceMonitor
{
    private array $metrics = [];
    private array $thresholds = [];
    private array $alerts = [];
    private MetricsRepository $repository;
    private AlertManager $alertManager;

    public function trackOperation(string $operation, callable $callback)
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        try {
            $result = $callback();
            $success = true;
        } catch (\Throwable $e) {
            $success = false;
            throw $e;
        } finally {
            $this->recordMetrics($operation, [
                'duration' => microtime(true) - $startTime,
                'memory' => memory_get_usage() - $startMemory,
                'success' => $success,
                'timestamp' => time()
            ]);
        }

        return $result;
    }

    public function recordMetrics(string $operation, array $metrics): void
    {
        $this->metrics[$operation][] = $metrics;
        $this->checkThresholds($operation, $metrics);
        $this->repository->store($operation, $metrics);
    }

    public function setThreshold(string $operation, string $metric, float $threshold): void
    {
        $this->thresholds[$operation][$metric] = $threshold;
    }

    private function checkThresholds(string $operation, array $metrics): void
    {
        if (!isset($this->thresholds[$operation])) {
            return;
        }

        foreach ($this->thresholds[$operation] as $metric => $threshold) {
            if (isset($metrics[$metric]) && $metrics[$metric] > $threshold) {
                $this->alertManager->triggerAlert($operation, $metric, $metrics[$metric], $threshold);
            }
        }
    }

    public function getMetrics(string $operation, int $duration = 3600): array
    {
        return $this->repository->getMetrics($operation, time() - $duration);
    }

    public function analyzePerformance(string $operation): array
    {
        $metrics = $this->getMetrics($operation);
        
        return [
            'average_duration' => $this->calculateAverage($metrics, 'duration'),
            'max_duration' => $this->calculateMax($metrics, 'duration'),
            'memory_usage' => $this->calculateAverage($metrics, 'memory'),
            'success_rate' => $this->calculateSuccessRate($metrics),
            'trends' => $this->analyzeTrends($metrics)
        ];
    }

    private function calculateAverage(array $metrics, string $key): float
    {
        $values = array_column($metrics, $key);
        return !empty($values) ? array_sum($values) / count($values) : 0;
    }

    private function calculateMax(array $metrics, string $key): float
    {
        $values = array_column($metrics, $key);
        return !empty($values) ? max($values) : 0;
    }

    private function calculateSuccessRate(array $metrics): float
    {
        $successful = count(array_filter($metrics, fn($m) => $m['success']));
        return count($metrics) > 0 ? ($successful / count($metrics)) * 100 : 0;
    }

    private function analyzeTrends(array $metrics): array
    {
        $sorted = array_values($metrics);
        usort($sorted, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);

        return [
            'duration_trend' => $this->calculateTrend(array_column($sorted, 'duration')),
            'memory_trend' => $this->calculateTrend(array_column($sorted, 'memory'))
        ];
    }

    private function calculateTrend(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0;
        }

        $x = range(0, $n - 1);
        $x_mean = array_sum($x) / $n;
        $y_mean = array_sum($values) / $n;

        $numerator = 0;
        $denominator = 0;

        for ($i = 0; $i < $n; $i++) {
            $numerator += ($x[$i] - $x_mean) * ($values[$i] - $y_mean);
            $denominator += pow($x[$i] - $x_mean, 2);
        }

        return $denominator != 0 ? $numerator / $denominator : 0;
    }
}

class MetricsRepository
{
    private $connection;
    private string $table = 'performance_metrics';

    public function store(string $operation, array $metrics): void
    {
        $this->connection->table($this->table)->insert([
            'operation' => $operation,
            'metrics' => json_encode($metrics),
            'created_at' => now()
        ]);
    }

    public function getMetrics(string $operation, int $since): array
    {
        return $this->connection->table($this->table)
            ->where('operation', $operation)
            ->where('created_at', '>=', date('Y-m-d H:i:s', $since))
            ->get()
            ->map(fn($row) => json_decode($row->metrics, true))
            ->toArray();
    }

    public function cleanup(int $olderThan): int
    {
        return $this->connection->table($this->table)
            ->where('created_at', '<', date('Y-m-d H:i:s', $olderThan))
            ->delete();
    }
}

class AlertManager
{
    private array $handlers = [];
    private array $alertHistory = [];

    public function addHandler(string $type, callable $handler): void
    {
        $this->handlers[$type] = $handler;
    }

    public function triggerAlert(string $operation, string $metric, float $value, float $threshold): void
    {
        $alert = [
            'operation' => $operation,
            'metric' => $metric,
            'value' => $value,
            'threshold' => $threshold,
            'timestamp' => time()
        ];

        $this->alertHistory[] = $alert;

        foreach ($this->handlers as $handler) {
            $handler($alert);
        }
    }

    public function getAlertHistory(int $duration = 3600): array
    {
        $since = time() - $duration;
        return array_filter(
            $this->alertHistory,
            fn($alert) => $alert['timestamp'] >= $since
        );
    }
}

class PerformanceReport 
{
    private PerformanceMonitor $monitor;
    private array $operations = [];

    public function addOperation(string $operation): self
    {
        $this->operations[] = $operation;
        return $this;
    }

    public function generate(): array
    {
        $report = [];

        foreach ($this->operations as $operation) {
            $report[$operation] = $this->monitor->analyzePerformance($operation);
        }

        return [
            'summary' => $this->generateSummary($report),
            'details' => $report,
            'recommendations' => $this->generateRecommendations($report)
        ];
    }

    private function generateSummary(array $report): array
    {
        $durations = [];
        $memories = [];
        $success_rates = [];

        foreach ($report as $metrics) {
            $durations[] = $metrics['average_duration'];
            $memories[] = $metrics['memory_usage'];
            $success_rates[] = $metrics['success_rate'];
        }

        return [
            'overall_duration' => array_sum($durations) / count($durations),
            'overall_memory' => array_sum($memories) / count($memories),
            'overall_success_rate' => array_sum($success_rates) / count($success_rates)
        ];
    }

    private function generateRecommendations(array $report): array
    {
        $recommendations = [];

        foreach ($report as $operation => $metrics) {
            if ($metrics['average_duration'] > 1.0) {
                $recommendations[] = "Operation '$operation' is taking longer than expected";
            }
            if ($metrics['memory_usage'] > 10 * 1024 * 1024) {
                $recommendations[] = "Operation '$operation' is using excessive memory";
            }
            if ($metrics['success_rate'] < 99) {
                $recommendations[] = "Operation '$operation' has a low success rate";
            }
        }

        return $recommendations;
    }
}
