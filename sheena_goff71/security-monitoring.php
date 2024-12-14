<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{Log, Cache, DB};
use App\Core\Events\SecurityEvent;

class SecurityMonitor
{
    protected EventProcessor $processor;
    protected AlertManager $alerts;
    protected int $threshold;
    protected array $patterns;

    public function __construct(
        EventProcessor $processor,
        AlertManager $alerts,
        int $threshold = 5,
        array $patterns = []
    ) {
        $this->processor = $processor;
        $this->alerts = $alerts;
        $this->threshold = $threshold;
        $this->patterns = $patterns;
    }

    public function trackEvent(SecurityEvent $event): void
    {
        DB::transaction(function() use ($event) {
            $this->processor->process($event);
            
            if ($this->isAnomalous($event)) {
                $this->handleAnomaly($event);
            }

            if ($this->isThresholdExceeded($event)) {
                $this->triggerAlert($event);
            }

            $this->updateMetrics($event);
        });
    }

    protected function isAnomalous(SecurityEvent $event): bool
    {
        foreach ($this->patterns as $pattern) {
            if ($pattern->matches($event)) {
                return true;
            }
        }
        return false;
    }

    protected function isThresholdExceeded(SecurityEvent $event): bool
    {
        $key = "security.events.{$event->type}.count";
        $count = Cache::increment($key);
        
        return $count >= $this->threshold;
    }

    protected function handleAnomaly(SecurityEvent $event): void
    {
        Log::warning('Security anomaly detected', [
            'event' => $event->toArray(),
            'timestamp' => now(),
            'context' => $this->getSecurityContext()
        ]);

        $this->processor->flagAnomaly($event);
        $this->alerts->notifySecurityTeam($event);
    }

    protected function triggerAlert(SecurityEvent $event): void
    {
        $this->alerts->trigger([
            'level' => 'critical',
            'type' => 'security_threshold_exceeded',
            'event' => $event->toArray(),
            'timestamp' => now()
        ]);

        $this->initiateAutoProtection($event);
    }

    protected function initiateAutoProtection(SecurityEvent $event): void
    {
        if ($event->requiresImmediateAction()) {
            $this->processor->blockSource($event->source);
            $this->processor->enableEnhancedMonitoring();
            $this->alerts->notifyAdministrators($event);
        }
    }

    protected function updateMetrics(SecurityEvent $event): void
    {
        $metrics = [
            'timestamp' => now(),
            'event_type' => $event->type,
            'severity' => $event->severity,
            'source' => $event->source,
            'context' => $this->getSecurityContext()
        ];

        DB::table('security_metrics')->insert($metrics);
    }

    protected function getSecurityContext(): array
    {
        return [
            'system_load' => sys_getloadavg(),
            'memory_usage' => memory_get_usage(true),
            'active_connections' => $this->getActiveConnections(),
            'error_rate' => $this->getCurrentErrorRate()
        ];
    }

    protected function getActiveConnections(): int
    {
        return DB::table('sessions')->where('last_activity', '>=', now()->subMinutes(5))->count();
    }

    protected function getCurrentErrorRate(): float
    {
        $total = Cache::get('security.requests.total', 0);
        $errors = Cache::get('security.requests.errors', 0);
        
        return $total > 0 ? ($errors / $total) * 100 : 0;
    }
}
