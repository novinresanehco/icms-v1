// app/Core/Monitoring/MetricsCollector.php
<?php

namespace App\Core\Monitoring;

use App\Core\Security\SecurityKernel;

class MetricsCollector implements MetricsInterface
{
    private SecurityKernel $security;
    private array $config;
    private array $metrics = [];

    public function record(string $metric, $value, array $tags = []): void
    {
        $this->security->executeSecure(function() use ($metric, $value, $tags) {
            $this->executeRecord($metric, $value, $tags);
        });
    }

    public function increment(string $metric, array $tags = []): void
    {
        $this->security->executeSecure(function() use ($metric, $tags) {
            $this->executeIncrement($metric, $tags);
        });
    }

    public function timing(string $metric, float $time, array $tags = []): void
    {
        $this->security->executeSecure(function() use ($metric, $time, $tags) {
            $this->executeTiming($metric, $time, $tags);
        });
    }

    private function executeRecord(string $metric, $value, array $tags): void
    {
        $this->validateMetric($metric);
        $this->validateTags($tags);

        $this->metrics[] = [
            'name' => $metric,
            'value' => $value,
            'tags' => $tags,
            'timestamp' => microtime(true)
        ];

        if ($this->shouldFlush()) {
            $this->flush();
        }
    }

    private function executeIncrement(string $metric, array $tags): void
    {
        $this->executeRecord($metric, 1, $tags);
    }

    private function executeTiming(string $metric, float $time, array $tags): void
    {
        $this->executeRecord("{$metric}_ms", $time * 1000, $tags);
    }

    private function validateMetric(string $metric): void
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_\.]*$/', $metric)) {
            throw new InvalidMetricException("Invalid metric name: {$metric}");
        }
    }

    private function validateTags(array $tags): void
    {
        foreach ($tags as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                throw new InvalidTagException("Invalid tag: {$key}");
            }
        }
    }

    private function shouldFlush(): bool
    {
        return count($this->metrics) >= ($this->config['batch_size'] ?? 100);
    }

    private function flush(): void
    {
        if (empty($this->metrics)) {
            return;
        }

        try {
            $this->storeMetrics($this->metrics);
            $this->metrics = [];
        } catch (\Exception $e) {
            Log::error('Failed to flush metrics', [
                'error' => $e->getMessage(),
                'metrics_count' => count($this->metrics)
            ]);
            throw new MetricsFlushException('Failed to flush metrics', 0, $e);
        }
    }

    private function storeMetrics(array $metrics): void
    {
        foreach ($this->config['storage'] as $storage) {
            $storage->store($metrics);
        }
    }
}

// app/Core/Monitoring/SystemMonitor.php
class SystemMonitor implements MonitorInterface
{
    private SecurityKernel $security;
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private array $config;

    public function monitor(): array
    {
        return $this->security->executeSecure(function() {
            return $this->executeMonitoring();
        });
    }

    private function executeMonitoring(): array
    {
        $metrics = [
            'cpu' => $this->collectCpuMetrics(),
            'memory' => $this->collectMemoryMetrics(),
            'disk' => $this->collectDiskMetrics(),
            'network' => $this->collectNetworkMetrics(),
            'application' => $this->collectApplicationMetrics(),
            'timestamp' => microtime(true)
        ];

        $this->analyzeMetrics($metrics);
        $this->storeMetrics($metrics);

        return $metrics;
    }

    private function collectCpuMetrics(): array
    {
        return [
            'usage' => sys_getloadavg(),
            'processes' => $this->getProcessCount(),
            'temperature' => $this->getCpuTemperature()
        ];
    }

    private function collectMemoryMetrics(): array
    {
        $meminfo = $this->parseMemInfo();
        return [
            'total' => $meminfo['MemTotal'] ?? 0,
            'used' => $meminfo['MemTotal'] - $meminfo['MemFree'] ?? 0,
            'cached' => $meminfo['Cached'] ?? 0,
            'swap_used' => $meminfo['SwapTotal'] - $meminfo['SwapFree'] ?? 0
        ];
    }

    private function collectDiskMetrics(): array
    {
        return array_map(function($mount) {
            $usage = disk_free_space($mount);
            $total = disk_total_space($mount);
            return [
                'free' => $usage,
                'total' => $total,
                'used' => $total - $usage,
                'mount' => $mount
            ];
        }, $this->config['disk_mounts']);
    }

    private function analyzeMetrics(array $metrics): void
    {
        foreach ($this->config['thresholds'] as $metric => $threshold) {
            if ($this->isThresholdExceeded($metrics, $metric, $threshold)) {
                $this->handleThresholdViolation($metric, $metrics[$metric], $threshold);
            }
        }
    }

    private function isThresholdExceeded(array $metrics, string $metric, array $threshold): bool
    {
        $value = data_get($metrics, $metric);
        return $value > $threshold['critical'] ?? PHP_FLOAT_MAX;
    }

    private function handleThresholdViolation(string $metric, $value, array $threshold): void
    {
        $this->alerts->trigger("threshold_exceeded", [
            'metric' => $metric,
            'value' => $value,
            'threshold' => $threshold['critical'],
            'timestamp' => microtime(true)
        ]);
    }
}