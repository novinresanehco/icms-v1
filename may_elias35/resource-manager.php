<?php

namespace App\Core\Resource;

class ResourceManager
{
    private ResourcePool $pool;
    private ResourceMonitor $monitor;
    private ResourceLimiter $limiter;
    private ResourceLogger $logger;
    private LockManager $lockManager;

    public function __construct(
        ResourcePool $pool,
        ResourceMonitor $monitor,
        ResourceLimiter $limiter,
        ResourceLogger $logger,
        LockManager $lockManager
    ) {
        $this->pool = $pool;
        $this->monitor = $monitor;
        $this->limiter = $limiter;
        $this->logger = $logger;
        $this->lockManager = $lockManager;
    }

    public function acquireResource(string $type, array $requirements = []): Resource
    {
        $lock = $this->lockManager->acquire("resource:$type");
        
        try {
            if (!$this->limiter->canAcquire($type)) {
                throw new ResourceLimitExceededException($type);
            }

            $resource = $this->pool->getResource($type, $requirements);
            $this->monitor->trackResourceUsage($resource);
            $this->logger->logResourceAcquisition($resource);

            return $resource;
        } finally {
            $lock->release();
        }
    }

    public function releaseResource(Resource $resource): void
    {
        $lock = $this->lockManager->acquire("resource:{$resource->getId()}");
        
        try {
            $this->monitor->stopTracking($resource);
            $this->pool->releaseResource($resource);
            $this->logger->logResourceRelease($resource);
        } finally {
            $lock->release();
        }
    }

    public function optimizeResources(): OptimizationResult
    {
        $unusedResources = $this->monitor->getUnusedResources();
        $result = new OptimizationResult();

        foreach ($unusedResources as $resource) {
            try {
                $this->releaseResource($resource);
                $result->addSuccess($resource);
            } catch (\Exception $e) {
                $result->addFailure($resource, $e);
                $this->logger->logOptimizationFailure($resource, $e);
            }
        }

        return $result;
    }

    public function getResourceMetrics(): array
    {
        return [
            'active_resources' => $this->monitor->getActiveResourceCount(),
            'available_resources' => $this->pool->getAvailableCount(),
            'resource_usage' => $this->monitor->getResourceUsageStats(),
            'limits' => $this->limiter->getCurrentLimits()
        ];
    }
}

class ResourcePool
{
    private array $resources = [];
    private ResourceFactory $factory;
    private array $config;

    public function getResource(string $type, array $requirements = []): Resource
    {
        $available = $this->findAvailableResource($type, $requirements);

        if ($available) {
            return $available;
        }

        if (count($this->resources[$type] ?? []) >= ($this->config[$type]['max_instances'] ?? PHP_INT_MAX)) {
            throw new ResourcePoolExhaustedException($type);
        }

        return $this->createResource($type, $requirements);
    }

    public function releaseResource(Resource $resource): void
    {
        $resource->reset();
        $this->resources[$resource->getType()][] = $resource;
    }

    public function getAvailableCount(): array
    {
        $counts = [];
        foreach ($this->resources as $type => $resources) {
            $counts[$type] = count($resources);
        }
        return $counts;
    }

    protected function findAvailableResource(string $type, array $requirements): ?Resource
    {
        if (!isset($this->resources[$type])) {
            return null;
        }

        foreach ($this->resources[$type] as $key => $resource) {
            if ($resource->meetsRequirements($requirements)) {
                unset($this->resources[$type][$key]);
                return $resource;
            }
        }

        return null;
    }

    protected function createResource(string $type, array $requirements): Resource
    {
        return $this->factory->create($type, $requirements);
    }
}

class ResourceMonitor
{
    private array $activeResources = [];
    private MetricsCollector $metrics;
    private array $thresholds;

    public function trackResourceUsage(Resource $resource): void
    {
        $this->activeResources[$resource->getId()] = [
            'resource' => $resource,
            'start_time' => microtime(true),
            'metrics' => []
        ];
    }

    public function stopTracking(Resource $resource): void
    {
        if (isset($this->activeResources[$resource->getId()])) {
            $duration = microtime(true) - $this->activeResources[$resource->getId()]['start_time'];
            $this->metrics->recordResourceUsage($resource, $duration);
            unset($this->activeResources[$resource->getId()]);
        }
    }

    public function getUnusedResources(): array
    {
        $unused = [];
        $currentTime = microtime(true);

        foreach ($this->activeResources as $id => $data) {
            $idleTime = $currentTime - $data['start_time'];
            if ($idleTime > $this->thresholds['idle_timeout']) {
                $unused[] = $data['resource'];
            }
        }

        return $unused;
    }

    public function getResourceUsageStats(): array
    {
        return $this->metrics->getResourceStats();
    }

    public function getActiveResourceCount(): int
    {
        return count($this->activeResources);
    }
}

class ResourceLimiter
{
    private array $limits;
    private array $usage;

    public function canAcquire(string $type): bool
    {
        if (!isset($this->limits[$type])) {
            return true;
        }

        return ($this->usage[$type] ?? 0) < $this->limits[$type];
    }

    public function incrementUsage(string $type): void
    {
        $this->usage[$type] = ($this->usage[$type] ?? 0) + 1;
    }

    public function decrementUsage(string $type): void
    {
        if (isset($this->usage[$type]) && $this->usage[$type] > 0) {
            $this->usage[$type]--;
        }
    }

    public function getCurrentLimits(): array
    {
        return array_map(function($type) {
            return [
                'limit' => $this->limits[$type] ?? PHP_INT_MAX,
                'current' => $this->usage[$type] ?? 0
            ];
        }, array_keys($this->limits));
    }
}

class ResourceLogger
{
    private LoggerInterface $logger;

    public function logResourceAcquisition(Resource $resource): void
    {
        $this->logger->info('Resource acquired', [
            'resource_id' => $resource->getId(),
            'type' => $resource->getType(),
            'timestamp' => time()
        ]);
    }

    public function logResourceRelease(Resource $resource): void
    {
        $this->logger->info('Resource released', [
            'resource_id' => $resource->getId(),
            'type' => $resource->getType(),
            'timestamp' => time()
        ]);
    }

    public function logOptimizationFailure(Resource $resource, \Exception $e): void
    {
        $this->logger->error('Resource optimization failed', [
            'resource_id' => $resource->getId(),
            'error' => $e->getMessage(),
            'timestamp' => time()
        ]);
    }
}
