<?php

namespace App\Core\Health\Notifications;

class HealthNotificationManager
{
    private NotificationService $notifications;
    private NotificationStorage $storage;
    private array $config;

    public function __construct(
        NotificationService $notifications,
        NotificationStorage $storage,
        array $config
    ) {
        $this->notifications = $notifications;
        $this->storage = $storage;
        $this->config = $config;
    }

    public function handleHealthReport(HealthReport $report): void
    {
        if ($report->overall === HealthStatus::Healthy) {
            $this->resolveExistingAlerts();
            return;
        }

        foreach ($report->results as $check => $result) {
            if ($result->status === HealthStatus::Critical || $result->status === HealthStatus::Warning) {
                $this->handleUnhealthyCheck($check, $result);
            }
        }
    }

    private function handleUnhealthyCheck(string $check, HealthResult $result): void
    {
        $existingAlert = $this->storage->findActiveAlert($check);
        
        if ($existingAlert && $existingAlert['status'] === $result->status->value) {
            return;
        }

        $alert = $this->storage->createAlert([
            'check_name' => $check,
            'status' => $result->status->value,
            'message' => $result->message,
            'metrics' => $result->metrics
        ]);

        $this->sendNotification($alert);
    }

    private function resolveExistingAlerts(): void
    {
        $activeAlerts = $this->storage->findActiveAlerts();
        
        foreach ($activeAlerts as $alert) {
            $this->storage->markResolved($alert['id']);
            $this->sendRecoveryNotification($alert);
        }
    }

    private function sendNotification(array $alert): void
    {
        $channels = $this->determineNotificationChannels($alert);
        $message = $this->formatAlertMessage($alert);

        foreach ($channels as $channel) {
            try {
                $this->notifications->send($channel, $message);
                $this->storage->markNotified($alert['id']);
            } catch (\Throwable $e) {
                Log::error('Failed to send health notification', [
                    'channel' => $channel,
                    'alert' => $alert,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    private function sendRecoveryNotification(array $alert): void
    {
        $channels = $this->determineNotificationChannels($alert);
        $message = $this->formatRecoveryMessage($alert);

        foreach ($channels as $channel) {
            try {
                $this->notifications->send($channel, $message);
            } catch (\Throwable $e) {
                Log::error('Failed to send recovery notification', [
                    'channel' => $channel,
                    'alert' => $alert,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    private function determineNotificationChannels(array $alert): array
    {
        if ($alert['status'] === HealthStatus::Critical->value) {
            return $this->config['channels']['critical'] ?? ['email', 'slack'];
        }

        return $this->config['