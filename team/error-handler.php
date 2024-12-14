<?php

namespace App\Core\Error;

use App\Core\Security\SecurityManager;
use App\Core\Monitoring\MonitoringService;
use App\Core\Logging\LogManager;
use Illuminate\Support\Facades\Log;

class CriticalErrorHandler
{
    private SecurityManager $security;
    private MonitoringService $monitor;
    private LogManager $logger;

    public function handleException(\Throwable $e): void
    {
        try {
            // Record incident
            $incidentId = $this->recordIncident($e);
            
            // Security analysis
            $this->analyzeSecurityImplications($e, $incidentId);
            
            // Critical system check
            $this->checkSystemState();
            
            // Alert administrators
            $this->notifyAdministrators($e, $incidentId);
            
            // Execute recovery procedures
            $this->executeRecoveryProcedures($e);
            
        } catch (\Throwable $secondary) {
            $this->handleCriticalFailure($e, $secondary);
        }
    }

    private function recordIncident(\Throwable $e): string
    {
        $incidentId = $this->generateIncidentId();
        
        $this->logger->critical('System exception', [
            'incident_id' => $incidentId,
            'exception' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ],
            'system_state' => $this->monitor->captureSystemState(),
            'timestamp' => now()->toIso8601String()
        ]);

        return $incidentId;
    }

    private function analyzeSecurityImplications(\Throwable $e, string $incidentId): void
    {
        if ($this->security->isSecurityThreat($e)) {
            $this->security->handleSecurityThreat([
                'incident_id' => $incidentId,
                'exception' => $e,
                'context' => $this->getSecurityContext()
            ]);
        }
    }

    private function checkSystemState(): void
    {
        $healthCheck = $this->monitor->performHealthCheck();
        
        if (!$healthCheck->isHealthy()) {
            $this->handleUnhealthySystem($healthCheck);
        }
    }

    private function notifyAdministrators(\Throwable $e, string $incidentId): void
    {
        $this->security->notifyAdministrators('critical_error', [
            'incident_id' => $incidentId,
            'message' => $e->getMessage(),
            'severity' => $this->determineSeverity($e),
            'timestamp' => now()->toIso8601String()
        ]);
    }

    private function executeRecoveryProcedures(\Throwable $e): void
    {
        $recoveryPlan = $this->determineRecoveryPlan($e);
        
        foreach ($recoveryPlan as $procedure) {
            try {
                $procedure->execute();
            } catch (\Throwable $recoveryError) {
                $this->handleRecoveryFailure($recoveryError);
            }
        }
    }

    private function handleCriticalFailure(\Throwable $primary, \Throwable $secondary): void
    {
        // Last resort logging
        Log::emergency('Critical error handler failure', [
            'primary_error' => [
                'message' => $primary->getMessage(),
                'trace' => $primary->getTraceAsString()
            ],
            'secondary_error' => [
                'message' => $secondary->getMessage(),
                'trace' => $secondary->getTraceAsString()
            ],
            'timestamp' => now()->toIso8601String()
        ]);

        // Emergency notifications
        try {
            $this->security->sendEmergencyAlert([
                'primary_error' => $primary->getMessage(),
                'secondary_error' => $secondary->getMessage()
            ]);
        } catch (\Throwable) {
            // Silent fail - we're in critical failure mode
        }
    }

    private function generateIncidentId(): string
    {
        return sprintf(
            '%s-%s-%s',
            date('YmdHis'),
            substr(md5(uniqid()), 0, 8),
            random_int(1000, 9999)
        );
    }

    private function getSecurityContext(): array
    {
        return [
            'request' => request()->all(),
            'user' => auth()->user()?->id,
            'ip' => request()->ip(),
            'url' => request()->fullUrl(),
            'method' => request()->method(),
            'headers' => request()->headers->all()
        ];
    }

    private function determineSeverity(\Throwable $e): string
    {
        if ($this->security->isSecurityThreat($e)) {
            return 'critical';
        }

        if ($e instanceof SystemException) {
            return 'high';
        }

        return 'medium';
    }

    private function determineRecoveryPlan(\Throwable $e): array
    {
        return match(true) {
            $e instanceof DatabaseException => [
                new DatabaseRecoveryProcedure(),
                new CacheRecoveryProcedure()
            ],
            $e instanceof SecurityException => [
                new SecurityRecoveryProcedure(),
                new SystemStateProcedure()
            ],
            default => [
                new StandardRecoveryProcedure()
            ]
        };
    }

    private function handleUnhealthySystem(HealthCheck $healthCheck): void
    {
        $this->logger->alert('Unhealthy system state detected', [
            'health_check' => $healthCheck->toArray(),
            'timestamp' => now()->toIso8601String()
        ]);

        $this->security->notifyAdministrators('unhealthy_system', [
            'health_check' => $healthCheck->toArray()
        ]);
    }

    private function handleRecoveryFailure(\Throwable $e): void
    {
        $this->logger->emergency('Recovery procedure failed', [
            'error' => [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ],
            'timestamp' => now()->toIso8601String()
        ]);

        $this->security->notifyAdministrators('recovery_failure', [
            'error' => $e->getMessage()
        ]);
    }
}
