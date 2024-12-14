<?php

namespace App\Core\Resource;

use App\Core\Security\SecurityManager;
use App\Core\Interfaces\ResourceManagerInterface;
use App\Core\Exceptions\{
    ResourceException,
    SecurityException,
    ValidationException
};
use Illuminate\Support\Facades\{Cache, Log};

class ResourceManager implements ResourceManagerInterface 
{
    private SecurityManager $security;
    private ValidationService $validator;
    private MonitoringService $monitor;
    private array $config;

    private const RESOURCE_PREFIX = 'resource:';
    private const LOCK_TIMEOUT = 30;
    private const MAX_RETRY = 3;
    private const RESOURCE_TYPES = ['memory', 'cpu', 'storage', 'network'];

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        MonitoringService $monitor,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->monitor = $monitor;
        $this->config = $config;
    }

    public function allocateResource(ResourceRequest $request): ResourceAllocation
    {
        return $this->security->executeSecureOperation(function() use ($request) {
            $operationId = $this->generateOperationId();
            $this->monitor->startOperation($operationId);

            try {
                $this->validateAllocationRequest($request);
                $this->enforceSecurityPolicy($request);
                $this->checkResourceAvailability($request);

                $resource = $this->acquireResource($request);
                $allocation = $this->createAllocation($operationId, $resource);
                
                $this->trackAllocation($allocation);
                $this->validateAllocationResult($allocation);
                
                $this->monitor->recordSuccess($operationId);
                return $allocation;

            } catch (\Exception $e) {
                $this->handleAllocationFailure($operationId, $request, $e);
                throw new ResourceException(
                    'Resource allocation failed: ' . $e->getMessage(),
                    previous: $e
                );
            } finally {
                $this->monitor->endOperation($operationId);
            }
        }, ['operation' => 'allocate_resource']);
    }

    public function releaseResource(string $allocationId): void 
    {
        $this->security->executeSecureOperation(function() use ($allocationId) {
            $operationId = $this->generateOperationId();
            
            try {
                $this->validateReleaseRequest($allocationId);
                $allocation = $this->getAllocation($allocationId);
                
                $this->enforceReleasePolicy($allocation);
                $this->performResourceRelease($allocation);
                
                $this->clearAllocationTracking($allocation);
                $this->validateReleaseResult($allocation);
                
                $this->monitor->recordSuccess($operationId);

            } catch (\Exception $e) {
                $this->handleReleaseFailure($operationId, $allocationId, $e);
                throw new ResourceException(
                    'Resource release failed: ' . $e->getMessage(),
                    previous: $e
                );
            }
        }, ['operation' => 'release_resource']);
    }

    protected function validateAllocationRequest(ResourceRequest $request): void
    {
        if (!in_array($request->getType(), self::RESOURCE_TYPES)) {
            throw new ValidationException('Invalid resource type');
        }

        if (!$this->validator->validateResourceRequest($request)) {
            throw new ValidationException('Invalid resource request parameters');
        }

        if (!$this->security->checkPermissions($request->getContext())) {
            throw new SecurityException('Insufficient permissions for resource allocation');
        }
    }

    protected function enforceSecurityPolicy(ResourceRequest $request): void
    {
        if (!$this->security->validateSecurityContext($request->getContext())) {
            throw new SecurityException('Security context validation failed');
        }

        if ($this->security->isRateLimitExceeded($request->getContext())) {
            throw new SecurityException('Resource allocation rate limit exceeded');
        }

        if ($this->security->detectAnomalousPattern($request)) {
            throw new SecurityException('Anomalous resource allocation pattern detected');
        }
    }

    protected function checkResourceAvailability(ResourceRequest $request): void
    {
        $available = $this->monitor->checkResourceAvailability(
            $request->getType(),
            $request->getQuantity()
        );

        if (!$available) {
            throw new ResourceException('Requested resources not available');
        }

        if ($this->monitor->isQuotaExceeded($request)) {
            throw new ResourceException('Resource allocation quota exceeded');
        }

        if ($this->monitor->isThresholdExceeded($request->getType())) {
            throw new ResourceException('Resource allocation threshold exceeded');
        }
    }

    protected function acquireResource(ResourceRequest $request): Resource
    {
        $attempts = 0;
        while ($attempts < self::MAX_RETRY) {
            try {
                $resource = $this->executeResourceAcquisition($request);
                $this->validateAcquiredResource($resource);
                return $resource;

            } catch (\Exception $e) {
                $attempts++;
                if ($attempts === self::MAX_RETRY) {
                    throw new ResourceException(
                        'Resource acquisition failed after ' . self::MAX_RETRY . ' attempts',
                        previous: $e
                    );
                }
                usleep(100000 * $attempts); // Exponential backoff
            }
        }
    }

    protected function executeResourceAcquisition(ResourceRequest $request): Resource
    {
        $lock = Cache::lock(
            'resource:' . $request->getType(),
            self::LOCK_TIMEOUT
        );

        if (!$lock->get()) {
            throw new ResourceException('Failed to acquire resource lock');
        }

        try {
            $resource = new Resource([
                'type' => $request->getType(),
                'quantity' => $request->getQuantity(),
                'metadata' => $this->generateResourceMetadata($request)
            ]);

            if (!$this->monitor->reserveResource($resource)) {
                throw new ResourceException('Failed to reserve resource');
            }

            return $resource;

        } finally {
            $lock->release();
        }
    }

    protected function createAllocation(
        string $operationId,
        Resource $resource
    ): ResourceAllocation {
        return new ResourceAllocation([
            'id' => $operationId,
            'resource' => $resource,
            'allocated_at' => microtime(true),
            'expires_at' => $this->calculateExpiration($resource),
            'metadata' => $this->generateAllocationMetadata($resource)
        ]);
    }

    protected function trackAllocation(ResourceAllocation $allocation): void
    {
        $key = self::RESOURCE_PREFIX . $allocation->getId();
        
        Cache::put(
            $key,
            $allocation->toArray(),
            $this->calculateAllocationTTL($allocation)
        );

        $this->monitor->trackAllocation($allocation);
        $this->security->logSecurityEvent('resource_allocated', ['allocation' => $allocation]);
    }

    protected function handleAllocationFailure(
        string $operationId,
        ResourceRequest $request,
        \Exception $e
    ): void {
        $this->monitor->recordFailure($operationId, $e);
        
        $this->security->logSecurityEvent('resource_allocation_failed', [
            'operation_id' => $operationId,
            'request' => $request,
            'error' => $e->getMessage(),
            'stack_trace' => $e->getTraceAsString()
        ]);

        if ($e instanceof SecurityException) {
            $this->security->handleSecurityViolation($e);
        }
    }

    protected function generateOperationId(): string
    {
        return uniqid('op:', true);
    }

    protected function generateResourceMetadata(ResourceRequest $request): array
    {
        return [
            'timestamp' => microtime(true),
            'node_id' => gethostname(),
            'version' => $this->config['version'],
            'request_context' => $request->getContext()
        ];
    }

    protected function generateAllocationMetadata(Resource $resource): array
    {
        return [
            'timestamp' => microtime(true),
            'node_id' => gethostname(),
            'version' => $this->config['version'],
            'resource_type' => $resource->getType(),
            'resource_quantity' => $resource->getQuantity()
        ];
    }

    protected function calculateExpiration(Resource $resource): float
    {
        return microtime(true) + $this->config['allocation_ttl'];
    }

    protected function calculateAllocationTTL(ResourceAllocation $allocation): int
    {
        return $this->config['allocation_ttl'] + self::LOCK_TIMEOUT;
    }
}
