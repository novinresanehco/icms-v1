<?php

namespace App\Core\Notification\Analytics\Optimizer;

class AnalyticsOptimizer
{
    private array $optimizers = [];
    private array $metrics = [];
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'max_iterations' => 100,
            'convergence_threshold' => 0.001,
            'timeout' => 30
        ], $config);
    }

    public function registerOptimizer(string $name, OptimizerInterface $optimizer): void
    {
        $this->optimizers[$name] = $optimizer;
    }

    public function optimize(string $optimizer, array $data, array $options = []): array
    {
        if (!isset($this->optimizers[$optimizer])) {
            throw new \InvalidArgumentException("Unknown optimizer: {$optimizer}");
        }

        $startTime = microtime(true);
        try {
            $result = $this->optimizers[$optimizer]->optimize($data, array_merge($this->config, $options));
            $this->recordMetrics($optimizer, $data, $result, microtime(true) - $startTime, true);
            return $result;
        } catch (\Exception $e) {
            $this->recordMetrics($optimizer, $data, [], microtime(true) - $startTime, false);
            throw $e;
        }
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    private function recordMetrics(string $optimizer, array $input, array $output, float $duration, bool $success): void
    {
        if (!isset($this->metrics[$optimizer])) {
            $this->metrics[$optimizer] = [
                'runs' => 0,
                'successful_runs' => 0,
                'failed_runs' => 0,
                'total_duration' => 0,
                'average_duration' => 0,
                'total_improvement' => 0
            ];
        }

        $this->metrics[$optimizer]['runs']++;
        $this->metrics[$optimizer][$success ? 'successful_runs' : 'failed_runs']++;
        $this->metrics[$optimizer]['total_duration'] += $duration;
        $this->metrics[$optimizer]['average_duration'] = 
            $this->metrics[$optimizer]['total_duration'] / $this->metrics[$optimizer]['runs'];

        if ($success) {
            $improvement = $this->calculateImprovement($input, $output);
            $this->metrics[$optimizer]['total_improvement'] += $improvement;
        }
    }

    private function calculateImprovement(array $input, array $output): float
    {
        // Example implementation - override with specific improvement calculation
        if (empty($input) || empty($output)) {
            return 0;
        }

        $inputMetric = $this->calculateMetric($input);
        $outputMetric = $this->calculateMetric($output);

        return ($outputMetric - $inputMetric) / $inputMetric;
    }

    private function calculateMetric(array $data): float
    {
        // Example implementation - override with specific metric calculation
        return array_sum(array_map(function($item) {
            return is_numeric($item) ? $item : 0;
        }, $data));
    }
}

interface OptimizerInterface
{
    public function optimize(array $data, array $config = []): array;
}

class PerformanceOptimizer implements OptimizerInterface
{
    private array $thresholds;

    public function __construct(array $thresholds = [])
    {
        $this->thresholds = array_merge([
            'execution_time' => 1000,
            'memory_usage' => 1024 * 1024 * 10,
            'query_count' => 10
        ], $thresholds);
    }

    public function optimize(array $data, array $config = []): array
    {
        $optimized = [];
        $metrics = [
            'execution_time' => 0,
            'memory_usage' => 0,
            'query_count' => 0
        ];

        foreach ($data as $key => $item) {
            if ($this->shouldInclude($item, $metrics)) {
                $optimized[$key] = $this->optimizeItem($item);
                $this->updateMetrics($metrics, $item);
            }
        }

        return $optimized;
    }

    private function shouldInclude(array $item, array $metrics): bool
    {
        foreach ($this->thresholds as $metric => $threshold) {
            if ($metrics[$metric] + ($item[$metric] ?? 0) > $threshold) {
                return false;
            }
        }
        return true;
    }

    private function optimizeItem(array $item): array
    {
        // Example optimization logic
        return array_filter($item, function($value) {
            return $value !== null && $value !== '';
        });
    }

    private function updateMetrics(array &$metrics, array $item): void
    {
        foreach ($metrics as $metric => $value) {
            $metrics[$metric] += $item[$metric] ?? 0;
        }
    }
}

class ResourceOptimizer implements OptimizerInterface
{
    private array $resourceLimits;

    public function __construct(array $resourceLimits = [])
    {
        $this->resourceLimits = array_merge([
            'cpu' => 80,
            'memory' => 1024 * 1024 * 100,
            'storage' => 1024 * 1024 * 1000
        ], $resourceLimits);
    }

    public function optimize(array $data, array $config = []): array
    {
        $optimized = [];
        $resources = [
            'cpu' => 0,
            'memory' => 0,
            'storage' => 0
        ];

        $prioritizedData = $this->prioritizeData($data);

        foreach ($prioritizedData as $item) {
            if ($this->canAllocateResources($item, $resources)) {
                $optimized[] = $this->optimizeResources($item);
                $this->allocateResources($resources, $item);
            }
        }

        return $optimized;
    }

    private function prioritizeData(array $data): array
    {
        usort($data, function($a, $b) {
            return ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0);
        });
        return $data;
    }

    private function canAllocateResources(array $item, array $resources): bool
    {
        foreach ($this->resourceLimits as $resource => $limit) {
            if ($resources[$resource] + ($item[$resource] ?? 0) > $limit) {
                return false;
            }
        }
        return true;
    }

    private function optimizeResources(array $item): array
    {
        // Example resource optimization logic
        $optimized = $item;
        
        if (isset($optimized['data'])) {
            $optimized['data'] = $this->compressData($optimized['data']);
        }
        
        return $optimized;
    }

    private function compressData($data)
    {
        if (is_string($data)) {
            return gzcompress($data, 9);
        }
        return $data;
    }

    private function allocateResources(array &$resources, array $item): void
    {
        foreach ($resources as $resource => $value) {
            $resources[$resource] += $item[$resource] ?? 0;
        }
    }
}
