<?php

namespace App\Core\Recovery;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\ValidationException;
use Psr\Log\LoggerInterface;

class RecoveryValidation implements RecoveryValidationInterface 
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function validateRecoveryPlan(array $plan, string $recoveryId): void 
    {
        $this->logger->info('Starting recovery plan validation', [
            'recovery_id' => $recoveryId
        ]);

        try {
            $this->validatePlanStructure($plan);
            $this->validatePlanSteps($plan['steps']);
            $this->validateValidationSteps($plan['validation']);
            $this->validateRollbackPlan($plan['rollback']);
            $this->validateResourceRequirements($plan);

            $this->logger->info('Recovery plan validation successful', [
                'recovery_id' => $recoveryId
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Recovery plan validation failed', [
                'recovery_id' => $recoveryId,
                'error' => $e->getMessage()
            ]);
            throw new ValidationException('Recovery plan validation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function validateRecoveryExecution(string $recoveryId, array $results): void 
    {
        $this->logger->info('Validating recovery execution', [
            'recovery_id' => $recoveryId
        ]);

        try {
            $this->validateExecutionResults($results);
            $this->validateSystemState();
            $this->validateDataIntegrity();
            $this->validateSecurityStatus();
            $this->validatePerformanceMetrics();

            $this->logger->info('Recovery execution validation successful', [
                'recovery_id' => $recoveryId
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Recovery execution validation failed', [
                'recovery_id' => $recoveryId,
                'error' => $e->getMessage()
            ]);
            throw new ValidationException('Recovery execution validation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function validatePlanStructure(array $plan): void 
    {
        $requiredKeys = ['steps', 'validation', 'rollback', 'resources', 'dependencies'];
        
        foreach ($requiredKeys as $key) {
            if (!isset($plan[$key])) {
                throw new ValidationException("Missing required plan component: {$key}");
            }
        }

        if (empty($plan['steps'])) {
            throw new ValidationException('Recovery plan must contain steps');
        }
    }

    private function validatePlanSteps(array $steps): void 
    {
        foreach ($steps as $step) {
            if (!isset($step['type'], $step['action'], $step['validation'])) {
                throw new ValidationException('Invalid step structure');
            }

            if (!$this->isValidStepType($step['type'])) {
                throw new ValidationException("Invalid step type: {$step['type']}");
            }

            $this->validateStepDependencies($step);
            $this->validateStepResources($step);
            $this->validateStepSecurity($step);
        }
    }

    private function validateValidationSteps(array $validationSteps): void 
    {
        foreach ($validationSteps as $step) {
            if (!isset($step['type'], $step['criteria'], $step['threshold'])) {
                throw new ValidationException('Invalid validation step structure');
            }

            $this->validateValidationCriteria($step['criteria']);
            $this->validateValidationThresholds($step['threshold']);
        }
    }

    private function validateRollbackPlan(array $rollbackPlan): void 
    {
        if (empty($rollbackPlan)) {
            throw new ValidationException('Rollback plan cannot be empty');
        }

        foreach ($rollbackPlan as $step) {
            if (!isset($step['trigger'], $step['action'], $step['verification'])) {
                throw new ValidationException('Invalid rollback step structure');
            }

            $this->validateRollbackTriggers($step['trigger']);
            $this->validateRollbackActions($step['action']);
        }
    }

    private function validateSystemState(): void 
    {
        // Check system readiness for recovery
        if (!$this->checkSystemResources()) {
            throw new ValidationException('Insufficient system resources for recovery');
        }

        if (!$this->checkSystemStability()) {
            throw new ValidationException('System not in stable state for recovery');
        }
    }

    private function validateDataIntegrity(): void 
    {
        if (!$this->verifyDatabaseIntegrity()) {
            throw new ValidationException('Database integrity check failed');
        }

        if (!$this->verifyFileSystemIntegrity()) {
            throw new ValidationException('File system integrity check failed');
        }
    }

    private function validateSecurityStatus(): void 
    {
        $this->security->validateSystemSecurity();
        $this->security->validateAccessControls();
        $this->security->validateEncryptionStatus();
    }

    private function validatePerformanceMetrics(): void 
    {
        $metrics = $this->getSystemPerformanceMetrics();
        
        if ($metrics['cpu_usage'] > $this->config['max_cpu_usage']) {
            throw new ValidationException('CPU usage exceeds maximum threshold');
        }

        if ($metrics['memory_usage'] > $this->config['max_memory_usage']) {
            throw new ValidationException('Memory usage exceeds maximum threshold');
        }
    }

    private function getDefaultConfig(): array 
    {
        return [
            'max_cpu_usage' => 80,
            'max_memory_usage' => 85,
            'required_disk_space' => 5000,  // MB
            'timeout' => 3600,              // seconds
            'validation_retries' => 3
        ];
    }
}
