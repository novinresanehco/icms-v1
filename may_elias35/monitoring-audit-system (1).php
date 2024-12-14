<?php

namespace App\Core\Monitoring;

class MonitoringManager implements MonitoringInterface
{
    private MetricsCollector $metrics;
    private SecurityMonitor $security;
    private PerformanceTracker $performance;
    private AlertDispatcher $alerts;
    private AuditLogger $logger;

    public function track(Operation $operation): OperationResult
    {
        $tracking = $this->startTracking($operation);
        
        try {
            $result = $operation->execute();
            $this->recordSuccess($tracking, $result);
            return $result;
            
        } catch (\Exception $e) {
            $this->recordFailure($tracking, $e);
            throw $e;
        }
    }

    private function startTracking(Operation $operation): OperationTracking
    {
        return new OperationTracking(
            $operation,
            $this->metrics,
            microtime(true)
        );
    }

    private function recordSuccess(OperationTracking $tracking, $result): void
    {
        $metrics = $tracking->complete();
        
        $this->metrics->record($metrics);
        $this->logger->logOperation($tracking->operation, $metrics);
        
        if ($metrics->exceedsThresholds()) {
            $this->alerts->performanceWarning($metrics);
        }
    }
}

class SecurityMonitor
{
    private ThreatDetector $detector;
    private IntrusionPrevention $ips;
    private AuditLogger $logger;
    private AlertDispatcher $alerts;

    public function monitorSecurityEvents(): void
    {
        foreach ($this->detector->detectThreats() as $threat) {
            $this->handleThreat($threat);
        }
    }

    private function handleThreat(Threat $threat): void
    {
        $this->logger->logThreat($threat);
        $this->ips->blockThreat($threat);
        
        if ($threat->isCritical()) {
            $this->alerts->triggerSecurityAlert($threat);
        }
    }

    public function validateRequest(Request $request): SecurityValidation
    {
        $validation = new SecurityValidation($request);
        
        if ($this->detector->isBlacklisted($request->ip())) {
            $validation->markAsSuspicious('IP blacklisted');
        }
        
        if ($this->detector->hasSuspiciousPatterns($request)) {
            $validation->markAsSuspicious('Suspicious patterns');
        }
        
        return $validation;
    }
}

class PerformanceTracker implements PerformanceInterface
{
    private MetricsCollector $metrics;
    private ThresholdManager $thresholds;
    private AlertDispatcher $alerts;

    public function trackPerformance(callable $operation): mixed
    {
        $start = microtime(true);
        $memStart = memory_get_usage(true);
        
        try {
            $result = $operation();
            return $result;
            
        } finally {
            $this->recordMetrics(
                $start,
                $memStart,
                memory_get_usage(true)
            );
        }
    }

    private function recordMetrics(float $start, int $memStart, int $memEnd): void
    {
        $duration = microtime(true) - $start;
        $memoryUsed = $memEnd - $memStart;
        
        $metrics = new PerformanceMetrics([
            'duration' => $duration,
            'memory_used' => $memoryUsed,
            'cpu_usage' => sys_getloadavg()[0]
        ]);
        
        $this->metrics->record($metrics);
        
        if ($metrics->exceedsThresholds($this->thresholds->get())) {
            $this->alerts->performanceWarning($metrics);
        }
    }
}

class AuditLogger implements AuditInterface
{
    private LogStorage $storage;
    private EventDispatcher $events;
    private SecurityManager $security;

    public function logSecurityEvent(SecurityEvent $event): void
    {
        $entry = new AuditEntry(
            'security',
            $event->getType(),
            $event->getData(),
            $event->getSeverity()
        );
        
        $this->security->executeProtectedOperation(
            fn() => $this->storage->store($entry)
        );
        
        if ($event->isCritical()) {
            $this->events->dispatch(
                new CriticalSecurityEvent($event)
            );
        }
    }

    public function logPerformanceMetrics(PerformanceMetrics $metrics): void
    {
        $entry = new AuditEntry(
            'performance',
            'metrics',
            $metrics->toArray(),
            $metrics->exceedsThresholds() ? 'warning' : 'info'
        );
        
        $this->storage->store($entry);
    }

    public function queryAuditLog(AuditQuery $query): AuditResult
    {
        return $this->security->executeProtectedOperation(
            fn() => $this->storage->query($query)
        );
    }
}

class AlertDispatcher
{
    private NotificationService $notifications;
    private AuditLogger $logger;
    private array $alertChannels;

    public function dispatch(Alert $alert): void
    {
        $this->logger->logAlert($alert);
        
        foreach ($this->getChannelsForSeverity($alert->severity) as $channel) {
            $this->notifications->send($channel, $alert);
        }

        if ($alert->requiresImmediate()) {
            $this->triggerEmergencyProtocol($alert);
        }
    }

    private function getChannelsForSeverity(string $severity): array
    {
        return $this->alertChannels[$severity] ?? ['default'];
    }

    private function triggerEmergencyProtocol(Alert $alert): void
    {
        $this->notifications->emergency([
            'title' => 'CRITICAL ALERT',
            'description' => $alert->description,
            'time' => now(),
            'action_required' => true
        ]);
    }
}
