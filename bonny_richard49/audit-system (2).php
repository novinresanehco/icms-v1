<?php

namespace App\Core\Audit;

final class AuditSystem 
{
    private MetricsCollector $metrics;
    private LogManager $logger;
    private SecurityManager $security;
    private StorageService $storage;
    private AlertService $alerts;

    public function __construct(
        MetricsCollector $metrics,
        LogManager $logger,
        SecurityManager $security,
        StorageService $storage,
        AlertService $alerts
    ) {
        $this->metrics = $metrics;
        $this->logger = $logger;
        $this->security = $security;
        $this->storage = $storage;
        $this->alerts = $alerts;
    }

    public function logCriticalOperation(string $operation, array $context): string 
    {
        $auditId = $this->generateAuditId();

        try {
            // Initialize metrics collection
            $this->metrics->startCollection($auditId);

            // Create audit record
            $record = $this->createAuditRecord($operation, $context);

            // Store with validation
            $this->storeAuditRecord($record);

            // Log critical operation
            $this->logger->critical('Critical operation executed', [
                'audit_id' => $auditId,
                'operation' => $operation,
                'context' => $this->sanitizeContext($context),
                'timestamp' => microtime(true)
            ]);

            return $auditId;

        } catch (\Throwable $e) {
            $this->handleAuditFailure($e, $operation, $context);
            throw $e;
        }
    }

    public function recordSecurityEvent(SecurityEvent $event): void 
    {
        $eventId = $this->generateEventId();

        try {
            // Validate event
            $this->validateSecurityEvent($event);

            // Create security record
            $record = $this->createSecurityRecord($event);

            // Store with encryption
            $this->storeSecurityRecord($record);

            // Check severity
            if ($event->isCritical()) {
                $this->alerts->triggerSecurityAlert($event);
            }

            // Log event
            $this->logger->security('Security event recorded', [
                'event_id' => $eventId,
                'event_type' => $event->getType(),
                'severity' => $event->getSeverity(),
                'timestamp' => microtime(true)
            ]);

        } catch (\Throwable $e) {
            $this->handleSecurityEventFailure($e, $event);
            throw $e;
        }
    }

    public function logSystemMetrics(array $metrics): void 
    {
        $metricsId = $this->generateMetricsId();

        try {
            // Validate metrics
            $this->validateMetrics($metrics);

            // Process metrics
            $processed = $this->processMetrics($metrics);

            // Store metrics
            $this->storeMetrics($processed);

            // Check thresholds
            $this->checkMetricThresholds($processed);

        } catch (\Throwable $e) {
            $this->handleMetricsFailure($e, $metrics);
            throw $e;
        }
    }

    private function createAuditRecord(string $operation, array $context): array 
    {
        return [
            'id' => $this->generateAuditId(),
            'operation' => $operation,
            'context' => $this->sanitizeContext($context),
            'user_id' => $context['user_id'] ?? null,
            'ip_address' => $context['ip_address'] ?? null,
            'user_agent' => $context['user_agent'] ?? null,
            'timestamp' => microtime(true),
            'system_state' => $this->captureSystemState()
        ];
    }

    private function createSecurityRecord(SecurityEvent $event): array 
    {
        return [
            'id' => $this->generateEventId(),
            'type' => $event->getType(),
            'severity' => $event->getSeverity(),
            'details' => $event->getDetails(),
            'user_id' => $event->getUserId(),
            'ip_address' => $event->getIpAddress(),
            'timestamp' => microtime(true),
            'system_context' => $this->captureSecurityContext()
        ];
    }

    private function captureSystemState(): array 
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'cpu_load' => sys_getloadavg(),
            'disk_usage' => disk_free_space('/'),
            'uptime' => time() - APP_START_TIME,
            'active_users' => $this->metrics->getActiveUsers(),
            'request_rate' => $this->metrics->getRequestRate()
        ];
    }

    private function captureSecurityContext(): array 
    {
        return [
            'active_sessions' => $this->security->getActiveSessions(),
            'failed_logins' => $this->security->getFailedLoginAttempts(),
            'suspicious_ips' => $this->security->getSuspiciousIps(),
            'system_alerts' => $this->security->getActiveAlerts()
        ];
    }

    private function validateSecurityEvent(SecurityEvent $event): void 
    {
        if (!$this->security->validateEvent($event)) {
            throw new ValidationException('Invalid security event');
        }
    }

    private function checkMetricThresholds(array $metrics): void 
    {
        foreach ($metrics as $metric => $value) {
            if ($this->isThresholdExceeded($metric, $value)) {
                $this->alerts->triggerMetricAlert($metric, $value);
            }
        }
    }
}
