<?php

namespace App\Core\Audit;

class AuditLogger implements AuditInterface
{
    private LoggerInterface $logger;
    private MetricsCollector $metrics;
    private NotificationService $notifications;

    public function logTokenCreation(Token $token): void
    {
        $this->log('token_created', [
            'user_id' => $token->getClaim('user_id'),
            'expires_at' => $token->getClaim('expires_at'),
            'permissions' => $token->getClaim('permissions')
        ]);
    }

    public function logTokenValidationFailure(string $token, \Exception $e): void
    {
        $this->log('token_validation_failed', [
            'token' => hash('sha256', $token),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 'critical');
    }

    public function logTokenRevocation(string $token): void
    {
        $this->log('token_revoked', [
            'token' => hash('sha256', $token)
        ]);
    }

    public function logSecurityEvent(string $event, array $context): void
    {
        $this->log("security_{$event}", $context, 'critical');
        $this->notifications->notifySecurityTeam($event, $context);
    }

    public function logValidationFailure(array $data, array $errors): void
    {
        $this->log('validation_failed', [
            'data' => $data,
            'errors' => $errors
        ]);
    }

    public function logSystemFailure(\Exception $e): void
    {
        $this->log('system_failure', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 'emergency');

        $this->notifications->notifySystemFailure($e);
    }

    private function log(string $event, array $context, string $level = 'info'): void
    {
        $context['timestamp'] = time();
        $context['memory'] = memory_get_usage(true);
        
        $this->logger->log($level, $event, $context);
        $this->metrics->recordEvent($event, $context);
    }
}

class MetricsCollector implements MetricsInterface
{
    private array $metrics = [];
    private array $thresholds;

    public function recordEvent(string $event, array $context): void
    {
        $this->metrics[] = [
            'event' => $event,
            'context' => $context,
            'timestamp' => time()
        ];

        $this->checkThresholds($event, $context);
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    private function checkThresholds(string $event, array $context): void
    {
        if (!isset($this->thresholds[$event])) {
            return;
        }

        foreach ($this->thresholds[$event] as $metric => $threshold) {
            if ($context[$metric] > $threshold) {
                $this->handleThresholdExceeded($event, $metric, $context[$metric], $threshold);
            }
        }
    }

    private function handleThresholdExceeded(string $event, string $metric, $value, $threshold): void
    {
        $this->notifications->notifyThresholdExceeded(
            $event,
            $metric,
            $value,
            $threshold
        );
    }
}

class NotificationService implements NotificationInterface
{
    private array $channels;
    private array $recipients;

    public function notifySecurityTeam(string $event, array $context): void
    {
        $this->notify(
            'security_team',
            "Security Event: {$event}",
            $context
        );
    }

    public function notifySystemFailure(\Exception $e): void
    {
        $this->notify(
            'system_admins',
            'System Failure',
            [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]
        );
    }

    public function notifyThresholdExceeded(string $event, string $metric, $value, $threshold): void
    {
        $this->notify(
            'monitoring_team',
            "Threshold Exceeded: {$event}.{$metric}",
            [
                'value' => $value,
                'threshold' => $threshold,
                'timestamp' => time()
            ]
        );
    }

    private function notify(string $group, string $message, array $context): void
    {
        foreach ($this->channels as $channel) {
            $channel->send(
                $this->recipients[$group],
                $message,
                $context
            );
        }
    }
}
