<?php

namespace App\Core\Error;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Monitoring\MonitoringServiceInterface;
use App\Core\Audit\AuditManagerInterface;
use Illuminate\Support\Facades\Log;

class SystemErrorHandler implements ErrorHandlerInterface
{
    private SecurityManagerInterface $security;
    private MonitoringServiceInterface $monitor;
    private AuditManagerInterface $audit;
    private array $errorLevels;

    public function __construct(
        SecurityManagerInterface $security,
        MonitoringServiceInterface $monitor,
        AuditManagerInterface $audit
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->audit = $audit;
        $this->errorLevels = $this->initializeErrorLevels();
    }

    /**
     * Handle critical system error with complete protection
     */
    public function handleCriticalError(\Throwable $e, array $context = []): void
    {
        $operationId = $this->monitor->startOperation('error.critical');

        try {
            // Log error securely
            $this->logSecureError($e, $context, $operationId);

            // Track in monitoring system
            $this->trackError($e, $operationId);

            // Create security audit entry
            $this->auditError($e, $context, $operationId);

            // Execute recovery procedures
            $this->executeRecoveryProcedures($e, $context);

            // Notify relevant parties
            $this->notifyError($e, $context);

        } catch (\Throwable $fallbackError) {
            // Ultimate fallback logging
            $this->handleFallbackError($fallbackError, $e);
        } finally {
            $this->monitor->stopOperation($operationId);
        }
    }

    /**
     * Log error with security context
     */
    private function logSecureError(\Throwable $e, array $context, string $operationId): void
    {
        $secureContext = $this->security->sanitizeContext(array_merge($context, [
            'error_id' => $operationId,
            'error_type' => get_class($e),
            'error_code' => $e->getCode(),
            'error_file' => $e->getFile(),
            'error_line' => $e->getLine(),
            'system_state' => $this->monitor->getSystemState()
        ]));

        Log::error($e->getMessage(), $secureContext);

        // Record detailed error metrics
        $this->monitor->recordMetric('error.logged', [
            'type' => get_class($e),
            'code' => $e->getCode()
        ]);
    }

    /**
     * Track error in monitoring system
     */
    private function trackError(\Throwable $e, string $operationId): void
    {
        $severity = $this->calculateErrorSeverity($e);

        $this->monitor->recordMetric('error.occurred', [
            'severity' => $severity,
            'type' => get_class($e),
            'operation_id' => $operationId
        ]);

        if ($severity === 'critical') {
            $this->monitor->triggerAlert('critical_error', [
                'error_type' => get_class($e),
                'message' => $e->getMessage(),
                'operation_id' => $operationId
            ]);
        }
    }

    /**
     * Create security audit entry for error
     */
    private function auditError(\Throwable $e, array $context, string $operationId): void
    {
        $this->audit->logSecurityEvent('system.error', [
            'error_type' => get_class($e),
            'error_message' => $e->getMessage(),
            'error_code' => $e->getCode(),
            'error_severity' => $this->calculateErrorSeverity($e),
            'operation_id' => $operationId
        ], $context);
    }

    /**
     * Execute system recovery procedures
     */
    private function executeRecoveryProcedures(\Throwable $e, array $context): void
    {
        $severity = $this->calculateErrorSeverity($e);

        if ($severity === 'critical') {
            // Execute critical error recovery
            $this->executeCriticalRecovery($e, $context);
        } else {
            // Execute standard recovery
            $this->executeStandardRecovery($e, $context);
        }
    }

    /**
     * Critical error recovery process
     */
    private function executeCriticalRecovery(\Throwable $e, array $context): void
    {
        try {
            // Attempt state recovery
            $this->security->recoverSecureState();

            // Verify system integrity
            $this->security->verifySystemIntegrity();

            // Restore critical services
            $this->restoreCriticalServices();

        } catch (\Throwable $recoveryError) {
            // Log recovery failure
            $this->handleRecoveryFailure($recoveryError, $e);
        }
    }

    /**
     * Handle error in the error handler (ultimate fallback)
     */
    private function handleFallbackError(\Throwable $fallbackError, \Throwable $originalError): void
    {
        try {
            // Log to emergency channel
            Log::emergency('Error handler failure', [
                'fallback_error' => $fallbackError->getMessage(),
                'original_error' => $originalError->getMessage(),
                'fallback_trace' => $fallbackError->getTraceAsString(),
                'original_trace' => $originalError->getTraceAsString()
            ]);

        } catch (\Throwable $catastrophicError) {
            // Last resort logging to system log
            error_log('CRITICAL: Error handler complete failure - ' . 
                     $catastrophicError->getMessage());
        }
    }

    private function calculateErrorSeverity(\Throwable $e): string
    {
        return match (true) {
            $e instanceof SecurityException => 'critical',
            $e instanceof DatabaseException => 'critical',
            $e instanceof ValidationException => 'high',
            default => 'standard'
        };
    }

    private function initializeErrorLevels(): array
    {
        return [
            'critical' => [
                'immediate_notification' => true,
                'requires_audit' => true,
                'requires_recovery' => true
            ],
            'high' => [
                'immediate_notification' => true,
                'requires_audit' => true,
                'requires_recovery' => false
            ],
            'standard' => [
                'immediate_notification' => false,
                'requires_audit' => true,
                'requires_recovery' => false
            ]
        ];
    }
}
