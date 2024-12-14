<?php

namespace App\Security\Monitoring;

class SecurityMonitor implements MonitorInterface
{
    private EventCollector $collector;
    private ThreatDetector $detector;
    private AlertSystem $alerts;
    private MetricsCollector $metrics;
    private AuditLogger $logger;

    public function monitorSecurityEvent(SecurityEvent $event): void
    {
        $this->collector->collect($event);
        
        if ($this->detector->isThreat($event)) {
            $this->handleThreat($event);
        }

        $this->metrics->recordEvent($event);
        $this->logger->logSecurityEvent($event);
    }

    private function handleThreat(SecurityEvent $event): void
    {
        DB::transaction(function() use ($event) {
            $threat = $this->detector->analyzeThreat($event);
            $this->alerts->dispatchThreatAlert($threat);
            $this->logger->logThreat($threat);
            
            if ($threat->isCritical()) {
                $this->executeEmergencyProtocol($threat);
            }
        });
    }

    private function executeEmergencyProtocol(Threat $threat): void
    {
        // Immediate system protection
        $this->security->lockdownAffectedSystems($threat);
        
        // Notify security team
        $this->alerts->dispatchCriticalAlert($threat);
        
        // Preserve forensic data
        $this->preserveForensicData($threat);
    }

    public function monitorPerformanceMetrics(): void
    {
        $metrics = [
            'cpu_usage' => sys_getloadavg()[0],
            'memory_usage' => memory_get_usage(true),
            'request_rate' => $this->getRequestRate(),
            'error_rate' => $this->getErrorRate(),
            'response_time' => $this->getAverageResponseTime()
        ];

        foreach ($metrics as $key => $value) {
            $this->metrics->record("security.$key", $value);
            
            if ($this->isThresholdExceeded($key, $value)) {
                $this->alerts->dispatchPerformanceAlert($key, $value);
            }
        }
    }

    private function preserveForensicData(Threat $threat): void
    {
        $data = [
            'threat' => $threat,
            'system_state' => $this->captureSystemState(),
            'logs' => $this->getRelevantLogs($threat),
            'metrics' => $this->getRelevantMetrics($threat)
        ];

        $this->forensics->preserve($data);
    }
}

class AuditSystem implements AuditInterface
{
    private AuditStorage $storage;
    private EncryptionService $encryption;
    private ValidationService $validator;

    public function logAccess(AccessEvent $event): void
    {
        $this->validator->validateEvent($event);
        
        $entry = [
            'timestamp' => microtime(true),
            'user_id' => $event->getUserId(),
            'action' => $event->getAction(),
            'resource' => $event->getResource(),
            'ip_address' => $event->getIpAddress(),
            'user_agent' => $event->getUserAgent(),
            'result' => $event->getResult()
        ];

        $encrypted = $this->encryption->encrypt(json_encode($entry));
        $this->storage->store($encrypted);
    }

    public function logSecurityEvent(SecurityEvent $event): void
    {
        $this->validator->validateEvent($event);
        
        $entry = [
            'timestamp' => microtime(true),
            'type' => $event->getType(),
            'severity' => $event->getSeverity(),
            'details' => $event->getDetails(),
            'system_state' => $this->captureSystemState()
        ];

        $encrypted = $this->encryption->encrypt(json_encode($entry));
        $this->storage->store($encrypted);
    }

    private function captureSystemState(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'cpu_load' => sys_getloadavg(),
            'active_connections' => $this->getActiveConnections(),
            'error_rates' => $this->getErrorRates(),
            'request_rates' => $this->getRequestRates()
        ];
    }
}

class MetricsCollector implements MetricsInterface
{
    private MetricsStorage $storage;
    private AlertSystem $alerts;

    public function record(string $metric, float $value, array $tags = []): void
    {
        $entry = [
            'timestamp' => microtime(true),
            'metric' => $metric,
            'value' => $value,
            'tags' => $tags
        ];

        $this->storage->store($entry);
        
        if ($this->isThresholdExceeded($metric, $value)) {
            $this->alerts->dispatchMetricAlert($metric, $value, $tags);
        }
    }

    public function getMetrics(array $criteria): array
    {
        return $this->storage->query($criteria);
    }

    private function isThresholdExceeded(string $metric, float $value): bool
    {
        $threshold = config("metrics.thresholds.$metric");
        return $threshold && $value > $threshold;
    }
}
