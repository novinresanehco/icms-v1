<?php

namespace App\Core\Audit;

use Illuminate\Support\Facades\{DB, Log, Event};
use App\Core\Security\SecurityManager;
use App\Core\Monitoring\PerformanceTracker;

class AuditLogger implements AuditInterface
{
    protected SecurityManager $security;
    protected PerformanceTracker $performance;
    protected EventRepository $events;
    protected array $config;
    
    public function __construct(
        SecurityManager $security,
        PerformanceTracker $performance,
        EventRepository $events,
        array $config
    ) {
        $this->security = $security;
        $this->performance = $performance;
        $this->events = $events;
        $this->config = $config;
    }

    public function logCriticalOperation(string $operation, array $context): void
    {
        $this->security->executeCriticalOperation(function() use ($operation, $context) {
            return DB::transaction(function() use ($operation, $context) {
                $event = [
                    'type' => 'critical_operation',
                    'operation' => $operation,
                    'context' => $this->sanitizeContext($context),
                    'performance_metrics' => $this->performance->getCurrentMetrics(),
                    'user_id' => auth()->id(),
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                    'timestamp' => now(),
                    'hash' => $this->generateEventHash($operation, $context)
                ];

                $this->events->create($event);
                $this->notifySecurityTeam($event);
            });
        });
    }

    public function logSecurityEvent(string $event, array $context): void
    {
        $this->security->executeCriticalOperation(function() use ($event, $context) {
            return DB::transaction(function() use ($event, $context) {
                $eventData = [
                    'type' => 'security_event',
                    'event' => $event,
                    'context' => $this->sanitizeContext($context),
                    'severity' => $this->calculateSeverity($event, $context),
                    'user_id' => auth()->id(),
                    'ip_address' => request()->ip(),
                    'timestamp' => now(),
                    'hash' => $this->generateEventHash($event, $context)
                ];

                $this->events->create($eventData);
                
                if ($this->isHighSeverity($eventData['severity'])) {
                    $this->handleHighSeverityEvent($eventData);
                }
            });
        });
    }

    public function logPerformanceMetric(string $metric, float $value, array $context = []): void
    {
        $this->security->executeCriticalOperation(function() use ($metric, $value, $context) {
            $event = [
                'type' => 'performance_metric',
                'metric' => $metric,
                'value' => $value,
                'context' => $this->sanitizeContext($context),
                'system_load' => $this->performance->getSystemLoad(),
                'timestamp' => now(),
                'hash' => $this->generateEventHash($metric, ['value' => $value, 'context' => $context])
            ];

            $this->events->create($event);

            if ($this->isPerformanceThresholdExceeded($metric, $value)) {
                $this->handlePerformanceIssue($event);
            }
        });
    }

    public function logAccessAttempt(string $resource, bool $granted, array $context = []): void
    {
        $this->security->executeCriticalOperation(function() use ($resource, $granted, $context) {
            $event = [
                'type' => 'access_attempt',
                'resource' => $resource,
                'granted' => $granted,
                'context' => $this->sanitizeContext($context),
                'user_id' => auth()->id(),
                'ip_address' => request()->ip(),
                'timestamp' => now(),
                'hash' => $this->generateEventHash('access_attempt', [
                    'resource' => $resource,
                    'granted' => $granted,
                    'context' => $context
                ])
            ];

            $this->events->create($event);

            if (!$granted) {
                $this->handleUnauthorizedAccess($event);
            }
        });
    }

    protected function sanitizeContext(array $context): array
    {
        return array_map(function($value) {
            if ($this->isSensitive($value)) {
                return '[REDACTED]';
            }
            return $value;
        }, $context);
    }

    protected function isSensitive($value): bool
    {
        foreach ($this->config['sensitive_patterns'] as $pattern) {
            if (preg_match($pattern, (string)$value)) {
                return true;
            }
        }
        return false;
    }

    protected function calculateSeverity(string $event, array $context): string
    {
        return match ($event) {
            'security_breach' => 'critical',
            'authentication_failure' => 'high',
            'validation_failure' => 'medium',
            default => 'low'
        };
    }

    protected function generateEventHash(string $type, array $data): string
    {
        return hash_hmac(
            'sha256',
            json_encode([$type, $data, request()->ip(), now()->timestamp]),
            $this->config['hash_key']
        );
    }

    protected function isHighSeverity(string $severity): bool
    {
        return in_array($severity, ['critical', 'high']);
    }

    protected function isPerformanceThresholdExceeded(string $metric, float $value): bool
    {
        return $value > ($this->config['performance_thresholds'][$metric] ?? PHP_FLOAT_MAX);
    }

    protected function handleHighSeverityEvent(array $event): void
    {
        Event::dispatch(new HighSeveritySecurityEvent($event));
        $this->notifySecurityTeam($event);
    }

    protected function handlePerformanceIssue(array $event): void
    {
        Event::dispatch(new PerformanceThresholdExceeded($event));
    }

    protected function handleUnauthorizedAccess(array $event): void
    {
        Event::dispatch(new UnauthorizedAccessAttempt($event));
        
        if ($this->isRepeatedUnauthorizedAccess($event)) {
            $this->notifySecurityTeam($event);
        }
    }

    protected function isRepeatedUnauthorizedAccess(array $event): bool
    {
        $attempts = $this->events->countRecentByIp(
            $event['ip_address'],
            'access_attempt',
            now()->subMinutes($this->config['access_attempt_window'])
        );
        
        return $attempts >= $this->config['max_access_attempts'];
    }

    protected function notifySecurityTeam(array $event): void
    {
        // Implementation depends on notification system
    }
}
