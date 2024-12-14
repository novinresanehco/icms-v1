<?php

namespace App\Core\Services;

use App\Core\Interfaces\AuditInterface;
use App\Core\Security\SecurityContext;
use App\Core\Events\AuditEvent;
use Illuminate\Support\Facades\{DB, Log, Event};
use Monolog\Logger;

class AuditService implements AuditInterface
{
    private Logger $securityLogger;
    private array $config;
    private string $auditTable;
    
    public function __construct(
        Logger $securityLogger,
        array $config,
        string $auditTable = 'security_audit_log'
    ) {
        $this->securityLogger = $securityLogger;
        $this->config = $config;
        $this->auditTable = $auditTable;
    }

    public function logValidation(SecurityContext $context): void
    {
        $this->logSecurityEvent(
            'validation',
            $context,
            [
                'validation_type' => 'security_context',
                'timestamp' => microtime(true),
                'operation' => $context->getOperation(),
                'user_id' => $context->getUser()->getId(),
                'ip_address' => $context->getIpAddress(),
                'user_agent' => $context->getUserAgent()
            ]
        );
    }

    public function logOperation(SecurityContext $context, $result): void 
    {
        $this->logSecurityEvent(
            'operation',
            $context,
            [
                'operation_type' => $context->getOperation(),
                'timestamp' => microtime(true),
                'user_id' => $context->getUser()->getId(),
                'status' => 'success',
                'result' => $this->sanitizeResult($result),
                'ip_address' => $context->getIpAddress(),
                'session_id' => $context->getSessionId()
            ]
        );
    }

    public function logFailure(\Exception $e, SecurityContext $context): void
    {
        $this->logSecurityEvent(
            'failure',
            $context,
            [
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'timestamp' => microtime(true),
                'stack_trace' => $e->getTraceAsString(),
                'user_id' => $context->getUser()->getId(),
                'operation' => $context->getOperation(),
                'ip_address' => $context->getIpAddress(),
                'user_agent' => $context->getUserAgent()
            ]
        );
    }

    public function logSecurityEvent(
        string $type,
        SecurityContext $context,
        array $data
    ): void {
        // Persist to database
        DB::table($this->auditTable)->insert([
            'type' => $type,
            'data' => json_encode($data),
            'created_at' => now()
        ]);

        // Log to security log file
        $this->securityLogger->info("Security Event: {$type}", $data);

        // Dispatch event for real-time monitoring
        Event::dispatch(new AuditEvent($type, $data));

        // Additional logging for critical events
        if ($this->isCriticalEvent($type)) {
            $this->handleCriticalEvent($type, $data);
        }
    }

    protected function isCriticalEvent(string $type): bool
    {
        return in_array($type, $this->config['critical_events'] ?? []);
    }

    protected function handleCriticalEvent(string $type, array $data): void
    {
        // Send immediate notifications
        $this->notifySecurityTeam($type, $data);

        // Log to separate critical events log
        Log::channel('critical')->error("Critical Security Event: {$type}", $data);
    }

    protected function notifySecurityTeam(string $type, array $data): void
    {
        // Implementation depends on notification system
        // But must not throw exceptions
    }

    protected function sanitizeResult($result): array
    {
        // Remove sensitive data before logging
        if (is_object($result)) {
            $result = json_decode(json_encode($result), true);
        }

        // Remove configured sensitive fields
        foreach ($this->config['sensitive_fields'] ?? [] as $field) {
            unset($result[$field]);
        }

        return $result;
    }

    public function getAuditTrail(array $criteria): array
    {
        return DB::table($this->auditTable)
            ->where($criteria)
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }
}
