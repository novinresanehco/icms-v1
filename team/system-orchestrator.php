<?php

namespace App\Core\Orchestration;

class SystemOrchestrator implements OrchestratorInterface
{
    private SecurityManager $security;
    private StateManager $state;
    private HealthMonitor $health;
    private EmergencyProtocol $emergency;
    private AuditLogger $audit;

    public function __construct(
        SecurityManager $security,
        StateManager $state,
        HealthMonitor $health,
        EmergencyProtocol $emergency,
        AuditLogger $audit
    ) {
        $this->security = $security;
        $this->state = $state;
        $this->health = $health;
        $this->emergency = $emergency;
        $this->audit = $audit;
    }

    public function orchestrate(SystemCommand $command): ExecutionResult
    {
        DB::beginTransaction();
        
        try {
            // Verify system state
            $this->verifySystemState();
            
            // Execute with protection
            $result = $this->executeProtected($command);
            
            // Validate result
            $this->validateExecution($result);
            
            DB::commit();
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->handleExecutionFailure($e, $command);
        }
    }

    private function verifySystemState(): void
    {
        $state = $this->state->getCurrentState();
        
        if (!$state->isOperational()) {
            throw new SystemStateException($state->getFailureReason());
        }

        // Verify critical components
        $this->verifyComponents();
    }

    private function verifyComponents(): void
    {
        $checks = [
            'auth' => $this->security->verifyAuthentication(),
            'cms' => $this->state->verifyCMS(),
            'templates' => $this->state->verifyTemplates(),
            'infrastructure' => $this->state->verifyInfrastructure()
        ];

        foreach ($checks as $component => $status) {
            if (!$status->isOperational()) {
                throw new ComponentException("Component failure: {$component}");
            }
        }
    }

    private function executeProtected(SystemCommand $command): ExecutionResult
    {
        // Initialize monitoring
        $monitor = $this->health->startMonitoring($command);
        
        try {
            // Execute with safeguards
            $result = $this->executeWithSafeguards($command, $monitor);
            
            // Verify execution integrity
            $this->verifyExecutionIntegrity($result);
            
            return $result;
            
        } catch (\Exception $e) {
            $monitor->recordFailure($e);
            throw $e;
        } finally {
            $monitor->stop();
        }
    }

    private function executeWithSafeguards(
        SystemCommand $command, 
        ExecutionMonitor $monitor
    ): ExecutionResult {
        // Apply security controls
        $this->security->enforceControls($command);
        
        // Execute with resource limits
        return $monitor->executeWithLimits(
            fn() => $command->execute(),
            [
                'timeout' => 30,
                'memory' => '128M',
                'cpu' => 80
            ]
        );
    }

    private function verifyExecutionIntegrity(ExecutionResult $result): void
    {
        if (!$result->isValid()) {
            throw new IntegrityException('Execution result validation failed');
        }

        $this->verifySystemConsistency();
    }

    private function verifySystemConsistency(): void
    {
        // Check critical metrics
        $metrics = $this->health->getCriticalMetrics();
        
        foreach ($metrics as $metric => $value) {
            if (!$this->isWithinThreshold($metric, $value)) {
                throw new SystemInconsistencyException("Metric out of range: {$metric}");
            }
        }
    }

    private function handleExecutionFailure(
        \Exception $e, 
        SystemCommand $command
    ): ExecutionResult {
        // Log failure
        $this->audit->logExecutionFailure($command, $e);
        
        // Check for critical failure
        if ($this->isCriticalFailure($e)) {
            $this->handleCriticalFailure($e);
        }
        
        // Return failure result
        return new ExecutionResult(false, null, $e);
    }

    private function handleCriticalFailure(\Exception $e): void
    {
        // Initiate emergency protocol
        $this->emergency->initiate(
            error: $e,
            context: $this->state->getCurrentState(),
            severity: Severity::CRITICAL
        );

        // Notify stakeholders
        $this->notifyCriticalStakeholders($e);
    }

    private function isCriticalFailure(\Exception $e): bool
    {
        return $e instanceof SecurityException ||
               $e instanceof DataCorruptionException ||
               $e instanceof SystemFailureException;
    }

    public function getSystemStatus(): SystemStatus
    {
        return new SystemStatus([
            'state' => $this->state->getCurrentState(),
            'health' => $this->health->getCurrentStatus(),
            'security' => $this->security->getCurrentStatus(),
            'components' => $this->getComponentStatus()
        ]);
    }

    private function getComponentStatus(): array
    {
        return [
            'auth' => $this->security->getAuthStatus(),
            'cms' => $this->state->getCMSStatus(),
            'templates' => $this->state->getTemplateStatus(),
            'infrastructure' => $this->state->getInfrastructureStatus()
        ];
    }
}
