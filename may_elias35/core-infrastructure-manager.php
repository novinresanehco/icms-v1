<?php

namespace App\Core\Infrastructure;

use App\Core\Infrastructure\Monitoring\MonitoringService;
use App\Core\Infrastructure\Resource\ResourceManager;
use App\Core\Infrastructure\Health\HealthCheck;
use App\Core\Cache\CacheManager;
use App\Core\Audit\AuditLogger;

class CoreInfrastructureManager implements InfrastructureManagerInterface
{
    private MonitoringService $monitor;
    private ResourceManager $resources;
    private HealthCheck $health;
    private CacheManager $cache;
    private AuditLogger $audit;

    public function __construct(
        MonitoringService $monitor,
        ResourceManager $resources,
        HealthCheck $health,
        CacheManager $cache,
        AuditLogger $audit
    ) {
        $this->monitor = $monitor;
        $this->resources = $resources;
        $this->health = $health;
        $this->cache = $cache;
        $this->audit = $audit;
    }

    public function executeInfraOperation(InfraOperation $operation): OperationResult
    {
        $startTime = microtime(true);

        try {
            $this->validateSystemState();
            $this->validateOperation($operation);
            
            $result = $this->executeProtectedOperation($operation);
            
            $this->validateResult($result);
            $this->updateMetrics($operation, $startTime);
            
            return $result;

        } catch (\Exception $e) {
            $this->handleFailure($e, $operation);
            throw $e;
        }
    }

    private function validateSystemState(): void
    {
        if (!$this->health->isSystemHealthy()) {
            throw new SystemHealthException('System health check failed');
        }

        if (!$this->resources->hasAvailableCapacity()) {
            throw new ResourceException('Insufficient system resources');
        }

        if ($this->monitor->hasActiveAlerts()) {
            throw new MonitoringException('Active system alerts detected');
        }
    }

    private function validateOperation(InfraOperation $operation): void
    {
        if (!$this->resources->validateResourceRequirements($operation)) {
            throw new ResourceValidationException('Resource requirements not met');
        }

        if (!$this->monitor->validateOperationLimits($operation)) {
            throw new LimitException('Operation exceeds system limits');
        }
    }

    private function executeProtectedOperation(InfraOperation $operation): OperationResult
    {
        $context = $this->createOperationContext();

        try {
            $result = $operation->execute($context);
            $this->resources->releaseResources($operation);
            return $result;

        } catch (\Exception $e) {
            $this->handleExecutionFailure($e, $operation);
            throw $e;
        }
    }

    private function validateResult(OperationResult $result): void
    {
        if (!$this->monitor->validateResultMetrics($result)) {
            throw new MonitoringException('Result metrics validation failed');
        }

        if (!$this->resources->validateResourceUsage($result)) {
            throw new ResourceException('Excessive resource usage detected');
        }
    }

    private function createOperationContext(): InfraContext
    {
        return new InfraContext(
            $this->monitor,
            $this->resources,
            $this->health
        );
    }

    private function updateMetrics(InfraOperation $operation, float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        $this->monitor->recordOperationMetrics($operation, $duration);
        $this->resources->updateUsageMetrics($operation);
    }

    private function handleExecutionFailure(\Exception $e, InfraOperation $operation): void
    {
        $this->resources->releaseResources($operation);
        $this->monitor->recordFailure($operation);
        $this->audit->logFailure($operation, $e);
    }

    private function handleFailure(\Exception $e, InfraOperation $operation): void
    {
        $this->monitor->recordFailure($operation);
        $this->resources->releaseResources($operation);
        $this->audit->logFailure($operation, $e);

        if ($e instanceof SystemHealthException) {
            $this->health->initiateRecovery();
        }

        if ($e instanceof ResourceException) {
            $this->resources->rebalance();
        }
    }
}
