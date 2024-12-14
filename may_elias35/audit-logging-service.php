<?php

namespace App\Core\Audit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Core\Security\SecurityContext;
use App\Core\Interfaces\AuditInterface;
use App\Core\Validation\ValidationService;

/**
 * Critical audit logging service for maintaining complete system audit trail
 * with zero-tolerance for logging failures.
 */
class AuditLoggingService implements AuditInterface 
{
    private ValidationService $validator;
    private array $criticalEvents = [
        'security_breach',
        'data_violation',
        'system_failure',
        'authorization_failure'
    ];

    public function __construct(
        ValidationService $validator
    ) {
        $this->validator = $validator;
    }

    /**
     * Log a critical security event with guaranteed persistence
     * 
     * @throws AuditLoggingException If logging fails
     */
    public function logSecurityEvent(
        string $event,
        SecurityContext $context,
        array $data = []
    ): void {
        $this->executeWithProtection(function() use ($event, $context, $data) {
            // Validate inputs
            $this->validateSecurityEvent($event, $context, $data);

            // Prepare audit data
            $auditData = $this->prepareSecurityAuditData($event, $context, $data);

            // Critical event check
            if ($this->isCriticalEvent($event)) {
                $this->handleCriticalEvent($auditData);
            }

            // Persist audit log
            $this->persistAuditLog($auditData, true);
        });
    }

    /**
     * Log system operation with full context and validation
     */
    public function logOperation(
        string $operation,
        array $context,
        array $data = []
    ): void {
        $this->executeWithProtection(function() use ($operation, $context, $data) {
            // Validate operation data
            $this->validateOperationLog($operation, $context, $data);

            // Prepare audit data
            $auditData = $this->prepareOperationAuditData($operation, $context, $data);

            // Persist audit log
            $this->persistAuditLog($auditData);
        });
    }

    /**
     * Log system failure with complete error context
     */
    public function logFailure(
        \Throwable $exception,
        array $context = []
    ): void {
        $this->executeWithProtection(function() use ($exception, $context) {
            // Prepare failure data
            $failureData = $this->prepareFailureData($exception, $context);

            // Log to system log
            Log::error($exception->getMessage(), $failureData);

            // Persist failure audit
            $this->persistAuditLog($failureData, true);

            // Handle critical failures
            if ($this->isCriticalFailure($exception)) {
                $this->handleCriticalFailure($failureData);
            }
        });
    }

    /**
     * Execute logging operation with guaranteed completion
     */
    private function executeWithProtection(callable $operation): void
    {
        try {
            DB::beginTransaction();
            
            // Execute logging operation
            $operation();
            
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            
            // Attempt emergency logging
            $this->executeEmergencyLogging($e);
            
            throw new AuditLoggingException(
                'Critical audit logging failure',
                previous: $e
            );
        }
    }

    /**
     * Validate security event data
     */
    private function validateSecurityEvent(
        string $event,
        SecurityContext $context,
        array $data
    ): void {
        if (!$this->validator->validateSecurityEvent($event, $context, $data)) {
            throw new ValidationException('Invalid security event data');
        }
    }

    /**
     * Validate operation log data
     */
    private function validateOperationLog(
        string $operation,
        array $context,
        array $data
    ): void {
        if (!$this->validator->validateOperationLog($operation, $context, $data)) {
            throw new ValidationException('Invalid operation log data');
        }
    }

    /**
     * Prepare security audit data with complete context
     */
    private function prepareSecurityAuditData(
        string $event,
        SecurityContext $context,
        array $data
    ): array {
        return [
            'event' => $event,
            'timestamp' => now(),
            'user_id' => $context->getUserId(),
            'ip_address' => $context->getIpAddress(),
            'user_agent' => $context->getUserAgent(),
            'resource' => $context->getResource(),
            'action' => $context->getAction(),
            'status' => $context->getStatus(),
            'data' => $data,
            'session_id' => $context->getSessionId(),
            'request_id' => $context->getRequestId()
        ];
    }

    /**
     * Prepare operation audit data
     */
    private function prepareOperationAuditData(
        string $operation,
        array $context,
        array $data
    ): array {
        return [
            'operation' => $operation,
            'timestamp' => now(),
            'context' => $context,
            'data' => $data,
            'system_state' => $this->captureSystemState()
        ];
    }

    /**
     * Prepare failure audit data
     */
    private function prepareFailureData(
        \Throwable $exception,
        array $context
    ): array {
        return [
            'error' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'context' => $context,
            'timestamp' => now(),
            'system_state' => $this->captureSystemState()
        ];
    }

    /**
     * Persist audit log with retry mechanism
     */
    private function persistAuditLog(array $data, bool $critical = false): void
    {
        $attempts = 0;
        $maxAttempts = $critical ? 5 : 3;

        while ($attempts < $maxAttempts) {
            try {
                DB::table('audit_logs')->insert($data);
                return;
            } catch (\Exception $e) {
                $attempts++;
                if ($attempts >= $maxAttempts) {
                    throw new AuditLoggingException(
                        'Failed to persist audit log after ' . $maxAttempts . ' attempts',
                        previous: $e
                    );
                }
                usleep(100000 * $attempts); // Exponential backoff
            }
        }
    }

    /**
     * Handle critical security events
     */
    private function handleCriticalEvent(array $eventData): void
    {
        // Notify security team
        $this->notifySecurityTeam($eventData);

        // Store in separate critical events log
        $this->logCriticalEvent($eventData);

        // Capture additional system context
        $this->captureExtendedContext($eventData);
    }

    /**
     * Handle critical system failures
     */
    private function handleCriticalFailure(array $failureData): void
    {
        // Notify system administrators
        $this->notifyAdministrators($failureData);

        // Log to emergency channel
        $this->logToEmergencyChannel($failureData);

        // Capture system diagnostic information
        $this->captureDiagnostics($failureData);
    }

    /**
     * Emergency logging when normal logging fails
     */
    private function executeEmergencyLogging(\Throwable $e): void
    {
        try {
            // Log to alternative storage
            $this->logToAlternativeStorage([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'timestamp' => now()
            ]);
        } catch (\Exception $emergency) {
            // Last resort logging to system log
            error_log('Critical logging failure: ' . $emergency->getMessage());
        }
    }

    /**
     * Capture current system state
     */
    private function captureSystemState(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'load_average' => sys_getloadavg(),
            'timestamp' => microtime(true)
        ];
    }

    /**
     * Check if event is critical
     */
    private function isCriticalEvent(string $event): bool
    {
        return in_array($event, $this->criticalEvents);
    }

    /**
     * Check if failure is critical
     */
    private function isCriticalFailure(\Throwable $e): bool
    {
        return $e instanceof CriticalException ||
               $e instanceof SecurityException ||
               $e->getCode() >= 5000;
    }
}
