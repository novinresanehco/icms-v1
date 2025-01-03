<?php

namespace App\Core\Error;

use Illuminate\Support\Facades\Log;
use App\Core\Monitoring\AlertManager;
use App\Core\Security\AuditService;
use App\Core\Cache\CacheManager;
use App\Core\Interfaces\ErrorHandlerInterface;

/**
 * Critical Error Handler - Core system error management and recovery
 * 
 * This class is responsible for centralized error management,
 * recovery protocols, logging integration, and alert triggering.
 * NO MODIFICATION without Security Team approval.
 */
class ErrorHandler implements ErrorHandlerInterface
{
    protected AlertManager $alertManager;
    protected AuditService $auditService;
    protected CacheManager $cacheManager;
    protected array $criticalErrors = [];
    protected bool $emergencyMode = false;

    public function __construct(
        AlertManager $alertManager,
        AuditService $auditService,
        CacheManager $cacheManager
    ) {
        $this->alertManager = $alertManager;
        $this->auditService = $auditService;
        $this->cacheManager = $cacheManager;
        
        $this->initializeErrorHandler();
    }

    /**
     * Handle critical system error with comprehensive protection
     *
     * @param \Throwable $error The error to handle
     * @param array $context Additional context information
     * @throws SystemFailureException If error recovery fails
     */
    public function handleCriticalError(\Throwable $error, array $context = []): void
    {
        try {
            // Enter emergency mode
            $this->enterEmergencyMode();
            
            // Log critical error with full context
            $this->logCriticalError($error, $context);
            
            // Execute recovery protocol
            $this->executeRecoveryProtocol($error, $context);
            
            // Send critical alerts
            $this->triggerCriticalAlerts($error, $context);
            
            // Audit security implications
            $this->auditSecurityImpact($error, $context);
            
            // Attempt system stabilization
            $this->stabilizeSystem();
            
        } catch (\Throwable $recoveryError) {
            // Log recovery failure
            $this->logRecoveryFailure($recoveryError);
            
            // Escalate to system administrators
            $this->escalateToAdmin($error, $recoveryError);
            
            throw new SystemFailureException(
                'Critical error recovery failed: ' . $error->getMessage(),
                previous: $error
            );
        } finally {
            // Always attempt to exit emergency mode
            $this->exitEmergencyMode();
        }
    }

    /**
     * Handle non-critical errors with appropriate logging and alerts
     */
    public function handleError(\Throwable $error, array $context = []): void
    {
        // Log error with context
        Log::error($error->getMessage(), [
            'exception' => $error,
            'context' => $context,
            'trace' => $error->getTraceAsString()
        ]);

        // Trigger appropriate alerts
        $this->alertManager->triggerAlert(
            'system_error',
            $error->getMessage(),
            $this->buildAlertContext($error, $context)
        );

        // Record for audit
        $this->auditService->logError($error, $context);
    }

    /**
     * Initialize critical error handlers and recovery systems
     */
    protected function initializeErrorHandler(): void
    {
        set_error_handler([$this, 'handlePhpError']);
        set_exception_handler([$this, 'handleUncaughtException']);
        register_shutdown_function([$this, 'handleFatalError']);
    }

    /**
     * Enter system emergency mode
     */
    protected function enterEmergencyMode(): void
    {
        if ($this->emergencyMode) {
            return;
        }

        $this->emergencyMode = true;
        $this->cacheManager->set('system.emergency_mode', true);
        
        // Notify monitoring systems
        $this->alertManager->triggerAlert(
            'system_emergency',
            'System entered emergency mode',
            ['timestamp' => time()]
        );
    }

    /**
     * Execute comprehensive error recovery protocol
     */
    protected function executeRecoveryProtocol(\Throwable $error, array $context): void
    {
        // Validate system state
        $this->validateSystemState();
        
        // Attempt data recovery if needed
        $this->recoverData($context);
        
        // Restore system services
        $this->restoreServices();
        
        // Verify system stability
        $this->verifySystemStability();
    }

    /**
     * Build comprehensive alert context
     */
    protected function buildAlertContext(\Throwable $error, array $context): array
    {
        return [
            'error_type' => get_class($error),
            'message' => $error->getMessage(),
            'code' => $error->getCode(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'trace' => $error->getTraceAsString(),
            'context' => $context,
            'system_state' => $this->captureSystemState(),
            'timestamp' => time()
        ];
    }

    /**
     * Capture current system state for diagnostics
     */
    protected function captureSystemState(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'load_average' => sys_getloadavg(),
            'emergency_mode' => $this->emergencyMode,
            'critical_errors' => $this->criticalErrors,
            'timestamp' => time()
        ];
    }

    /**
     * Log recovery failure with full context
     */
    protected function logRecoveryFailure(\Throwable $error): void
    {
        Log::critical('Error recovery failed', [
            'error' => $error,
            'trace' => $error->getTraceAsString(),
            'system_state' => $this->captureSystemState()
        ]);
    }

    /**
     * Exit system emergency mode with validation
     */
    protected function exitEmergencyMode(): void
    {
        if (!$this->emergencyMode) {
            return;
        }

        // Verify system stability before exiting
        if ($this->verifySystemStability()) {
            $this->emergencyMode = false;
            $this->cacheManager->delete('system.emergency_mode');
            
            // Log mode exit
            Log::info('System exited emergency mode', [
                'duration' => time() - $this->emergencyModeStart,
                'system_state' => $this->captureSystemState()
            ]);
        }
    }

    /**
     * Verify overall system stability
     */
    protected function verifySystemStability(): bool
    {
        // Check critical system components
        $healthCheck = [
            'database' => $this->checkDatabaseConnection(),
            'cache' => $this->checkCacheSystem(),
            'storage' => $this->checkStorageSystem(),
            'memory' => $this->checkMemoryUsage()
        ];

        return !in_array(false, $healthCheck, true);
    }

    /**
     * Handle uncaught PHP exceptions
     */
    public function handleUncaughtException(\Throwable $error): void
    {
        $this->handleCriticalError($error, [
            'type' => 'uncaught_exception',
            'handler' => 'global'
        ]);
    }

    /**
     * Handle PHP fatal errors
     */
    public function handleFatalError(): void
    {
        $error = error_get_last();
        
        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $this->handleCriticalError(
                new \ErrorException(
                    $error['message'],
                    0,
                    $error['type'],
                    $error['file'],
                    $error['line']
                ),
                ['type' => 'fatal_error']
            );
        }
    }

    /**
     * Handle PHP errors
     */
    public function handlePhpError(
        int $level,
        string $message,
        string $file = '',
        int $line = 0,
        array $context = []
    ): bool {
        if (error_reporting() & $level) {
            $this->handleError(
                new \ErrorException($message, 0, $level, $file, $line),
                ['type' => 'php_error', 'context' => $context]
            );
        }
        
        return true;
    }
}
