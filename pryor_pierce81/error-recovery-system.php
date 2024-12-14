<?php

namespace App\Core\Error;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Core\Security\SecurityContext;
use App\Core\Interfaces\ErrorHandlerInterface;
use App\Core\Monitoring\PerformanceMonitor;

class ErrorHandler implements ErrorHandlerInterface
{
    private SecurityContext $security;
    private PerformanceMonitor $monitor;
    private BackupManager $backup;
    private AlertManager $alerts;
    private array $config;

    private const CRITICAL_ERRORS = [
        'database_failure',
        'security_breach',
        'data_corruption',
        'system_overload',
        'service_unavailable'
    ];

    public function __construct(
        SecurityContext $security,
        PerformanceMonitor $monitor,
        BackupManager $backup,
        AlertManager $alerts,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->backup = $backup;
        $this->alerts = $alerts;
        $this->config = $config;
    }

    public function handleCriticalError(\Throwable $e, array $context = []): void
    {
        $errorId = $this->generateErrorId();
        
        try {
            DB::beginTransaction();

            // Log comprehensive error details
            $this->logError($errorId, $e, $context);

            // Execute immediate recovery steps
            $recoveryStatus = $this->executeRecovery($e, $context);

            // Notify relevant parties
            $this->notifyStakeholders($errorId, $e, $recoveryStatus);

            DB::commit();

        } catch (\Throwable $recoveryError) {
            DB::rollBack();
            
            // Emergency backup procedure
            $this->executeEmergencyProtocol($recoveryError, [
                'original_error' => $e,
                'error_id' => $errorId,
                'context' => $context
            ]);
        }
    }

    public function recoverFromError(string $errorId): RecoveryResult
    {
        $error = $this->getErrorDetails($errorId);
        if (!$error) {
            throw new \RuntimeException('Error details not found');
        }

        return $this->executeRecoveryPlan(
            $error['type'],
            $error['context'],
            $error['recovery_points']
        );
    }

    public function monitorSystemHealth(): HealthStatus
    {
        $metrics = $this->monitor->getMetrics();
        $criticalServices = $this->checkCriticalServices();
        $systemLoad = $this->getCurrentSystemLoad();

        if ($this->isSystemCompromised($metrics, $criticalServices, $systemLoad)) {
            $this->initiateEmergencyProtocols();
        }

        return new HealthStatus([
            'metrics' => $metrics,
            'services' => $criticalServices,
            'system_load' => $systemLoad,
            'status' => $this->determineSystemStatus($metrics, $criticalServices)
        ]);
    }

    protected function executeRecovery(\Throwable $e, array $context): RecoveryStatus
    {
        $errorType = $this->classifyError($e);
        $recoveryPlan = $this->determineRecoveryPlan($errorType, $context);
        
        return DB::transaction(function() use ($recoveryPlan, $context) {
            $status = new RecoveryStatus();

            foreach ($recoveryPlan as $step) {
                try {
                    $result = $this->executeRecoveryStep($step, $context);
                    $status->addStepResult($step, $result);

                    if (!$result->isSuccessful()) {
                        break;
                    }
                } catch (\Throwable $stepError) {
                    $status->addStepError($step, $stepError);
                    break;
                }
            }

            return $status;
        });
    }

    protected function executeEmergencyProtocol(\Throwable $e, array $context): void
    {
        try {
            // Create emergency backup
            $backupId = $this->backup->createEmergencyBackup();

            // Log emergency situation
            Log::emergency('Emergency protocol activated', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'context' => $context,
                'backup_id' => $backupId
            ]);

            // Alert emergency response team
            $this->alerts->emergency('system_failure', [
                'error' => $e->getMessage(),
                'backup_id' => $backupId,
                'context' => $context
            ]);

        } catch (\Throwable $emergencyError) {
            // Last resort logging
            Log::critical('Emergency protocol failed', [
                'error' => $emergencyError->getMessage(),
                'original_error' => $e->getMessage(),
                'context' => $context
            ]);
        }
    }

    protected function classifyError(\Throwable $e): string
    {
        foreach (self::CRITICAL_ERRORS as $errorType) {
            if ($this->matchesErrorPattern($e, $errorType)) {
                return $errorType;
            }
        }

        return 'unknown_error';
    }

    protected function matchesErrorPattern(\Throwable $e, string $errorType): bool
    {
        $patterns = $this->config['error_patterns'][$errorType] ?? [];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $e->getMessage())) {
                return true;
            }
        }

        return false;
    }

    protected function determineRecoveryPlan(string $errorType, array $context): array
    {
        return $this->config['recovery_plans'][$errorType] ?? 
               $this->getDefaultRecoveryPlan();
    }

    protected function executeRecoveryStep(string $step, array $context): StepResult
    {
        $startTime = microtime(true);
        
        try {
            $handler = $this->getStepHandler($step);
            $result = $handler->execute($context);
            
            $this->monitor->recordMetric("recovery_step.$step", [
                'duration' => microtime(true) - $startTime,
                'success' => true
            ]);

            return new StepResult($step, true, $result);

        } catch (\Throwable $e) {
            $this->monitor->recordMetric("recovery_step.$step", [
                'duration' => microtime(true) - $startTime,
                'success' => false,
                'error' => $e->getMessage()
            ]);

            return new StepResult($step, false, null, $e);
        }
    }

    protected function generateErrorId(): string
    {
        return md5(uniqid('err_', true));
    }

    protected function logError(
        string $errorId,
        \Throwable $e,
        array $context
    ): void {
        Log::error("System error [$errorId]", [
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'context' => $this->sanitizeContext($context),
            'user_id' => $this->security->getCurrentUserId(),
            'request_id' => request()->id(),
            'timestamp' => now()
        ]);
    }

    protected function sanitizeContext(array $context): array
    {
        return array_map(function($value) {
            if (is_object($value)) {
                return get_class($value);
            }
            if (is_array($value)) {
                return $this->sanitizeContext($value);
            }
            return $value;
        }, $context);
    }
}
