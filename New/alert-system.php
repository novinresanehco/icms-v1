<?php

namespace App\Core\Monitoring;

class AlertSystem implements AlertInterface
{
    private NotificationService $notifications;
    private LogManager $logs;
    private AlertStorage $storage;
    private array $config;

    public function __construct(
        NotificationService $notifications,
        LogManager $logs,
        AlertStorage $storage,
        array $config
    ) {
        $this->notifications = $notifications;
        $this->logs = $logs;
        $this->storage = $storage;
        $this->config = $config;
    }

    public function criticalError(string $monitoringId, \Throwable $error, array $context): void
    {
        $alert = [
            'id' => $this->generateAlertId(),
            'type' => 'critical_error',
            'monitoring_id' => $monitoringId,
            'timestamp' => microtime(true),
            'error' => [
                'message' => $error->getMessage(),
                'code' => $error->getCode(),
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'trace' => $error->getTraceAsString()
            ],
            'context' => $context,
            'severity' => 'critical',
            'status' => 'active'
        ];

        $this->processAlert($alert);
    }

    public function thresholdExceeded(string $monitoringId, string $metric, $value, $threshold): void
    {
        $alert = [
            'id' => $this->generateAlertId(),
            'type' => 'threshold_exceeded',
            'monitoring_id' => $monitoringId,
            'timestamp' => microtime(true),
            'data' => [
                'metric' => $metric,
                'value' => $value,
                'threshold' => $threshold
            ],
            'severity' => $this->determineThresholdSeverity($metric, $value, $threshold),
            'status' => 'active'
        ];

        $this->processAlert($alert);
    }

    public function performanceCritical(string $monitoringId, array $analysis): void
    {
        $alert = [
            'id' => $this->generateAlertId(),
            'type' => 'performance_critical',
            'monitoring_id' => $monitoringId,
            'timestamp' => microtime(true),
            'data' => $analysis,
            'severity' => 'critical',
            'status' => 'active'
        ];

        $this->processAlert($alert);
    }

    public function securityEvent(string $monitoringId, string $type, array $data): void
    {
        $alert = [
            'id' => $this->generateAlertId(),
            'type' => 'security_event',
            'sub_type' => $type,
            'monitoring_id' => $monitoringId,
            'timestamp' => microtime(true),
            'data' => $data,
            'severity' => 'critical',
            'status' => 'active'
        ];

        $this->processAlert($alert);
    }

    protected function processAlert(array $alert): void
    {
        // Store alert
        $this->storage->store($alert);

        // Log alert
        $this->logs->alert('System alert generated', [
            'alert_id' => $alert['id'],
            'type' => $alert['type'],
            'severity' => $alert['severity']
        ]);

        // Send notifications
        $this->sendAlertNotifications($alert);

        // Execute alert-specific actions
        $this->executeAlertActions($alert);
    }

    protected function sendAlertNotifications(array $alert): void
    {
        $channels = $this->determineNotificationChannels($alert);
        $message = $this->formatAlertMessage($alert);

        foreach ($channels as $channel) {
            try {
                $this->notifications->send($channel, $message);
                $this->storage->markNotified($alert['id'], $channel);
            } catch (\Throwable $e) {
                $this->logs->error('Failed to send alert notification', [
                    'alert_id' => $alert['id'],
                    'channel' => $channel,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    protected function executeAlertActions(array $alert): void
    {
        $actions = $this->determineAlertActions($alert);

        foreach ($actions as $action) {
            try {
                $action->execute($alert);
                $this->storage->recordAction($alert['id'], $action::class);
            } catch (\Throwable $e) {
                $this->logs->error('Failed to execute alert action', [
                    'alert_id' => $alert['id'],
                    'action' => $action::class,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    protected function determineThresholdSeverity(string $metric, $value, $threshold): string
    {
        $criticalThreshold = $threshold * $this->config['critical_multiplier'];
        return $value > $criticalThreshold ? 'critical' : 'warning';
    }

    protected function determineNotificationChannels(array $alert): array
    {
        return match($alert['severity']) {
            'critical' => $this->config['channels']['critical'],
            'warning' => $this->config['channels']['warning'],
            default => $this->config['channels']['default']
        };
    }

    protected function formatAlertMessage(array $alert): array
    {
        return [
            'title' => $this->getAlertTitle($alert),
            'message' => $this->getAlertMessage($alert),
            'severity' => $alert['severity'],
            'timestamp' => $alert['timestamp'],
            'data' => $alert['data'] ?? null
        ];
    }

    protected function determineAlertActions(array $alert): array
    {
        return match($alert['type']) {
            'critical_error' => [new ErrorRecoveryAction(), new SystemDiagnosticAction()],
            'security_event' => [new SecurityResponseAction(), new AuditLogAction()],
            'threshold_exceeded' => [new ResourceOptimizationAction()],
            default => []
        };
    }

    protected function generateAlertId(): string
    {
        return uniqid('alert_', true);
    }

    protected function getAlertTitle(array $alert): string
    {
        return match($alert['type']) {
            'critical_error' => 'Critical System Error',
            'threshold_exceeded' => "Performance Threshold Exceeded: {$alert['data']['metric']}",
            'security_event' => "Security Event: {$alert['sub_type']}",
            default => 'System Alert'
        };
    }

    protected function getAlertMessage(array $alert): string
    {
        return match($alert['type']) {
            'critical_error' => $alert['error']['message'],
            'threshold_exceeded' => "Value {$alert['data']['value']} exceeds threshold {$alert['data']['threshold']}",
            'security_event' => $this->formatSecurityMessage($alert),
            default => 'System alert triggered'
        };
    }

    protected function formatSecurityMessage(array $alert): string
    {
        // Implementation depends on security event types
        return "Security event detected: {$alert['sub_type']}";
    }
}

interface AlertInterface
{
    public function criticalError(string $monitoringId, \Throwable $error, array $context): void;
    public function thresholdExceeded(string $monitoringId, string $metric, $value, $threshold): void;
    public function performanceCritical(string $monitoringId, array $analysis): void;
    public function securityEvent(string $monitoringId, string $type, array $data): void;
}
