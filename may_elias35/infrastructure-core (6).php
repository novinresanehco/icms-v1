<?php

namespace App\Core\Infrastructure;

use Illuminate\Support\Facades\{Cache, DB, Log};
use App\Core\Monitoring\PerformanceMonitor;

final class InfrastructureCore 
{
    private PerformanceMonitor $monitor;
    private CacheManager $cache;
    private ResourceManager $resources;

    public function __construct(
        PerformanceMonitor $monitor,
        CacheManager $cache,
        ResourceManager $resources
    ) {
        $this->monitor = $monitor;
        $this->cache = $cache;
        $this->resources = $resources;
    }

    public function initializeSystem(): void 
    {
        $this->monitor->startSystemMonitoring();
        $this->resources->optimizeAllocations();
        $this->cache->initializeStrategies();
    }

    public function monitorPerformance(): array 
    {
        return [
            'cpu_usage' => $this->resources->getCPUUsage(),
            'memory_usage' => $this->resources->getMemoryUsage(),
            'query_times' => $this->monitor->getAverageQueryTimes(),
            'cache_hits' => $this->cache->getHitRatio(),
            'response_times' => $this->monitor->getAverageResponseTimes()
        ];
    }

    public function checkSystemHealth(): array 
    {
        return [
            'database' => $this->checkDatabaseHealth(),
            'cache' => $this->checkCacheHealth(),
            'storage' => $this->checkStorageHealth(),
            'queues' => $this->checkQueueHealth()
        ];
    }

    private function checkDatabaseHealth(): array 
    {
        try {
            DB::connection()->getPdo();
            return ['status' => 'healthy', 'latency' => $this->monitor->getDatabaseLatency()];
        } catch (\Exception $e) {
            Log::critical('Database health check failed', ['error' => $e->getMessage()]);
            return ['status' => 'failing', 'error' => $e->getMessage()];
        }
    }

    private function checkCacheHealth(): array 
    {
        return [
            'status' => Cache::connection()->ping() ? 'healthy' : 'failing',
            'hit_ratio' => $this->cache->getHitRatio(),
            'memory_usage' => $this->cache->getMemoryUsage()
        ];
    }

    private function checkStorageHealth(): array 
    {
        return $this->resources->checkStorageHealth();
    }

    private function checkQueueHealth(): array 
    {
        return $this->resources->checkQueueHealth();
    }
}
