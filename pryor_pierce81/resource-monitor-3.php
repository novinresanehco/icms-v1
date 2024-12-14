<?php

namespace App\Core\Resource;

class ResourceMonitor
{
    private array $metrics = [];
    private array $limits = [];
    private AlertManager $alertManager;

    public function trackResourceUsage(): void
    {
        $this->recordMetrics([
            'memory' => $this->getMemoryUsage(),
            'cpu' => $this->getCpuUsage(),
            'disk' => $this->getDiskUsage(),
            'connections' => $this->getConnectionCount(),
            'timestamp' => time()
        ]);
    }

    public function setResourceLimit(string $resource, float $limit): void
    {
        $this->limits[$resource] = $limit;
    }

    private function recordMetrics(array $metrics): void
    {
        $this->metrics[] = $metrics;
        $this->checkLimits($metrics);
    }

    private function checkLimits(array $metrics): void
    {
        foreach ($this->limits as $resource => $limit) {
            if (isset($metrics[$resource]) && $metrics[$resource] > $limit) {
                $this->alertManager->triggerResourceAlert($resource, $metrics[$resource], $limit);
            }
        }
    }

    private function getMemoryUsage(): array
    {
        return [
            'used' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'limit' => ini_get('memory_limit')
        ];
    }

    private function getCpuUsage(): float
    {
        $load = sys_getloadavg();
        return $load[0];
    }

    private function getDiskUsage(): array
    {
        $path = storage_path();
        return [
            'free' => disk_free_space($path),
            'total' => disk_total_space($path)
        ];
    }

    private function getConnectionCount(): int
    {
        return DB::table('information_schema.processlist')->count();
    }

    public function getResourceMetrics(int $duration = 3600): array
    {
        $since = time() - $duration;
        return array_filter(
            $this->metrics,
            fn($m) => $m['timestamp'] >= $since
        );
    }

    public function analyzeResourceUsage(): array
    {
        $metrics = $this->getResourceMetrics();
        
        return [
            'memory' => $this->analyzeMemoryUsage($metrics),
            'cpu' => $this->analyzeCpuUsage($metrics),
            'disk' => $this->analyzeDiskUsage($metrics),
            'connections' => $this->analyzeConnections($metrics)
        ];
    }
}
