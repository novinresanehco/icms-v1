<?php

namespace App\Core\Monitoring;

class SystemMonitor implements CriticalMonitorInterface
{
    private SecurityMonitor $security;
    private PerformanceMonitor $performance;
    private ResourceMonitor $resources;
    private AlertSystem $alerts;
    private ProtectionSystem $protection;

    public function monitorOperation(Operation $operation): OperationResult
    {
        // Initialize monitoring
        $this->initializeMonitoring();
        $systemState = $this->captureSystemState();
        
        try {
            // Pre-execution validation
            $this->validateSystemState();
            $this->protection->activateDefenses();

            // Execute with monitoring
            $result = $this->executeWithMonitoring($operation);

            // Post-execution verification
            $this->verifySystemState();
            $this->protection->verifyDefenses();

            return $result;

        } catch (SecurityException $e) {
            $this->handleSecurityFailure($e, $systemState);
            throw $e;
        } catch (SystemException $e) {
            $this->handleSystemFailure($e, $systemState);
            throw $e;
        } finally {
            $this->finalizeMonitoring();
        }
    }

    private function executeWithMonitoring(Operation $operation): OperationResult
    {
        $metrics = [
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(true),
            'cpu_start' => sys_getloadavg()[0]
        ];

        try {
            $result = $operation->execute();

            $this->recordMetrics($operation, $metrics);
            $this->verifyOperationLimits($metrics);

            return $result;

        } catch (\Exception $e) {
            $this->recordFailure($operation, $e, $metrics);
            throw $e;
        }
    }

    private function validateSystemState(): void
    {
        if (!$this->security->isSystemSecure()) {
            throw new SecurityStateException('System security compromised');
        }

        if (!$this->performance->isWithinLimits()) {
            throw new PerformanceException('System performance degraded');
        }

        if (!$this->resources->hasAvailableResources()) {
            throw new ResourceException('Insufficient system resources');
        }
    }

    private function recordMetrics(Operation $operation, array $metrics): void
    {
        $endMetrics = [
            'duration' => microtime(true) - $metrics['start_time'],
            'memory_used' => memory_get_usage(true) - $metrics['start_memory'],
            'cpu_usage' => sys_getloadavg()[0] - $metrics['cpu_start']
        ];

        $this->performance->recordOperationMetrics($operation, $endMetrics);
        $this->resources->updateResourceUsage($endMetrics);
        $this->security->recordSecurityMetrics($operation);
    }

    private function verifyOperationLimits(array $metrics): void
    {
        if ($metrics['duration'] > $this->performance->getMaxDuration()) {
            throw new PerformanceException('Operation exceeded time limit');
        }

        if ($metrics['memory_used'] > $this->resources->getMemoryLimit()) {
            throw new ResourceException('Operation exceeded memory limit');
        }

        if ($metrics['cpu_usage'] > $this->resources->getCpuLimit()) {
            throw new ResourceException('Operation exceeded CPU limit');
        }
    }

    private function handleSecurityFailure(SecurityException $e, SystemState $state): void
    {
        $this->alerts->triggerSecurityAlert($e);
        $this->protection->activateEmergencyProtocol();
        $this->security.lockdownSystem();
        
        $this->logCriticalFailure('security_breach', [
            'exception' => $e,
            'system_state' => $state,
            'current_state' => $this->captureSystemState()
        ]);
    }

    private function handleSystemFailure(SystemException $e, SystemState $state): void
    {
        $this->alerts->triggerSystemAlert($e);
        $this->protection->initiateFailsafe();
        $this->resources->releaseResources();

        $this->logCriticalFailure('system_failure', [
            'exception' => $e,
            'system_state' => $state,
            'resource_usage' => $this->resources->getCurrentUsage()
        ]);
    }

    private function logCriticalFailure(string $type, array $context): void
    {
        Log::critical("Critical system failure: $type", $context);
        $this->alerts->notifyAdministrators($type, $context);
        $this->security->recordSecurityIncident($type, $context);
    }

    private function captureSystemState(): SystemState
    {
        return new SystemState([
            'security' => $this->security->getCurrentState(),
            'performance' => $this->performance->getCurrentMetrics(),
            'resources' => $this->resources->getCurrentUsage(),
            'alerts' => $this->alerts->getActiveAlerts(),
            'timestamp' => microtime(true)
        ]);
    }

    private function initializeMonitoring(): void
    {
        $this->security->startMonitoring();
        $this->performance->startTracking();
        $this->resources->startMonitoring();
        $this->protection->initialize();
    }

    private function finalizeMonitoring(): void
    {
        $this->security->stopMonitoring();
        $this->performance->stopTracking();
        $this->resources->stopMonitoring();
        $this->protection->finalize();
    }
}
