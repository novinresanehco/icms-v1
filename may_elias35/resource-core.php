<?php

namespace App\Core\Resources;

class ResourceManager implements ResourceInterface
{
    private StorageManager $storage;
    private SecurityManager $security;
    private ValidationService $validator;
    private LockManager $locks;
    private AuditLogger $logger;
    private MetricsCollector $metrics;

    public function __construct(
        StorageManager $storage,
        SecurityManager $security,
        ValidationService $validator,
        LockManager $locks,
        AuditLogger $logger,
        MetricsCollector $metrics
    ) {
        $this->storage = $storage;
        $this->security = $security;
        $this->validator = $validator;
        $this->locks = $locks;
        $this->logger = $logger;
        $this->metrics = $metrics;
    }

    public function allocate(string $resourceType, array $options): ResourceHandle
    {
        $allocationId = uniqid('alloc_', true);
        
        try {
            $this->validateResourceRequest($resourceType, $options);
            $this->security->validateResourceAccess($resourceType);

            $handle = $this->createResourceHandle($allocationId, $resourceType, $options);
            $this->initializeResource($handle);
            
            $this->logger->logResourceAllocation($allocationId, $resourceType);
            return $handle;

        } catch (\Exception $e) {
            $this->handleAllocationFailure($allocationId, $resourceType, $e);
            throw new ResourceException('Resource allocation failed', 0, $e);
        }
    }

    public function release(ResourceHandle $handle): void
    {
        try {
            $this->validateResourceHandle($handle);
            $this->security->validateReleasePermission($handle);

            $this->cleanupResource($handle);
            $this->releaseResourceHandle($handle);
            
            $this->logger->logResourceRelease($handle->getId());

        } catch (\Exception $e) {
            $this->handleReleaseFailure($handle, $e);
            throw new ResourceException('Resource release failed', 0, $e);
        }
    }

    private function validateResourceRequest(string $resourceType, array $options): void
    {
        if (!$this->validator->validateResourceType($resourceType)) {
            throw new ValidationException('Invalid resource type');
        }

        if (!$this->validator->validateResourceOptions($options)) {
            throw new ValidationException('Invalid resource options');
        }

        if (!$this->storage->hasAvailableCapacity($resourceType)) {
            throw new CapacityException('Insufficient resource capacity');
        }
    }

    private function createResourceHandle(string $allocationId, string $resourceType, array $options): ResourceHandle
    {
        $lock = $this->locks->acquireLock($resourceType);
        
        try {
            $resource = $this->storage->allocateResource($resourceType, $options);
            
            return new ResourceHandle(
                $allocationId,
                $resourceType,
                $resource,
                $options,
                $this->security->generateAccessToken($allocationId)
            );

        } finally {
            $this->locks->releaseLock($lock);
        }
    }

    private function initializeResource(ResourceHandle $handle): void
    {
        try {
            $this->storage->initializeResource($handle->getResource());
            $this->security->secureResource($handle);
            
            if ($handle->hasInitializer()) {
                $initializer = $handle->getInitializer();
                $initializer($handle->getResource());
            }

        } catch (\Exception $e) {
            $this->rollbackAllocation($handle);
            throw $e;
        }
    }

    private function validateResourceHandle(ResourceHandle $handle): void
    {
        if (!$this->validator->validateHandle($handle)) {
            throw new ValidationException('Invalid resource handle');
        }

        if (!$this->security->validateAccessToken($handle)) {
            throw new SecurityException('Invalid resource access token');
        }

        if (!$this->storage->resourceExists($handle->getResource())) {
            throw new ResourceException('Resource no longer exists');
        }
    }

    private function cleanupResource(ResourceHandle $handle): void
    {
        $lock = $this->locks->acquireLock($handle->getResourceType());
        
        try {
            if ($handle->hasCleanup()) {
                $cleanup = $handle->getCleanup();
                $cleanup($handle->getResource());
            }

            $this->storage->cleanupResource($handle->getResource());
            $this->security->revokeResourceAccess($handle);

        } finally {
            $this->locks->releaseLock($lock);
        }
    }

    private function releaseResourceHandle(ResourceHandle $handle): void
    {
        $this->storage->deallocateResource($handle->getResource());
        $this->security->revokeAccessToken($handle);
        
        $this->metrics->recordResourceRelease(
            $handle->getResourceType(),
            $handle->getUsageDuration()
        );
    }

    private function rollbackAllocation(ResourceHandle $handle): void
    {
        try {
            $this->storage->rollbackAllocation($handle->getResource());
            $this->security->revokeAccessToken($handle);
            
            $this->logger->logAllocationRollback($handle->getId());

        } catch (\Exception $e) {
            $this->logger->logRollbackFailure($handle->getId(), $e);
        }
    }

    private function handleAllocationFailure(string $allocationId, string $resourceType, \Exception $e): void
    {
        $this->logger->logAllocationFailure($allocationId, [
            'type' => $resourceType,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->metrics->recordAllocationFailure($resourceType);

        if ($e instanceof SecurityException) {
            $this->security->handleSecurityIncident($allocationId, $e);
        }
    }

    private function handleReleaseFailure(ResourceHandle $handle, \Exception $e): void
    {
        $this->logger->logReleaseFailure($handle->getId(), [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->metrics->recordReleaseFailure($handle->getResourceType());

        if ($e instanceof SecurityException) {
            $this->security->handleSecurityIncident($handle->getId(), $e);
        }
    }
}
