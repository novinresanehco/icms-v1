<?php
namespace App\Core\Monitoring;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\{SecurityManager, AuditLogger};
use App\Core\Exceptions\{MonitoringException, AlertException};

class MonitoringSystem implements MonitoringInterface
{
    private SecurityManager $security;
    private AuditLogger $audit;
    private MetricsRepository $repository;
    private AlertSystem $alerts;
    private int $criticalThreshold;

    public function trackMetrics(array $metrics, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(function() use ($metrics, $context) {
            DB::transaction(function() use ($metrics, $context) {
                $normalized = $this->normalizeMetrics($metrics);
                $this->validateMetrics($normalized);
                
                $this->repository->store($normalized);
                $this->analyzeMetrics($normalized);
                
                if ($this->isCriticalState($normalized)) {
                    $this->handleCriticalState($normalized, $context);
                }
                
                $this->updateCache($normalized);
            });
        }, $context);
    }

    public function monitorHealth(SecurityContext $context): HealthStatus
    {
        return $this->security->executeCriticalOperation(function() {
            $metrics = $this->getCurrentMetrics();
            $status = new HealthStatus($metrics);
            
            if (!$status->isHealthy()) {
                $this->handleUnhealthyState($status);
            }
            
            return $status;
        }, $context);
    }

    public function trackSecurityEvent(SecurityEvent $event, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(function() use ($event, $context) {
            if ($event->isCritical()) {
                $this->handleCriticalSecurityEvent($event, $context);
            }
            
            $this->audit->logSecurityEvent($event);
            $this->updateSecurityMetrics($event);
            
            if ($this->requiresImmedateAction($event)) {
                $this->triggerSecurityAlert($event);
            }
        }, $context);
    }

    private function normalizeMetrics(array $metrics): array
    {
        return array_map(function($metric) {
            return [
                'value' => $this->validateMetricValue($metric),
                'timestamp' => microtime(true),
                'type' => $this->determineMetricType($metric)
            ];
        }, $metrics);
    }

    private function validateMetrics(array $metrics): void
    {
        foreach ($metrics as $key => $metric) {
            if (!$this->isValidMetric($metric)) {
                throw new MonitoringException("Invalid metric: $key");
            }
        }
    }

    private function analyzeMetrics(array $metrics): void
    {
        foreach ($metrics as $key => $metric) {
            $threshold = $this->getThreshold($key);
            
            if ($metric['value'] > $threshold) {
                $this->handleThresholdExceeded($key, $metric, $threshold);
            }
            
            if ($this->isAnomalous($metric)) {
                $this->handleAnomaly($key, $metric);
            }
        }
    }

    private function isCriticalState(array $metrics): bool
    {
        $criticalCount = 0;
        
        foreach ($metrics as $metric) {
            if ($metric['value'] > $this->criticalThreshold) {
                $criticalCount++;
            }
        }
        
        return $criticalCount >= config('monitoring.critical_threshold');
    }

    private function handleCriticalState(array $metrics, SecurityContext $context): void
    {
        $this->alerts->triggerCriticalAlert($metrics);
        $this->audit->logCriticalState($metrics, $context);
        $this->initiateEmergencyProtocol($metrics);
    }

    private function handleCriticalSecurityEvent(SecurityEvent $event, SecurityContext $context): void
    {
        $this->alerts->triggerSecurityAlert($event);
        $this->audit->logCriticalSecurity($event, $context);
        
        if ($event->requiresSystemLockdown()) {
            $this->initiateSystemLockdown($event);
        }
    }

    private function updateCache(array $metrics): void
    {
        Cache::tags('monitoring')->put(
            'current_metrics',
            $metrics,
            now()->addMinutes(5)
        );
    }

    private function getCurrentMetrics(): array
    {
        return Cache::tags('monitoring')->remember(
            'current_metrics',
            300,
            fn() => $this->repository->getCurrentMetrics()
        );
    }

    private function updateSecurityMetrics(SecurityEvent $event): void
    {
        $this->repository->updateSecurityMetrics([
            'event_type' => $event->getType(),
            'severity' => $event->getSeverity(),
            'timestamp' => microtime(true)
        ]);
    }

    private function requiresImmedateAction(SecurityEvent $event): bool
    {
        return $event->getSeverity() >= config('monitoring.immediate_action_threshold');
    }

    private function initiateEmergencyProtocol(array $metrics): void
    {
        try {
            $this->security->executeEmergencyProtocol($metrics);
        } catch (\Throwable $e) {
            $this->handleEmergencyFailure($e);
        }
    }
}
