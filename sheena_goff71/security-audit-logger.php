<?php

namespace App\Core\Security\Services;

use App\Core\Security\Models\{AuditEvent, SecurityContext};
use Illuminate\Support\Facades\{DB, Cache, Log};
use Monolog\Logger;

class AuditLogger
{
    private Logger $logger;
    private MetricsCollector $metrics;
    private SecurityConfig $config;

    public function __construct(
        Logger $logger,
        MetricsCollector $metrics,
        SecurityConfig $config
    ) {
        $this->logger = $logger;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function logSecurityEvent(
        string $eventType,
        SecurityContext $context,
        array $data = []
    ): void {
        DB::beginTransaction();
        
        try {
            $event = new AuditEvent([
                'type' => $eventType,
                'user_id' => $context->getUserId(),
                'ip_address' => $context->getIpAddress(),
                'timestamp' => now(),
                'data' => $this->sanitizeData($data),
                'severity' => $this->calculateSeverity($eventType),
                'session_id' => $context->getSessionId(),
                'request_id' => $context->getRequestId(),
                'operation_id' => $context->getOperationId()
            ]);

            $this->persistAuditEvent($event);
            $this->notifyIfCritical($event);
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleLoggingFailure($eventType, $e);
        }
    }

    public function logFailure(
        string $operation,
        SecurityContext $context,
        \Exception $error,
        array $additionalData = []
    ): void {
        $data = array_merge([
            'error_message' => $error->getMessage(),
            'error_code' => $error->getCode(),
            'stack_trace' => $error->getTraceAsString(),
            'system_state' => $this->captureSystemState()
        ], $additionalData);

        $this->logSecurityEvent(
            "failure_{$operation}",
            $context,
            $data
        );

        if ($this->isHighSeverityError($error)) {
            $this->triggerEmergencyProtocols($error, $context);
        }
    }

    public function logUnauthorizedAccess(
        SecurityContext $context,
        string $resource = null
    ): void {
        $this->logSecurityEvent('unauthorized_access', $context, [
            'resource' => $resource,
            'attempted_permissions' => $context->getRequestedPermissions(),
            'user_roles' => $context->getUserRoles()
        ]);

        $this->metrics->incrementCounter(
            'security_unauthorized_access',
            ['resource' => $resource]
        );

        $this->detectPotentialBreachAttempt($context);
    }

    private function persistAuditEvent(AuditEvent $event): void
    {
        // Primary storage
        DB::table('security_audit_log')->insert($event->toArray());

        // Secondary storage for critical events
        if ($event->isCritical()) {
            $this->storeInSecondaryStorage($event);
        }

        // Update metrics
        $this->updateMetrics($event);

        // Cache recent events for quick access
        $this->cacheRecentEvent($event);
    }

    private function notifyIfCritical(AuditEvent $event): void
    {
        if ($event->isCritical()) {
            $this->sendEmergencyNotification($event);
        }
    }

    private function detectPotentialBreachAttempt(SecurityContext $context): void
    {
        $attempts = $this->getRecentFailedAttempts($context);
        
        if ($attempts >= $this->config->getBreachThreshold()) {
            $this->triggerSecurityAlert($context, $attempts);
        }
    }

    private function getRecentFailedAttempts(SecurityContext $context): int
    {
        $key = "failed_attempts:{$context->getIpAddress()}";
        $attempts = (int)Cache::get($key, 0) + 1;
        
        Cache::put(
            $key,
            $attempts,
            $this->config->getFailedAttemptsWindow()
        );
        
        return $attempts;
    }

    private function sanitizeData(array $data): array
    {
        array_walk_recursive($data, function(&$value) {
            if (is_string($value)) {
                $value = $this->sanitizeString($value);
            }
        });
        
        return $data;
    }

    private function sanitizeString(string $value): string
    {
        $value = strip_tags($value);
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        foreach ($this->config->getSensitivePatterns() as $pattern) {
            $value = preg_replace($pattern, '[REDACTED]', $value);
        }
        
        return $value;
    }

    private function handleLoggingFailure(string $eventType, \Exception $e): void
    {
        Log::emergency('Audit logging failed', [
            'event_type' => $eventType,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->metrics->incrementCounter('audit_logging_failures');
    }

    private function captureSystemState(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'system_load' => sys_getloadavg(),
            'active_connections' => $this->getActiveConnections(),
            'cache_stats' => $this->getCacheStats(),
            'queue_size' => $this->getQueueSize()
        ];
    }
}
