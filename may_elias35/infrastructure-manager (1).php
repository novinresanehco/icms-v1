<?php

namespace App\Core\Infrastructure;

use App\Core\Monitoring\SystemMonitor;
use App\Core\Cache\CacheManager;
use App\Core\Storage\StorageManager;
use App\Core\Audit\AuditLogger;

class InfrastructureManager implements InfrastructureInterface
{
    private SystemMonitor $monitor;
    private CacheManager $cache;
    private StorageManager $storage;
    private AuditLogger $audit;

    private const PERFORMANCE_THRESHOLD = [
        'cpu' => 70,
        'memory' => 80,
        'response_time' => 200
    ];

    public function __construct(
        SystemMonitor $monitor,
        CacheManager $cache,
        StorageManager $storage,
        AuditLogger $audit
    ) {
        $this->monitor = $monitor;
        $this->cache = $cache;
        $this->storage = $storage;
        $this->audit = $audit;
    }

    public function executeSystemOperation(SystemOperation $operation): OperationResult
    {
        try {
            // Start monitoring
            $monitoringId = $this->monitor->startOperation($operation);
            
            // Check system health
            $this->verifySystemHealth();
            
            // Execute operation
            $result = $this->executeOperation($operation);
            
            // Verify system state
            $this->verifySystemState();
            
            // Log success
            $this->audit->logSystemOperation($operation, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->handleSystemFailure($e, $operation);
            throw $e;
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function verifySystemHealth(): void
    {
        $health = $this->monitor->checkSystemHealth();
        
        if ($health->getCpuUsage() > self::PERFORMANCE_THRESHOLD['cpu']) {
            throw new SystemOverloadException('CPU usage exceeds threshold');
        }
        
        if ($health->getMemoryUsage() > self::PERFORMANCE_THRESHOLD['memory']) {
            throw new SystemOverloadException('Memory usage exceeds threshold');
        }
        
        if (!$this->cache->isHealthy() || !$this->storage->isHealthy()) {
            throw new SystemHealthException('Critical system components unhealthy');
        }
    }

    private function executeOperation(SystemOperation $operation): OperationResult
    {
        $this->optimizeResources($operation);
        
        return match ($operation->getType()) {
            'cache' => $this->executeCacheOperation($operation),
            'storage' => $this->executeStorageOperation($operation),
            'system' => $this->executeSystemTask($operation),
            default => throw new UnsupportedOperationException()
        };
    }

    private function verifySystemState(): void
    {
        $state = $this->monitor->captureSystemState();
        
        if ($state->getResponseTime() > self::PERFORMANCE_THRESHOLD['response_time']) {
            throw new PerformanceException('System response time exceeds threshold');
        }
        
        if (!$state->isStable()) {
            throw new SystemInstabilityException('System state unstable');
        }
    }

    private function handleSystemFailure(\Exception $e, SystemOperation $operation): void
    {
        $this->audit->logCriticalFailure($e, [
            'operation' => $operation->getId(),
            'type' => $operation->getType(),
            'system_state' => $this->monitor->captureSystemState(),
            'timestamp' => now()
        ]);
        
        if ($this->isCriticalFailure($e)) {
            $this->executeCriticalRecovery($operation);
        }
    }

    private function optimizeResources(SystemOperation $operation): void
    {
        $this->cache->optimize($operation->getCacheRequirements());
        $this->storage->optimize($operation->getStorageRequirements());
    }

    private function isCriticalFailure(\Exception $e): bool
    {
        return $e instanceof SystemCriticalException ||
               $e instanceof ResourceExhaustionException;
    }

    private function executeCriticalRecovery(SystemOperation $operation): void
    {
        $this->monitor->triggerCriticalAlert();
        $this->cache->emergencyCleanup();
        $this->storage->emergencyCleanup();
    }
}
