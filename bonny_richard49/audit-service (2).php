<?php

namespace App\Core\Security\Services;

use Illuminate\Support\Facades\Log;
use App\Core\Interfaces\AuditInterface;
use App\Core\Security\Events\SecurityEvent;

class AuditService implements AuditInterface
{
    private LogManager $logger;
    private AlertService $alertService;
    private array $config;
    private MetricsCollector $metrics;

    public function __construct(
        LogManager $logger,
        AlertService $alertService,
        MetricsCollector $metrics,
        array $config
    ) {
        $this->logger = $logger;
        $this->alertService = $alertService;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function logSecurityEvent(SecurityEvent $event): void
    {
        try {
            $eventData = $this->prepareEventData($event);
            
            // Log with appropriate severity
            $this->logWithSeverity($event->getSeverity(), $eventData);
            
            // Record metrics
            $this->recordEventMetrics($event);
            
            // Handle critical events
            if ($event->isCritical()) {
                $this->handleCriticalEvent($event, $eventData);
            }
            
            // Persist event data
            $this->persistAuditData($eventData);
            
        } catch (\Exception $e) {
            // Emergency logging for audit system failure
            $this->handleAuditFailure($e, $event);
        }
    }

    public function logSuccess(CriticalOperation $operation, SecurityContext $context, string $operationId): void
    {
        $eventData = [
            'event_type' => 'operation_success',
            'operation_id' => $operationId,
            'operation_type' => $operation->getType(),
            'context' => $this->sanitizeContext($context),
            'timestamp' => microtime(true),
            'duration' => $operation->getDuration(),
            'metrics' => $this->collectOperationMetrics($operation)
        ];

        $this->logger->info('Operation completed successfully', $eventData);
        $this->metrics->recordSuccess($operation->getType());
        $this->persistAuditData($eventData);
    }

    public function logFailure(\Throwable $e, CriticalOperation $operation, SecurityContext $context, string $operationId, array $details = []): void
    {
        $eventData = [
            'event_type' => 'operation_failure',
            'operation_id' => $operationId,
            'operation_type' => $operation->getType(),
            'context' => $this->sanitizeContext($context),
            'error' => [
                'type' => get_class($e),
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ],
            'details' => $details,
            'timestamp' => microtime(true),
            'system_state' => $this->captureSystemState()
        ];

        $this->logger->error('Operation failed', $eventData);
        $this->metrics->recordFailure($operation->getType(), get_class($e));
        $this->alertService->sendFailureAlert($eventData);
        $this->persistAuditData($eventData);
    }

    public function logUnauthorized(SecurityContext $context, CriticalOperation $operation): void
    {
        $eventData = [
            'event_type' => 'unauthorized_access',
            'context' => $this->sanitizeContext($context),
            'operation_type' => $operation->getType(),
            'required_permissions' => $operation->getRequiredPermissions(),
            'timestamp' => microtime(true),
            'ip_address' => $context->getIpAddress()
        ];

        $this->logger->warning('Unauthorized access attempt', $eventData);
        $this->metrics->incrementUnauthorizedAttempts();
        $this->alertService->sendSecurityAlert($eventData);
        $this->persistAuditData($eventData);
    }

    public function logSuspiciousActivity(SecurityContext $context, array $details = []): void
    {
        $eventData = [
            'event_type' => 'suspicious_activity',
            'context' => $this->sanitizeContext($context),
            'details' => $details,
            'timestamp' => microtime(true),
            'ip_address' => $context->getIpAddress(),
            'indicators' => $this->detectThreatIndicators($context)
        ];

        $this->logger->warning('Suspicious activity detected', $eventData);
        $this->metrics->incrementSuspiciousActivity();
        $this->alertService->sendSecurityAlert($eventData);
        $this->persistAuditData($eventData);
    }

    private function prepareEventData(SecurityEvent $event): array
    {
        return [
            'event_id' => $this->generateEventId(),
            'event_type' => $event->getType(),
            'severity' => $event->getSeverity(),
            'timestamp' => microtime(true),
            'data' => $this->sanitizeEventData($event->getData()),
            'context' => $this->sanitizeContext($event->getContext()),
            'metadata' => [
                'source' => $event->getSource(),
                'category' => $event->getCategory(),
                'tags' => $event->getTags()
            ]
        ];
    }

    private function logWithSeverity(string $severity, array $data): void
    {
        match($severity) {
            'emergency' => $this->logger->emergency('Critical security event', $data),
            'critical' => $this->logger->critical('Security event', $data),
            'warning' => $this->logger->warning('Security warning', $data),
            default => $this->logger->info('Security event', $data)
        };
    }

    private function handleCriticalEvent(SecurityEvent $event, array $eventData): void
    {
        // Send immediate alerts
        $this->alertService->sendCriticalAlert($eventData);
        
        // Record critical event metrics
        $this->metrics->recordCriticalEvent($event->getType());
        
        // Execute critical event procedures
        $this->executeCriticalEventProcedures($event);
    }

    private function handleAuditFailure(\Exception $e, SecurityEvent $event): void
    {
        // Emergency direct logging
        error_log(sprintf(
            'CRITICAL: Audit system failure - Event: %s, Error: %s',
            $event->getType(),
            $e->getMessage()
        ));

        // Attempt backup logging
        try {
            $this->executeEmergencyLogging($e, $event);
        } catch (\Exception $backupError) {
            // Last resort logging
            error_log('CRITICAL: Backup audit logging failed');
        }
    }

    private function persistAuditData(array $data): void
    {
        try {
            // Store in secure audit log storage
            $this->logger->store(
                $this->prepareForStorage($data),
                $this->config['storage_retention']
            );
        } catch (\Exception $e) {
            $this->handlePersistenceFailure($e, $data);
        }
    }

    private function sanitizeContext(SecurityContext $context): array
    {
        return array_intersect_key(
            $context->toArray(),
            array_flip($this->config['allowed_context_keys'])
        );
    }

    private function sanitizeEventData(array $data): array
    {
        return array_map(
            fn($value) => $this->sanitizeValue($value),
            $data
        );
    }

    private function collectOperationMetrics(CriticalOperation $operation): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'duration' => $operation->getDuration(),
            'cpu_usage' => sys_getloadavg()[0]
        ];
    }

    private function generateEventId(): string
    {
        return sprintf(
            '%s-%s',
            date('YmdHis'),
            bin2hex(random_bytes(8))
        );
    }

    private function captureSystemState(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'load_average' => sys_getloadavg(),
            'timestamp' => microtime(true)
        ];
    }

    private function detectThreatIndicators(SecurityContext $context): array
    {
        // Implement threat detection logic
        return [];
    }
}
