<?php

namespace App\Core\Infrastructure;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Monitoring\MonitoringInterface;
use App\Core\Exception\ResourceException;
use Psr\Log\LoggerInterface;

class ResourceManager implements ResourceManagerInterface
{
    private SecurityManagerInterface $security;
    private MonitoringInterface $monitor;
    private LoggerInterface $logger;
    private array $resources = [];
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        MonitoringInterface $monitor,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function allocateResource(string $type, array $requirements): string
    {
        $resourceId = $this->generateResourceId();

        try {
            DB::beginTransaction();

            $this->security->validateContext('resource:allocate');
            $this->validateResourceType($type);
            $this->validateRequirements($type, $requirements);

            $monitoringId = $this->monitor->startOperation([
                'type' => 'resource_allocation',
                'resource_type' => $type
            ]);

            $resource = $this->executeResourceAllocation($type, $requirements);
            $this->verifyResourceHealth($resource);

            $this->resources[$resourceId] = $resource;

            $this->logResourceAllocation($resourceId, $type, $requirements);
            $this->monitor->stopOperation($monitoringId);

            DB::commit();
            return $resourceId;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleResourceFailure($resourceId, $type, 'allocation', $e);
            throw new ResourceException("Resource allocation failed: {$type}", 0, $e);
        }
    }

    public function deallocateResource(string $resourceId): void
    {
        try {
            DB::beginTransaction();

            $this->security->validateContext('resource:deallocate');
            $this->validateResourceExists($resourceId);

            $monitoringId = $this->monitor->startOperation([
                'type' => 'resource_deallocation',
                'resource_id' => $resourceId
            ]);

            $resource = $this->resources[$resourceId];
            $this->executeResourceDeallocation($resource);
            
            unset($this->resources[$resourceId]);

            $this->logResourceDeallocation($resourceId, $resource);
            $this->monitor->stopOperation($monitoringId);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleResourceFailure($resourceId, $resource['type'], 'deallocation', $e);
            throw new ResourceException("Resource deallocation failed: {$resourceId}", 0, $e);
        }
    }

    public function getResourceMetrics(string $resourceId): array
    {
        try {
            $this->security->validateContext('resource:metrics');
            $this->validateResourceExists($resourceId);

            $resource = $this->resources[$resourceId];
            $metrics = $this->collectResourceMetrics($resource);
            
            $this->validateResourceMetrics($resourceId, $metrics);
            $this->logResourceMetrics($resourceId, $metrics);

            return $metrics;

        } catch (\Exception $e) {
            $this->handleResourceFailure($resourceId, $resource['type'], 'metrics', $e);
            throw new ResourceException("Resource metrics collection failed: {$resourceId}", 0, $e);
        }
    }

    private function validateResourceType(string $type): void
    {
        if (!isset($this->config['resource_types'][$type])) {
            throw new ResourceException("Invalid resource type: {$type}");
        }
    }

    private function validateRequirements(string $type, array $requirements): void
    {
        $typeConfig = $this->config['resource_types'][$type];

        foreach ($typeConfig['required_fields'] as $field) {
            if (!isset($requirements[$field])) {
                throw new ResourceException("Missing required field: {$field}");
            }
        }

        foreach ($requirements as $field => $value) {
            if (isset($typeConfig['limits'][$field]) && 
                $value > $typeConfig['limits'][$field]) {
                throw new ResourceException(
                    "Resource requirement exceeds limit: {$field}"
                );
            }
        }
    }

    private function validateResourceExists(string $resourceId): void
    {
        if (!isset($this->resources[$resourceId])) {
            throw new ResourceException("Resource not found: {$resourceId}");
        }
    }

    private function executeResourceAllocation(
        string $type,
        array $requirements
    ): array {
        $resource = [
            'type' => $type,
            'requirements' => $requirements,
            'allocated_at' => microtime(true),
            'status' => 'allocating',
            'metrics' => []
        ];

        // Allocate physical resources
        $this->allocatePhysicalResources($type, $requirements);
        
        // Initialize monitoring
        $this->initializeResourceMonitoring($resource);
        
        $resource['status'] = 'active';
        return $resource;
    }

    private function executeResourceDeallocation(array $resource): void
    {
        // Stop monitoring
        $this->stopResourceMonitoring($resource);
        
        // Release physical resources
        $this->releasePhysicalResources($resource);
        
        // Cleanup
        $this->cleanupResource($resource);
    }

    private function collectResourceMetrics(array $resource): array
    {
        return [
            'cpu_usage' => $this->getCpuUsage($resource),
            'memory_usage' => $this->getMemoryUsage($resource),
            'disk_usage' => $this->getDiskUsage($resource),
            'network_usage' => $this->getNetworkUsage($resource)
        ];
    }

    private function validateResourceMetrics(
        string $resourceId,
        array $metrics
    ): void {
        $resource = $this->resources[$resourceId];
        $limits = $this