<?php

namespace App\Core\Monitoring;

class SystemMonitor implements MonitorInterface
{
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private SecurityMonitor $security;
    private PerformanceTracker $performance;
    private RecoveryManager $recovery;
    private AuditLogger $logger;

    public function __construct(
        MetricsCollector $metrics,
        AlertManager $alerts,
        SecurityMonitor $security,
        PerformanceTracker $performance,
        RecoveryManager $recovery,
        AuditLogger $logger
    ) {
        $this->metrics = $metrics;
        $this->alerts = $alerts;
        $this->security = $security;
        $this->performance = $performance;
        $this->recovery = $recovery;
        $this->logger = $logger;
    }

    public function monitorOperation(callable $operation, array $context): mixed
    {
        $operationId = $this->startMonitoring($context);
        
        try {
            $this->validateSystemState();
            $this->checkSecurityStatus();
            
            $result = $this->executeWithMonitoring($operation, $operationId);
            
            $this->verifyOperationResult($result, $context);
            $this->recordSuccess($operationId);
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->handleFailure($e, $operationId);
            throw $e;
        } finally {
            $this->stopMonitoring($operationId);
        }
    }

    private function startMonitoring(array $context): string
    {
        $operationId = uniqid('op_', true);
        
        $this->metrics->startOperation($operationId, [
            'type' => $context['type'] ?? 'unknown',
            'priority' => $context['priority'] ?? 'normal',
            'start_time' => microtime(true)
        ]);
        
        $this->performance->startTracking($operationId);
        $this->security->startMonitoring($operationId);
        
        return $operationId;
    }

    private function validateSystemState(): void
    {
        $systemStatus = $this->getSystemStatus();
        
        if ($systemStatus['memory_usage'] > 85) {
            $this->alerts->triggerAlert('HIGH_MEMORY_USAGE', $systemStatus);
            throw new SystemStateException('System memory critical');
        }
        
        if ($systemStatus['cpu_usage'] > 90) {
            $this->alerts->triggerAlert('HIGH_CPU_USAGE', $systemStatus);
            throw new SystemStateException('System CPU critical');
        }
        
        if (!$this->security->isSystemSecure()) {
            $this->alerts->triggerAlert('SECURITY_THREAT_DETECTED');
            throw new SecurityException('System security compromised');
        }
    }

    private function checkSecurityStatus(): void
    {
        $securityStatus = $this->security->getCurrentStatus();
        
        if ($securityStatus->hasActiveThreats()) {
            $this->alerts->triggerAlert('ACTIVE_SECURITY_THREATS', [
                'threats' => $securityStatus->getThreats()
            ]);
            throw new SecurityException('Active security threats detected');
        }
        
        if ($securityStatus->isUnderAttack()) {
            $this->initiateEmergencyProtocols();
            throw new SecurityException('System under attack');
        }
    }

    private function executeWithMonitoring(callable $operation, string $operationId): mixed
    {
        $start = microtime(true);
        
        try {
            $result = $operation();
            
            $executionTime = microtime(true) - $start;
            $this->performance->recordMetric($operationId, 'execution_time', $executionTime);
            
            if ($executionTime > 1.0) {
                $this->alerts->triggerAlert('SLOW_OPERATION', [
                    'operation_id' => $operationId,
                    'execution_time' => $executionTime
                ]);
            }
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->performance->recordFailure($operationId, $e);
            throw $e;
        }
    }

    private function verifyOperationResult($result, array $context): void
    {
        if ($context['requires_validation'] ?? false) {
            $validation = $this->validateOperationResult($result);
            
            if (!$validation->isValid()) {
                $this->alerts->triggerAlert('INVALID_OPERATION_RESULT', [
                    'errors' => $validation->getErrors()
                ]);
                throw new ValidationException('Operation result validation failed');
            }
        }
    }

    private function handleFailure(\Throwable $e, string $operationId): void
    {
        $this->logger->logError($operationId, $e);
        $this->metrics->recordFailure($operationId, $e);
        
        if ($e instanceof SecurityException) {
            $this->security->handleSecurityFailure($e);
            $this->alerts->triggerAlert('SECURITY_FAILURE', [
                'operation_id' => $operationId,
                'error' => $e->getMessage()
            ]);
        }
        
        if ($this->isRecoverable($e)) {
            $this->recovery->initiateRecovery($operationId, $e);
        }
        
        if ($this->isCritical($e)) {
            $this->initiateEmergencyProtocols();
        }
    }

    private function stopMonitoring(string $operationId): void
    {
        $this->performance->stopTracking($operationId);
        $this->security->stopMonitoring($operationId);
        $this->metrics->completeOperation($operationId);
        
        $this->generateOperationReport($operationId);
    }

    private function getSystemStatus(): array
    {
        return [
            'memory_usage' => memory_get_usage(true) / 1024 / 1024,
            'cpu_usage' => sys_getloadavg()[0] * 100,
            'disk_space' => disk_free_space('/') / disk_total_space('/') * 100,
            'active_connections' => $this->performance->getActiveConnections(),
            'queue_size' => $this->performance->getQueueSize()
        ];
    }

    private function initiateEmergencyProtocols(): void
    {
        $this->alerts->triggerEmergencyAlert();
        $this->security->lockdownSystem();
        $this->recovery->initiateEmergencyRecovery();
    }

    private function generateOperationReport(string $operationId): void
    {
        $report = [
            'metrics' => $this->metrics->getOperationMetrics($operationId),
            'performance' => $this->performance->getMetrics($operationId),
            'security' => $this->security->getReport($operationId)
        ];
        
        $this->logger->logReport($operationId, $report);
    }
}
