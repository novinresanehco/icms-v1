<?php

namespace App\Core\Recovery;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\ExecutionException;
use Psr\Log\LoggerInterface;

class RecoveryExecutor implements RecoveryExecutorInterface 
{
    private SecurityManagerInterface $security;
    private RecoveryValidationInterface $validator;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        RecoveryValidationInterface $validator,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function executeRecoveryPlan(string $recoveryId, array $plan): array 
    {
        $this->logger->info('Starting recovery plan execution', [
            'recovery_id' => $recoveryId
        ]);

        $results = [];
        $executionContext = $this->createExecutionContext($recoveryId);

        try {
            DB::beginTransaction();

            $this->security->validateContext('recovery:execute');
            $this->validator->validateRecoveryPlan($plan, $recoveryId);

            foreach ($plan['steps'] as $step) {
                $stepResult = $this->executeStep($step, $executionContext);
                $results[] = $stepResult;

                $this->validateStepExecution($step, $stepResult);
                $this->updateExecutionContext($executionContext, $stepResult);
            }

            $this->validator->validateRecoveryExecution($recoveryId, $results);
            
            DB::commit();
            
            $this->logger->info('Recovery plan execution completed successfully', [
                'recovery_id' => $recoveryId
            ]);

            return $results;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleExecutionFailure($recoveryId, $plan, $results, $e);
            throw new ExecutionException('Recovery execution failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function executeStep(array $step, array $context): array 
    {
        $this->logger->info('Executing recovery step', [
            'type' => $step['type'],
            'recovery_id' => $context['recovery_id']
        ]);

        try {
            $this->validatePreStepConditions($step, $context);
            
            $result = match($step['type']) {
                'database' => $this->executeDatabaseRecovery($step, $context),
                'files' => $this->executeFileRecovery($step, $context),
                'configuration' => $this->executeConfigurationRecovery($step, $context),
                default => throw new ExecutionException("Unknown step type: {$step['type']}")
            };

            $this->validatePostStepConditions($step, $result, $context);
            
            return $result;

        } catch (\Exception $e) {
            $this->logger->error('Step execution failed', [
                'step' => $step,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function executeDatabaseRecovery(array $step, array $context): array 
    {
        // Implementation for database recovery
        $this->validateDatabaseState();
        $this->backupCurrentState();
        
        try {
            $result = $this->performDatabaseRecovery($step['data']);
            $this->verifyDatabaseRecovery($result);
            return $result;
        } catch (\Exception $e) {
            $this->rollbackDatabaseState();
            throw $e;
        }
    }

    private function executeFileRecovery(array $step, array $context): array 
    {
        // Implementation for file recovery
        $this->validateFileSystem();
        $this->backupCurrentFiles();
        
        try {
            $result = $this->performFileRecovery($step['data']);
            $this->verifyFileRecovery($result);
            return $result;
        } catch (\Exception $e) {
            $this->rollbackFileSystem();
            throw $e;
        }
    }

    private function executeConfigurationRecovery(array $step, array $context): array 
    {
        // Implementation for configuration recovery
        $this->validateConfigurationState();
        $this->backupCurrentConfig();
        
        try {
            $result = $this->performConfigRecovery($step['data']);
            $this->verifyConfigRecovery($result);
            return $result;
        } catch (\Exception $e) {
            $this->rollbackConfiguration();
            throw $e;
        }
    }

    private function createExecutionContext(string $recoveryId): array 
    {
        return [
            'recovery_id' => $recoveryId,
            'start_time' => microtime(true),
            'steps_completed' => [],
            'system_state' => $this->captureSystemState(),
            'security_context' => $this->security->getCurrentContext()
        ];
    }

    private function handleExecutionFailure(string $recoveryId, array $plan, array $results, \Exception $e): void 
    {
        $this->logger->error('Recovery execution failed', [
            'recovery_id' => $recoveryId,
            'error' => $e->getMessage(),
            'results' => $results
        ]);

        try {
            $this->executeRollbackPlan($plan['rollback'], $results);
        } catch (\Exception $rollbackError) {
            $this->logger->critical('Rollback failed', [
                'recovery_id' => $recoveryId,
                'error' => $rollbackError->getMessage()
            ]);
        }
    }

    private function getDefaultConfig(): array 
    {
        return [
            'execution_timeout' => 3600,
            'step_timeout' => 300,
            'max_retries' => 3,
            'verification_delay' => 5
        ];
    }
}
