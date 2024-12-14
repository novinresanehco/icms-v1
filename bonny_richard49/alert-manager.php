<?php

namespace App\Core\Monitoring;

class AlertManager implements AlertManagerInterface
{
    private AlertStore $store;
    private NotificationService $notifications;
    private EscalationService $escalation;
    private array $config;

    public function triggerAlert(array $alert): void
    {
        $operationId = uniqid('alert_', true);

        try {
            $this->validateAlert($alert);
            $this->processAlert($alert, $operationId);
            $this->storeAlert($alert, $operationId);
            $this->notifyAlert($alert);
            $this->handleEscalation($alert);

        } catch (\Throwable $e) {
            $this->handleAlertFailure($e, $alert, $operationId);
            throw $e;
        }
    }

    public function triggerCriticalAlert(array $alert): void
    {
        $operationId = uniqid('critical_', true);

        try {
            $this->validateCriticalAlert($alert);
            $this->processCriticalAlert($alert, $operationId);
            $this->storeCriticalAlert($alert, $operationId);
            $this->notifyCriticalAlert($alert);
            $this->escalateCriticalAlert($alert);

        } catch (\Throwable $e) {
            $this->handleCriticalFailure($e, $alert, $operationId);
            throw $e;
        }
    }

    protected function validateAlert(array $alert): void
    {
        if (!isset($alert['type'], $alert['severity'])) {
            throw new AlertException('Invalid alert format');
        }

        if (!$this->isValidSeverity($alert['severity'])) {
            throw new AlertException('Invalid alert severity');
        }
    }

    protected function validateCriticalAlert(array $alert): void
    {
        $this->validateAlert($alert);

        if ($alert['severity'] !== 'CRITICAL') {
            throw new AlertException('Invalid critical alert severity');
        }
    }

    protected function processAlert(array $alert, string $operationId): void
    {
        $alert['timestamp'] = time();
        $alert['operation_id'] = $operationId;
        $alert['status'] = 'NEW';
    }

    protected function processCriticalAlert(array $alert, string $operationId): void
    {
        $this->processAlert($alert, $operationId);
        $alert['priority'] = 'HIGHEST';
        $alert['requires_ack'] = true;
    }

    protected function storeAlert(array $alert, string $operationId): void
    {
        $this->store->store([
            'alert' => $alert,
            'operation_id' => $operationId,
            'timestamp' => time()
        ]);
    }

    protected function storeCriticalAlert(array $alert, string $operationId): void
    {
        $this->store->storeCritical([
            'alert' => $alert,
            'operation_id' => $operationId,
            'timestamp' => time()
        ]);
    }

    protected function notifyAlert(array $alert): void
    {
        $this->notifications->send($alert, $this->getNotificationTargets($alert));
    }

    protected function notifyCriticalAlert(array $alert): void
    {
        $this->notifications->sendCritical(
            $alert,
            $this->getCriticalNotificationTargets($alert)
        );
    }

    protected function handleEscalation(array $alert): void
    {
        if ($this->needsEscalation($alert)) {
            $this->escalation->escalateAlert($alert);
        }
    }

    protected function escalateCriticalAlert(array $alert): void
    {
        $this->escalation->escalateCriticalAlert($alert);
    }

    protected function handleAlertFailure(
        \Throwable $e,
        array $alert,
        string $operationId
    ): void {
        $this->store->storeFailure([
            'error' => $e->getMessage(),
            'alert' => $alert,
            'operation_id' => $operationId,
            'timestamp' => time()
        ]);
    }

    protected function handleCriticalFailure(
        \Throwable $e,
        array $alert,
        string $operationId
    ): void {
        $this->store->storeCriticalFailure([
            'error' => $e->getMessage(),
            'alert' => $alert,
            'operation_id' => $operationId,
            'timestamp' => time()
        ]);

        $this->escalation->escalateFailure($e, $alert);
    }

    protected function isValidSeverity(string $severity): bool
    {
        return in_array($severity, ['INFO', 'WARNING', 'ERROR', 'CRITICAL']);
    }

    protected function needsEscalation(array $alert): bool
    {
        return $alert['severity'] === 'ERROR' || 
               isset($alert['escalate']) && $alert['escalate'] === true;
    }

    protected function getNotificationTargets(array $alert): array
    {
        return $this->config['notification_targets'][$alert['severity']] ?? [];
    }

    protected function getCriticalNotificationTargets(array $alert): array
    {
        return array_merge(
            $this->config['critical_notification_targets'],
            $this->getNotificationTargets($alert)
        );
    }
}
