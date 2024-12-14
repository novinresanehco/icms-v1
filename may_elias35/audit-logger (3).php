<?php

namespace App\Core\Audit;

class AuditLogger implements LoggerInterface
{
    private LoggerInterface $systemLogger;
    private MetricsCollector $metrics;
    private StorageInterface $storage;
    private AlertManager $alertManager;
    private array $config;

    public function __construct(
        LoggerInterface $systemLogger,
        MetricsCollector $metrics,
        StorageInterface $storage,
        AlertManager $alertManager,
        array $config = []
    ) {
        $this->systemLogger = $systemLogger;
        $this->metrics = $metrics;
        $this->storage = $storage;
        $this->alertManager = $alertManager;
        $this->config = $config;
    }

    public function logEvent(AuditEvent $event): void
    {
        try {
            // Prepare log entry
            $logEntry = $this->prepareLogEntry($event);

            // Record metrics before logging
            $this->recordMetrics($event);

            // Write to storage
            $this->writeLog($logEntry);

            // Check for alert conditions
            $this->checkAlertConditions($event);

            // Archive if needed
            if ($this->shouldArchive($event)) {
                $this->archiveEvent($event);
            }

        } catch (\Exception $e) {
            // Log the logging failure
            $this->systemLogger->error('Failed to log audit event', [
                'event_type' => $event->getType(),
                'error' => $e->getMessage(),
                'trace_id' => $event->getTraceId()
            ]);

            throw new AuditLoggingException(
                'Failed to log audit event: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public function logBatch(array $events): void
    {
        $batchId = $this->generateBatchId();

        try {
            $logEntries = array_map(
                fn($event) => $this->prepareLogEntry($event, $batchId),
                $events
            );

            // Write batch to storage
            $this->writeBatchLog($logEntries);

            // Record batch metrics
            $this->recordBatchMetrics($events);

        } catch (\Exception $e) {
            $this->systemLogger->error('Failed to log audit event batch', [
                'batch_id' => $batchId,
                'error' => $e->getMessage(),
                'event_count' => count($events)
            ]);

            throw new AuditBatchLoggingException(
                'Failed to log audit event batch: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public function logPurge(array $criteria): void
    {
        $purgeId = $this->generatePurgeId();

        try {
            $logEntry = [
                'type' => 'audit_purge',
                'purge_id' => $purgeId,
                'criteria' => $criteria,
                'timestamp' => time(),
                'user_id' => $this->getCurrentUserId(),
                'ip_address' => $this->getClientIp()
            ];

            $this->writeLog($logEntry);
            $this->recordPurgeMetrics($criteria);

        } catch (\Exception $e) {
            $this->systemLogger->error('Failed to log audit purge', [
                'purge_id' => $purgeId,
                'error' => $e->getMessage()
            ]);

            throw new AuditPurgeLoggingException(
                'Failed to log audit purge: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    protected function prepareLogEntry(AuditEvent $event, ?string $batchId = null): array
    {
        return [
            'id' => $event->getId(),
            'batch_id' => $batchId,
            'type' => $event->getType(),
            'action' => $event->getAction(),
            'data' => $this->sanitizeData($event->getData()),
            'user_id' => $event->getUserId(),
            'ip_address' => $event->getIpAddress(),
            'metadata' => $event->getMetadata(),
            'timestamp' => $event->getTimestamp(),
            'trace_id' => $event->getTraceId(),
            'severity' => $event->getSeverity(),
            'environment' => $this->getEnvironmentInfo(),
            'process_id' => getmypid(),
            'memory_usage' => memory_get_usage(true)
        ];
    }

    protected function writeLog(array $logEntry): void
    {
        $rotationNeeded = $this->storage->checkRotation();
        
        if ($rotationNeeded) {
            $this->rotateLog();
        }

        $this->storage->write($logEntry);
    }

    protected function writeBatchLog(array $logEntries): void
    {
        $this->storage->writeBatch($logEntries);
    }

    protected function recordMetrics(AuditEvent $event): void
    {
        $this->metrics->increment('audit_events_total', [
            'type' => $event->getType(),
            'severity' => $event->getSeverity()
        ]);

        $this->metrics->gauge('audit_event_size', strlen(json_encode($event->getData())), [
            'type' => $event->getType()
        ]);

        if ($event->getSeverity() >= $this->config['high_severity_threshold']) {
            $this->metrics->increment('high_severity_events');
        }
    }

    protected function checkAlertConditions(AuditEvent $event): void
    {
        if ($this->shouldAlert($event)) {
            $this->alertManager->sendAlert($event);
        }
    }

    protected function shouldAlert(AuditEvent $event): bool
    {
        return $event->getSeverity() >= $this->config['alert_threshold']
            || in_array($event->getType(), $this->config['alert_types'] ?? [])
            || $this->detectAnomalous($event);
    }

    protected function detectAnomalous(AuditEvent $event): bool
    {
        // Implement anomaly detection logic
        return false;
    }

    protected function shouldArchive(AuditEvent $event): bool
    {
        return $event->getTimestamp() < (time() - $this->config['archive_age'])
            || $event->getSeverity() >= $this->config['archive_severity_threshold'];
    }

    protected function archiveEvent(AuditEvent $event): void
    {
        $this->storage->archive($event);
    }

    protected function rotateLog(): void
    {
        $this->storage->rotate(
            $this->config['rotation_size'],
            $this->config['max_files']
        );
    }

    protected function sanitizeData(array $data): array
    {
        return array_map(function ($value) {
            if (is_string($value)) {
                return $this->sanitizeString($value);
            }
            if (is_array($value)) {
                return $this->sanitizeData($value);
            }
            return $value;
        }, $data);
    }

    protected function sanitizeString(string $value): string
    {
        // Remove potentially sensitive data patterns
        $patterns = $this->config['sensitive_patterns'] ?? [];
        foreach ($patterns as $pattern) {
            $value = preg_replace($pattern, '[REDACTED]', $value);
        }
        return $value;
    }
}
