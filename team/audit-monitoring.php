<?php

namespace App\Core\Audit;

class AuditManager implements AuditManagerInterface
{
    private LogManager $logger;
    private SecurityManager $security;
    private MetricsCollector $metrics;
    private StorageManager $storage;
    private array $config;
    private array $buffer = [];

    public function __construct(
        LogManager $logger,
        SecurityManager $security,
        MetricsCollector $metrics,
        StorageManager $storage,
        array $config
    ) {
        $this->logger = $logger;
        $this->security = $security;
        $this->metrics = $metrics;
        $this->storage = $storage;
        $this->config = $config;
    }

    public function logSecurityEvent(SecurityEvent $event): void
    {
        $startTime = microtime(true);
        DB::beginTransaction();

        try {
            $this->validateEvent($event);
            $this->enrichEventData($event);
            
            $record = $this->createAuditRecord($event);
            $this->processRealTimeAlerts($event);
            
            if ($this->isHighRiskEvent($event)) {
                $this->handleHighRiskEvent($event);
            }
            
            $this->buffer[] = $record;
            
            if ($this->shouldFlushBuffer()) {
                $this->flushBuffer();
            }
            
            DB::commit();
            $this->recordMetrics('security_event', $startTime);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAuditFailure($e, $event);
            throw $e;
        }
    }

    public function logAccessEvent(AccessEvent $event): void
    {
        $startTime = microtime(true);
        
        try {
            $this->validateEvent($event);
            $this->enrichEventData($event);
            
            $record = $this->createAuditRecord($event);
            
            if ($this->isUnauthorizedAccess($event)) {
                $this->handleUnauthorizedAccess($event);
            }
            
            $this->buffer[] = $record;
            $this->recordMetrics('access_event', $startTime);
            
        } catch (\Exception $e) {
            $this->handleAuditFailure($e, $event);
            throw $e;
        }
    }

    public function logSystemEvent(SystemEvent $event): void
    {
        $startTime = microtime(true);
        
        try {
            $this->validateEvent($event);
            $this->enrichEventData($event);
            
            $record = $this->createAuditRecord($event);
            
            if ($this->isSystemCritical($event)) {
                $this->handleCriticalSystemEvent($event);
            }
            
            $this->buffer[] = $record;
            $this->recordMetrics('system_event', $startTime);
            
        } catch (\Exception $e) {
            $this->handleAuditFailure($e, $event);
            throw $e;
        }
    }

    public function getAuditTrail(
        array $criteria,
        array $options = []
    ): Collection {
        $this->security->validateAccess('audit.read');
        
        return $this->storage->getAuditRecords(
            $this->validateCriteria($criteria),
            $this->validateOptions($options)
        );
    }

    private function validateEvent(AuditEvent $event): void
    {
        if (!$event->isValid()) {
            throw new ValidationException('Invalid audit event');
        }
    }

    private function enrichEventData(AuditEvent $event): void
    {
        $event->setTimestamp(now());
        $event->setEnvironment(app()->environment());
        $event->setRequestId(request()->id());
        $event->setUserContext($this->security->getCurrentUser());
    }

    private function createAuditRecord(AuditEvent $event): AuditRecord
    {
        return new AuditRecord([
            'type' => get_class($event),
            'severity' => $event->getSeverity(),
            'timestamp' => $event->getTimestamp(),
            'data' => $this->encryptSensitiveData($event->getData()),
            'context' => $event->getContext(),
            'metadata' => $this->generateMetadata($event)
        ]);
    }

    private function encryptSensitiveData(array $data): array
    {
        foreach ($this->config['sensitive_fields'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = $this->security->encrypt($data[$field]);
            }
        }
        return $data;
    }

    private function generateMetadata(AuditEvent $event): array
    {
        return [
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'request_id' => request()->id(),
            'environment' => app()->environment(),
            'system_version' => config('app.version')
        ];
    }

    private function isHighRiskEvent(SecurityEvent $event): bool
    {
        return in_array(
            $event->getType(),
            $this->config['high_risk_events']
        );
    }

    private function handleHighRiskEvent(SecurityEvent $event): void
    {
        $this->security->triggerAlert($event);
        $this->notifySecurityTeam($event);
        $this->initiateImmediateProtocols($event);
    }

    private function handleUnauthorizedAccess(AccessEvent $event): void
    {
        $this->security->logFailedAccess($event);
        $this->updateAccessPatterns($event);
        
        if ($this->detectBruteForceAttempt($event)) {
            $this->blockSuspiciousAccess($event);
        }
    }

    private function handleCriticalSystemEvent(SystemEvent $event): void
    {
        $this->notifySystemAdmins($event);
        $this->checkSystemHealth();
        $this->updateSystemStatus($event);
    }

    private function shouldFlushBuffer(): bool
    {
        return count($this->buffer) >= $this->config['buffer_size']
            || memory_get_usage() > $this->config['memory_limit'];
    }

    private function flushBuffer(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        $this->storage->batchStore($this->buffer);
        $this->buffer = [];
    }

    private function recordMetrics(string $type, float $startTime): void
    {
        $this->metrics->record('audit_event', [
            'type' => $type,
            'duration' => microtime(true) - $startTime,
            'memory' => memory_get_peak_usage(true),
            'buffer_size' => count($this->buffer)
        ]);
    }

    private function handleAuditFailure(\Exception $e, AuditEvent $event): void
    {
        $this->logger->emergency('Audit system failure', [
            'event' => get_class($event),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->metrics->increment('audit_failure');
        $this->security->triggerAlert(new AuditFailureEvent($e, $event));
    }
}
