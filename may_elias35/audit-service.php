<?php

namespace App\Core\Audit;

use App\Core\Security\SecurityContext;
use App\Core\Validation\ValidationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Core\Monitoring\SystemMonitor;
use App\Core\Notification\NotificationService;

/**
 * Critical Audit Service - Handles all system auditing with zero-error tolerance
 * Any modification requires security team approval
 */
class AuditLogger implements AuditInterface 
{
    private ValidationService $validator;
    private SystemMonitor $monitor;
    private NotificationService $notifier;
    private array $config;

    // Critical constants
    private const MAX_RETRY_ATTEMPTS = 3;
    private const RETRY_DELAY_MS = 100;
    private const CRITICAL_EVENTS = [
        'security_breach',
        'data_corruption', 
        'system_failure',
        'unauthorized_access',
        'integrity_violation'
    ];

    public function __construct(
        ValidationService $validator,
        SystemMonitor $monitor,
        NotificationService $notifier,
        array $config
    ) {
        $this->validator = $validator;
        $this->monitor = $monitor;
        $this->notifier = $notifier;
        $this->config = $config;
    }

    /**
     * Logs security events with complete audit trail and failure protection
     *
     * @throws AuditException If logging fails after retries
     */
    public function logSecurityEvent(
        string $event,
        array $data,
        SecurityContext $context
    ): void {
        // Start monitoring
        $trackingId = $this->monitor->startOperation('security_audit');
        
        DB::beginTransaction();
        
        try {
            // Validate all inputs
            $this->validateSecurityEvent($event, $data, $context);
            
            // Prepare audit entry with sanitization
            $entry = $this->prepareSecurityAudit($event, $data, $context);
            
            // Store with retry mechanism
            $this->storeAuditEntry($entry);
            
            // Handle critical events if necessary
            if ($this->isCriticalEvent($event)) {
                $this->handleCriticalEvent($entry);
            }
            
            DB::commit();
            
            // Record successful audit
            $this->monitor->recordSuccess($trackingId);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleLoggingFailure($e, $event, $data, $trackingId);
            throw new AuditException(
                'Security event logging failed',
                previous: $e
            );
        }
    }

    /**
     * Logs system events with validation and failure protection
     */
    public function logSystemEvent(string $event, array $data): void 
    {
        $trackingId = $this->monitor->startOperation('system_audit');
        
        try {
            // Validate system event data
            $this->validateSystemEvent($event, $data);
            
            // Prepare and store audit entry
            $entry = $this->prepareSystemAudit($event, $data);
            $this->storeAuditEntry($entry);
            
            $this->monitor->recordSuccess($trackingId);
            
        } catch (\Exception $e) {
            $this->handleLoggingFailure($e, $event, $data, $trackingId);
            throw new AuditException(
                'System event logging failed',
                previous: $e
            );
        }
    }

    /**
     * Validates security event data with zero tolerance for invalid input
     */
    private function validateSecurityEvent(
        string $event,
        array $data,
        SecurityContext $context
    ): void {
        // Validate event data
        if (!$this->validator->validateSecurityEvent($event, $data)) {
            throw new AuditValidationException('Invalid security event data');
        }

        // Validate security context
        if (!$context->isValid()) {
            throw new AuditSecurityException('Invalid security context');
        }

        // Additional security validations
        if (!$this->validator->validateEventContext($event, $context)) {
            throw new AuditSecurityException('Security context validation failed');
        }
    }

    /**
     * Prepares security audit entry with full context and sanitization
     */
    private function prepareSecurityAudit(
        string $event,
        array $data,
        SecurityContext $context
    ): array {
        return [
            'type' => 'security',
            'event' => $event,
            'data' => $this->sanitizeData($data),
            'context' => [
                'user_id' => $context->getUserId(),
                'ip_address' => $context->getIpAddress(),
                'user_agent' => $context->getUserAgent(),
                'session_id' => $context->getSessionId(),
                'request_id' => $context->getRequestId()
            ],
            'timestamp' => now(),
            'severity' => $this->calculateSeverity($event),
            'system_state' => $this->monitor->captureSystemState()
        ];
    }

    /**
     * Handles critical security events with immediate notification
     */
    private function handleCriticalEvent(array $entry): void 
    {
        // Notify security team immediately
        $this->notifier->notifySecurityTeam($entry);
        
        // Log to separate critical events storage
        $this->storeCriticalEvent($entry);
        
        // Increase system monitoring
        $this->monitor->enableEnhancedMonitoring();
        
        // Trigger security alerts
        $this->triggerSecurityAlerts($entry);
    }

    /**
     * Stores audit entry with retry mechanism
     */
    private function storeAuditEntry(array $entry): void 
    {
        $attempts = 0;
        $lastException = null;
        
        while ($attempts < self::MAX_RETRY_ATTEMPTS) {
            try {
                DB::table('audit_logs')->insert($entry);
                return;
            } catch (\Exception $e) {
                $lastException = $e;
                $attempts++;
                
                if ($attempts >= self::MAX_RETRY_ATTEMPTS) {
                    $this->handleStorageFailure($entry, $lastException);
                    throw new AuditStorageException(
                        'Failed to store audit entry after retries',
                        previous: $lastException
                    );
                }
                
                usleep(self::RETRY_DELAY_MS * $attempts);
            }
        }
    }

    /**
     * Handles complete logging failure with emergency backup
     */
    private function handleLoggingFailure(
        \Exception $e,
        string $event,
        array $data,
        string $trackingId
    ): void {
        // Record failure in monitoring
        $this->monitor->recordFailure($trackingId, $e);
        
        try {
            // Attempt emergency backup logging
            $this->logToEmergencyBackup([
                'error' => $e->getMessage(),
                'event' => $event,
                'data' => $this->sanitizeData($data),
                'trace' => $e->getTraceAsString(),
                'timestamp' => now()
            ]);
        } catch (\Exception $backupException) {
            // If emergency backup fails, log to system log as last resort
            Log::emergency('Audit logging failed with backup failure', [
                'original_error' => $e->getMessage(),
                'backup_error' => $backupException->getMessage(),
                'event' => $event
            ]);
        }

        // Notify of logging failure
        $this->notifier->notifyCriticalFailure('audit_logging_failure', [
            'event' => $event,
            'error' => $e->getMessage()
        ]);
    }
}
