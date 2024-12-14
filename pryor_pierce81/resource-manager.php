<?php

namespace App\Core\Services;

use App\Core\Interfaces\ResourceInterface;
use App\Core\Events\{ResourceAlert, SystemOverloadEvent};
use App\Core\Models\ResourceMetrics;
use Illuminate\Support\Facades\{Cache, DB, Log, Event};

class ResourceManager implements ResourceInterface 
{
    private array $config;
    private MetricsRepository $metrics;
    private CacheManager $cache;
    private AlertService $alerts;

    public function __construct(
        array $config,
        MetricsRepository $metrics,
        CacheManager $cache,
        AlertService $alerts
    ) {
        $this->config = $config;
        $this->metrics = $metrics;
        $this->cache = $cache;
        $this->alerts = $alerts;
    }

    public function getAvailableResources(): array
    {
        $metrics = $this->getCurrentMetrics();
        
        return [
            'memory' => $this->calculateAvailableMemory($metrics),
            'cpu' => $this->calculateAvailableCpu($metrics),
            'connections' => $this->getAvailableConnections(),
            'storage' => $this->getAvailableStorage(),
            'cache' => $this->cache->getAvailableSpace(),
        ];
    }

    public function validateResourceAvailability(array $requirements): bool
    {
        $available = $this->getAvailableResources();
        
        foreach ($requirements as $resource => $required) {
            if (!isset($available[$resource]) || $available[$resource] < $required) {
                $this->logResourceShortage($resource, $required, $available[$resource] ?? 0);
                return false;
            }
        }
        
        return true;
    }

    public function allocateResources(array $requirements): string
    {
        $allocationId = $this->generateAllocationId();
        
        if (!$this->validateResourceAvailability($requirements)) {
            throw new ResourceException('Insufficient resources available');
        }

        DB::beginTransaction();
        
        try {
            $allocation = $this->createAllocation($allocationId, $requirements);
            $this->reserveResources($allocation);
            
            DB::commit();
            return $allocationId;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ResourceException('Resource allocation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function releaseResources(string $allocationId): void
    {
        $allocation = $this->getAllocation($allocationId);
        
        if (!$allocation) {
            throw new ResourceException('Invalid allocation ID');
        }

        DB::beginTransaction();
        
        try {
            $this->freeResources($allocation);
            $this->deleteAllocation($allocationId);
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ResourceException('Resource release failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function optimizeResources(): void
    {
        if ($this->isOptimizationNeeded()) {
            $this->performOptimization();
        }

        if ($this->isCleanupNeeded()) {
            $this->performCleanup();
        }

        $this->updateResourceMetrics();
    }

    protected function getCurrentMetrics(): ResourceMetrics
    {
        return new ResourceMetrics([
            'memory_usage' => memory_get_usage(true),
            'cpu_usage' => sys_getloadavg()[0],
            'disk_usage' => disk_free_space('/'),
            'connection_count' => DB::table('information_schema.processlist')->count(),
            'cache_usage' => $this->cache->getUsage(),
            'timestamp' => microtime(true)
        ]);
    }

    protected function calculateAvailableMemory(ResourceMetrics $metrics): int
    {
        $maxMemory = $this->config['memory_limit'];
        $currentUsage = $metrics->memory_usage;
        $reserved = $this->getTotalReservedMemory();
        
        return $maxMemory - ($currentUsage + $reserved);
    }

    protected function calculateAvailableCpu(ResourceMetrics $metrics): float
    {
        $maxCpu = $this->config['cpu_limit'];
        $currentUsage = $metrics->cpu_usage;
        $reserved = $this->getTotalReservedCpu();
        
        return $maxCpu - ($currentUsage + $reserved);
    }

    protected function getAvailableConnections(): int
    {
        $maxConnections = $this->config['max_connections'];
        $currentConnections = DB::table('information_schema.processlist')->count();
        
        return $maxConnections - $currentConnections;
    }

    protected function getAvailableStorage(): int
    {
        return disk_free_space('/');
    }

    protected function logResourceShortage(string $resource, $required, $available): void
    {
        Log::warning('Resource shortage detected', [
            'resource' => $resource,
            'required' => $required,
            'available' => $available,
            'timestamp' => microtime(true)
        ]);

        $this->alerts->sendResourceAlert($resource, $required, $available);
    }

    protected function isOptimizationNeeded(): bool
    {
        $metrics = $this->getCurrentMetrics();
        
        return $metrics->memory_usage > $this->config['optimization_memory_threshold'] ||
               $metrics->cpu_usage > $this->config['optimization_cpu_threshold'] ||
               $metrics->cache_usage > $this->config['optimization_cache_threshold'];
    }

    protected function performOptimization(): void
    {
        $this->cache->optimize();
        $this->optimizeConnections();
        $this->cleanupTempStorage();
    }

    protected function performCleanup(): void
    {
        $this->cleanupExpiredAllocations();
        $this->cleanupTempFiles();
        $this->compactStorage();
    }

    private function generateAllocationId(): string
    {
        return uniqid('rsrc_', true);
    }
}
