<?php

namespace App\Core\Audit;

use App\Core\Security\SecurityContext;
use Illuminate\Support\Facades\{DB, Log};
use App\Core\Contracts\AuditInterface;

class AuditManager implements AuditInterface 
{
    private const CRITICAL_SEVERITY = 'critical';
    private const HIGH_SEVERITY = 'high';
    private const MEDIUM_SEVERITY = 'medium';
    private const LOW_SEVERITY = 'low';

    private LogManager $logger;
    private MetricsCollector $metrics;
    private SecurityConfig $config;

    public function __construct(
        LogManager $logger,
        MetricsCollector $metrics,
        SecurityConfig $config
    ) {
        $this->logger = $logger;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function logSecurityEvent(
        string $event,
        SecurityContext $context,
        array $data = [],
        string $severity = self::HIGH_SEVERITY
    ): void {
        DB::beginTransaction();
        
        try {
            $entry = $this->createAuditEntry([
                'event' => $event,
                'user_id' => $context->getUserId(),
                'roles' => $context->getRoles(),
                'ip_address' => $context->getIpAddress(),
                'severity' => $severity,
                'data' => $this->sanitizeData($data),
                'timestamp' => now(),
                'trace_id' => $this->generateTraceId()
            ]);

            if ($this->isCriticalSeverity($severity)) {
                $this->handleCriticalEvent($entry);
            }

            $this->metrics->incrementSecurityMetric($event, $severity);
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleLoggingFailure($e, $event, $context);
        }
    }

    public function logOperationEvent(
        string $operation,
        SecurityContext $context,
        array $data = []
    ): void {
        try {
            $entry = $this->createAuditEntry([
                'event' => "operation.{$operation}",
                'user_id' => $context->getUserId(),
                'roles' => $context->getRoles(),
                'data' => $this->sanitizeData($data),
                'timestamp' => now(),
                'trace_id' => $this->generateTraceId()
            ]);

            $this->metrics->incrementOperationMetric($operation);
            
        } catch (\Exception $e) {
            $this->handleLoggingFailure($e, $operation, $context);
        }
    }

    public function logAccessAttempt(
        SecurityContext $context,
        string $resource,
        bool $granted
    ): void {
        $severity = $granted ? self::LOW_SEVERITY : self::HIGH_SEVERITY;
        
        $this->logSecurityEvent(
            $granted ? 'access.granted' : 'access.denied',
            $context,
            ['resource' => $resource],
            $severity
        );
    }

    public function logAuthenticationEvent(
        string $event,
        SecurityContext $context,
        array $data = []
    ): void {
        $severity = $this->getAuthEventSeverity($event);
        
        $this->logSecurityEvent(
            "auth.{$event}",
            $context,
            $data,
            $severity
        );
    }

    public function logDataAccess(
        string $operation,
        SecurityContext $context,
        string $entity,
        int $entityId,
        array $data = []
    ): void {
        $this->logSecurityEvent(
            "data.{$operation}",
            $context,
            [
                'entity' => $entity,
                'entity_id' => $entityId,
                'data' => $data
            ],
            self::MEDIUM_SEVERITY
        );
    }

    protected function createAuditEntry(array $data): AuditEntry
    {
        $entry = new AuditEntry($data);
        $entry->save();

        $this->logger->info('Audit entry created', [
            'trace_id' => $data['trace_id'],
            'event' => $data['event']
        ]);

        return $entry;
    }

    protected function handleCriticalEvent(AuditEntry $entry): void
    {
        $this->logger->critical('Critical security event', [
            'trace_id' => $entry->trace_id,
            'event' => $entry->event,
            'data' => $entry->data
        ]);

        if ($this->config->get('notifications.critical_events', true)) {
            $this->notifySecurityTeam($entry);
        }
    }

    protected function sanitizeData(array $data): array
    {
        return array_map(function ($value) {
            if ($this->isSensitive($value)) {
                return '[REDACTED]';
            }
            return $value;
        }, $data);
    }

    protected function isSensitive($value): bool
    {
        $sensitivePatterns = $this->config->get('sensitive_patterns', []);
        
        foreach ($sensitivePatterns as $pattern) {
            if (is_string($value) && preg_match($pattern, $value)) {
                return true;
            }
        }

        return false;
    }

    protected function generateTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    protected function getAuthEventSeverity(string $event): string
    {
        return match($event) {
            'failed' => self::HIGH_SEVERITY,
            'locked' => self::HIGH_SEVERITY,
            'success' => self::LOW_SEVERITY,
            default => self::MEDIUM_SEVERITY
        };
    }

    protected function isCriticalSeverity(string $severity): bool
    {
        return $severity === self::CRITICAL_SEVERITY;
    }

    private function handleLoggingFailure(\Exception $e, string $event, SecurityContext $context): void
    {
        Log::error('Audit logging failed', [
            'exception' => $e->getMessage(),
            'event' => $event,
            'user_id' => $context->getUserId(),
            'trace' => $e->getTraceAsString()
        ]);

        if ($this->config->get('throw_on_failure', false)) {
            throw $e;
        }
    }

    private function notifySecurityTeam(AuditEntry $entry): void
    {
        // Implementation depends on notification system
        // Must be handled without throwing exceptions
    }
}
