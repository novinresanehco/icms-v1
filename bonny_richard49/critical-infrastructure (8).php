<?php

namespace App\Core\Infrastructure;

use App\Core\Exceptions\{
    InfrastructureException,
    SecurityException,
    PerformanceException
};

/**
 * CRITICAL INFRASTRUCTURE MANAGEMENT
 * Zero-error tolerance infrastructure layer
 */
class InfrastructureManager
{
    private SecurityService $security;
    private PerformanceMonitor $monitor;
    private ResourceManager $resources;
    private BackupService $backup;
    private LogManager $logger;

    public function __construct(
        SecurityService $security,
        PerformanceMonitor $monitor,
        ResourceManager $resources,
        BackupService $backup,
        LogManager $logger
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->resources = $resources;
        $this->backup = $backup;
        $this->logger = $logger;
    }

    public function executeCriticalOperation(callable $operation, array $context): mixed
    {
        // Initialize monitoring
        $monitoringId = $this->monitor->startOperation();
        
        // Create system snapshot
        $backupId = $this->backup->createSystemSnapshot();
        
        try {
            // Pre-execution checks
            $this->performPreExecutionChecks($context);
            
            // Allocate resources
            $this->resources->allocateForOperation($context);
            
            // Execute with full protection
            $result = $this->executeProtected($operation, $context);
            
            // Verify system state
            $this->verifySystemState();
            
            return $result;
            
        } catch (\Throwable $e) {
            // Restore system state
            $this->backup->restoreSnapshot($backupId);
            
            // Log failure
            $this->logFailure($e, $context, $monitoringId);
            
            throw new InfrastructureException(
                'Infrastructure operation failed',
                previous: $e
            );
        } finally {
            // Cleanup
            $this->resources->releaseResources();
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function performPreExecutionChecks(array $context): void
    {
        // Verify security state
        if (!$this->security->verifySystemSecurity()) {
            throw new SecurityException('Security check failed');
        }

        // Check system performance
        if (!$this->monitor->checkSystemHealth()) {
            throw new PerformanceException('System health check failed');
        }

        // Verify resource availability
        if (!$this->resources->checkAvailability($context)) {
            throw new InfrastructureException('Insufficient resources');
        }
    }

    private function executeProtected(callable $operation, array $context): mixed
    {
        return $this->security->executeSecure(function() use ($operation, $context) {
            return $this->monitor->trackExecution(function() use ($operation, $context) {
                return $operation($context);
            });
        });
    }

    private function verifySystemState(): void
    {
        $metrics = $this->monitor->getSystemMetrics();
        
        if ($metrics['cpu_usage'] > 80 || $metrics['memory_usage'] > 80) {
            throw new PerformanceException('System resource threshold exceeded');
        }
        
        if (!$this->security->verifyIntegrity()) {
            throw new SecurityException('System integrity check failed');
        }
    }

    private function logFailure(\Throwable $e, array $context, string $monitoringId): void
    {
        $this->logger->critical('Infrastructure failure', [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'context' => $context,
            'monitoring_id' => $monitoringId,
            'system_state' => $this->monitor->getSystemState()
        ]);
    }
}
