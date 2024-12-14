<?php

namespace App\Core\Infrastructure;

use App\Core\Infrastructure\Resource\ResourceManager;
use App\Core\Infrastructure\Health\HealthCheckService;
use App\Core\Infrastructure\Cache\CacheService;
use App\Core\Monitoring\MetricsCollector;
use App\Core\Audit\AuditLogger;

class CoreInfrastructureService implements InfrastructureInterface
{
    private ResourceManager $resources;
    private HealthCheckService $health;
    private CacheService $cache;
    private MetricsCollector $metrics;
    private AuditLogger $audit;

    private const HEALTH_CHECK_INTERVAL = 30;
    private const CACHE_TTL = 3600;
    private const MAX_RESOURCE_USAGE = 80;

    public function __construct(
        ResourceManager $resources,
        HealthCheckService $health,
        CacheService $cache,
        MetricsCollector $metrics,
        AuditLogger $audit
    ) {
        $this->resources = $resources;
        $this->health = $health;
        $this->cache = $cache;
        $this->metrics = $metrics;
        $this->audit = $audit;
    }

    public function executeInfraOperation(InfraOperation $operation): OperationResult
    {
        $operationId = $this->metrics->startOperation();

        try {
            // System validation
            $this->validateSystemState();

            // Resource allocation
            $this->allocateResources($operation);

            // Execute operation
            $result = $this->executeOperation($operation);

            // Verify results
            $this->verifyOperationResult($result);

            // Record metrics
            $this->recordOperationMetrics($operation);

            return $result;

        } catch (\Exception $e) {
            $this->handleOperationFailure($e, $operation);
            throw $e;
        } finally {
            $this->metrics->endOperation($operationId);
            $this->releaseResources($operation);
        }
    }

    private function validateSystemState(): void
    {
        if (!$this->health->isSystemHealthy()) {
            throw new InfrastructureException('System health check failed');
        }

        if ($this->resources->isOverloaded()) {
            throw new ResourceException('System resources overloaded');
        }

        if (!$this->cache->isOperational()) {
            throw new CacheException('Cache service not operational');
        }
    }

    private function allocateResources(InfraOperation $operation): void
    {
        if (!$this->resources->allocate($operation->getResourceRequirements())) {
            throw new ResourceException('Failed to allocate required resources');
        }

        $this->audit->logAllocation(
            'resource_allocation',
            [
                'operation' => $operation->getId(),
                'resources' => $operation->getResourceRequirements()
            ]
        );
    }

    private function executeOperation(InfraOperation $operation): OperationResult
    {
        $context = $this->createOperationContext();

        try {
            return $operation->execute($context);
        } catch (\Exception $e) {
            $this->handleExecutionFailure($e, $operation);
            throw $e;
        }
    }

    private function verifyOperationResult(OperationResult $result): void
    {
        if (!$result->isValid()) {
            throw new OperationException('Invalid operation result');
        }

        if (!$this->verifyResourceUsage($result)) {
            throw new ResourceException('Resource usage verification failed');
        }

        if (!$this->verifyPerformance($result)) {
            throw new PerformanceException('Performance verification failed');
        }
    }

    private function createOperationContext(): OperationContext
    {
        return new OperationContext(
            $this->resources,
            $this->health,
            $this->cache
        );
    }

    private function verifyResourceUsage(OperationResult $result): bool
    {
        return $this->resources->verifyUsage($result->getResourceUsage());
    }

    private function verifyPerformance(OperationResult $result): bool
    {
        return $result->getPerformanceMetrics()->isWithinLimits();
    }

    private function recordOperationMetrics(InfraOperation $operation): void
    {
        $this->metrics->record([
            'operation_id' => $operation->getId(),
            'resource_usage' => $this->resources->getCurrentUsage(),
            'performance' => $this->health->getPerformanceMetrics(),
            'timestamp' => now()
        ]);
    }

    private function releaseResources(InfraOperation $operation): void
    {
        $this->resources->release($operation->getResourceRequirements());
        
        $this->audit->logRelease(
            'resource_release',
            [
                'operation' => $operation->getId(),
                'timestamp' => now()
            ]
        );
    }

    private function handleExecutionFailure(\Exception $e, InfraOperation $operation): void
    {
        $this->audit->logFailure(
            'operation_execution_failed',
            [
                'operation' => $operation->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]
        );

        $this->metrics->recordFailure(
            'execution',
            [
                'operation' => $operation->getId(),
                'error_type' => get_class($e)
            ]
        );
    }

    private function handleOperationFailure(\Exception $e, InfraOperation $operation): void
    {
        $this->audit->logFailure(
            'infrastructure_operation_failed',
            [
                'operation' => $operation->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]
        );

        if ($e instanceof ResourceException) {
            $this->resources->handleResourceFailure($operation);
        }

        if ($e instanceof PerformanceException) {
            $this->health->handlePerformanceFailure();
        }

        $this->metrics->recordFailure(
            'infrastructure',
            [
                'operation' => $operation->getId(),
                'error_type' => get_class($e)
            ]
        );
    }
}
