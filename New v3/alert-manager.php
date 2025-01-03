<?php

namespace App\Core\Alert;

use App\Core\Security\SecurityManager;
use App\Core\Metrics\MetricsCollector;
use App\Core\Notification\NotificationService;
use App\Core\Validation\ValidationService;
use App\Core\Logging\AuditLogger;

class AlertManager implements AlertInterface
{
    private SecurityManager $security;
    private MetricsCollector $metrics;
    private NotificationService $notifications;
    private ValidationService $validator;
    private AuditLogger $logger;
    private array $thresholds;
    private array $activeAlerts = [];

    public function triggerAlert(string $type, array $data): void
    {
        $alertId = $this->generateAlertId();
        
        try {
            $this->validateAlertData($type, $data);
            $this->security->validateAccess('alert.trigger');

            $severity = $this->calculateSeverity($type, $data);
            $this->processAlert($alertId, $type, $data, $severity);
            
            $this->notifyRecipients($type, $data, $severity);
            $this->recordAlert($alertId, $type, $data, $severity);

        } catch (\Exception $e) {
            $this->handleAlertFailure($e, $type, $data);
            throw $e;
        }
    }

    public function checkThresholds(array $metrics): void
    {
        foreach ($metrics as $metric => $value) {
            if (isset($this->thresholds[$metric])) {
                $threshold = $this->thresholds[$metric];
                
                if ($value > $threshold['critical']) {
                    $this->triggerAlert('threshold_breach', [
                        'metric' => $metric,
                        'value' => $value,
                        'threshold' => $threshold['critical'],
                        'severity' => 'critical'
                    ]);
                } elseif ($value > $threshold['warning']) {
                    $this->triggerAlert('threshold_breach', [
                        'metric' => $metric,
                        'value' => $value,
                        'threshold' => $threshold['warning'],
                        'severity' => 'warning'
                    ]);
                }
            }
        }
    }

    public function resolveAlert(string $alertId): void
    {
        try {
            $this->validateAlertId($alertId);
            $this->security->validateAccess('alert.resolve');

            if (!isset($this->activeAlerts[$alertId])) {
                throw new AlertException("Alert not found: {$alertId}");
            }

            $alert = $this->activeAlerts[$alertId];
            $this->processAlertResolution($alertId, $alert);
            
            unset($this->activeAlerts[$alertId]);
            $this->recordResolution($alertId);

        } catch (\Exception $e) {
            $this->handleResolutionFailure($e, $alertId);
            throw $e;
        }
    }

    public function getActiveAlerts(): array
    {
        try {
            $this->security->validateAccess('alert.view');
            return $this->activeAlerts;
        } catch (\Exception $e) {
            $this->handleViewFailure($e);
            throw $e;
        }
    }

    public function notifyOperationFailure(string $operationId, \Exception $e): void
    {
        $this->triggerAlert('operation_failure', [
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'severity' => 'critical'
        ]);
    }

    public function notifyMonitoringFailure(string $stage, array $context): void
    {
        $this->triggerAlert('monitoring_failure', [
            'stage' => $stage,
            'context' => $context,
            'severity' => 'critical'
        ]);
    }

    public function initializeMonitoring(string $operationId): void
    {
        try {
            $this->validateOperationId($operationId);
            $this->metrics->initializeMetrics($operationId);
            $this->resetAlertState($operationId);
        } catch (\Exception $e) {
            $this->handleInitializationFailure($e, $operationId);
            throw $e;
        }
    }

    protected function processAlert(string $alertId, string $type, array $data, string $severity): void
    {
        $alert = [
            'id' => $alertId,
            'type' => $type,
            'data' => $data,
            'severity' => $severity,
            'timestamp' => time(),
            'status' => 'active'
        ];

        $this->activeAlerts[$alertId] = $alert;
        
        if ($severity === 'critical') {
            $this->handleCriticalAlert($alert);
        }
    }

    protected function calculateSeverity(string $type, array $data): string
    {
        return match($type) {
            'security_breach' => 'critical',
            'threshold_breach' => $data['severity'] ?? 'warning',
            'operation_failure' => 'critical',
            'monitoring_failure' => 'critical',
            default => 'warning'
        };
    }

    protected function notifyRecipients(string $type, array $data, string $severity): void
    {
        $recipients = $this->getAlertRecipients($type, $severity);
        
        foreach ($recipients as $recipient) {
            $this->notifications->send(
                $recipient,
                $this->formatAlertMessage($type, $data),
                $this->getAlertPriority($severity)
            );
        }
    }

    protected function handleCriticalAlert(array $alert): void
    {
        $this->notifications->notifyAdministrators(
            'Critical Alert: ' . $alert['type'],
            $alert
        );

        $this->logger->critical('Critical alert triggered', $alert);
        
        if ($this->requiresSystemAction($alert)) {
            $this->executeSystemAction($alert);
        }
    }

    protected function validateAlertData(string $type, array $data): void
    {
        if (!$this->validator->validateAlertType($type)) {
            throw new AlertException("Invalid alert type: {$type}");
        }

        if (!$this->validator->validateAlertData($type, $data)) {
            throw new AlertException('Invalid alert data');
        }
    }

    protected function validateAlertId(string $alertId): void
    {
        if (!preg_match('/^alert_[a-f0-9]{32}$/', $alertId)) {
            throw new AlertException('Invalid alert ID format');
        }
    }

    protected function recordAlert(string $alertId, string $type, array $data, string $severity): void
    {
        $this->logger->alert('Alert triggered', [
            'alert_id' => $alertId,
            'type' => $type,
            'data' => $data,
            'severity' => $severity
        ]);

        $this->metrics->incrementCounter("alerts.{$type}");
    }

    protected function recordResolution(string $alertId): void
    {
        $this->logger->info('Alert resolved', [
            'alert_id' => $alertId
        ]);

        $this->metrics->incrementCounter('alerts.resolved');
    }

    private function generateAlertId(): string
    {
        return 'alert_' . md5(uniqid(mt_rand(), true));
    }

    private function handleAlertFailure(\Exception $e, string $type, array $data): void
    {
        $this->logger->error('Alert processing failed', [
            'type' => $type,
            'data' => $data,
            'error' => $e->getMessage()
        ]);

        $this->metrics->incrementCounter('alert_failures');
    }
}
