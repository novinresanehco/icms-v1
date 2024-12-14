<?php

namespace App\Core\Infrastructure;

class ResourceManager
{
    private ConfigurationManager $config;
    private CacheManager $cache;
    private DatabaseManager $database;
    private StorageManager $storage;
    private MonitoringService $monitor;

    public function __construct(
        ConfigurationManager $config,
        CacheManager $cache,
        DatabaseManager $database,
        StorageManager $storage,
        MonitoringService $monitor
    ) {
        $this->config = $config;
        $this->cache = $cache;
        $this->database = $database;
        $this->storage = $storage;
        $this->monitor = $monitor;
    }

    public function allocateForOperation(array $context): void
    {
        // Calculate required resources
        $requirements = $this->calculateRequirements($context);
        
        // Check availability
        if (!$this->checkAvailability($requirements)) {
            throw new InsufficientResourcesException();
        }
        
        // Reserve resources
        $this->reserveResources($requirements);
        
        // Initialize systems
        $this->initializeSystems($requirements);
        
        // Start monitoring
        $this->monitor->startResourceTracking();
    }

    public function checkAvailability(array $requirements): bool
    {
        // Check database connections
        if (!$this->database->hasAvailableConnections($requirements['db_connections'])) {
            return false;
        }
        
        // Check cache capacity
        if (!$this->cache->hasAvailableCapacity($requirements['cache_size'])) {
            return false;
        }
        
        // Check storage space
        if (!$this->storage->hasAvailableSpace($requirements['storage_size'])) {
            return false;
        }
        
        // Check system resources
        if (!$this->hasSystemResources($requirements)) {
            return false;
        }
        
        return true;
    }

    public function releaseResources(): void
    {
        try {
            // Release database connections
            $this->database->releaseConnections();
            
            // Clear cache if needed
            $this->cache->releaseMemory();
            
            // Release storage locks
            $this->storage->releaseLocks();
            
            // Stop resource tracking
            $this->monitor->stopResourceTracking();
            
        } catch (\Throwable $e) {
            // Log cleanup failure
            Log::error('Resource cleanup failed', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Attempt emergency cleanup
            $this->performEmergencyCleanup();
            
            throw $e;
        }
    }

    private function calculateRequirements(array $context): array
    {
        return [
            'db_connections' => $this->calculateDbConnections($context),
            'cache_size' => $this->calculateCacheSize($context),
            'storage_size' => $this->calculateStorageSize($context),
            'memory_limit' => $this->calculateMemoryLimit($context),
            'cpu_limit' => $this->calculateCpuLimit($context)
        ];
    }

    private function reserveResources(array $requirements): void
    {
        // Reserve database connections
        $this->database->reserveConnections($requirements['db_connections']);
        
        // Allocate cache
        $this->cache->allocate($requirements['cache_size']);
        
        // Reserve storage
        $this->storage->reserve($requirements['storage_size']);
        
        // Set resource limits
        $this->setResourceLimits($requirements);
    }

    private function initializeSystems(array $requirements): void
    {
        // Initialize database pool
        $this->database->initializePool($requirements['db_connections']);
        
        // Setup cache
        $this->cache->initialize($requirements['cache_size']);
        
        // Prepare storage
        $this->storage->prepare($requirements['storage_size']);
    }

    private function performEmergencyCleanup(): void
    {
        // Force release all resources
        $this->database->forceReleaseAll();
        $this->cache->clearAll();
        $this->storage->forceRelease();
        
        // Reset system state
        $this->resetSystemState();
    }
}
