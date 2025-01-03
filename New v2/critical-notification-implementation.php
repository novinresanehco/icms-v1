<?php

namespace App\Core\Notification;

class NotificationManager implements NotificationManagerInterface
{
    private NotificationStore $store;
    private SecurityManager $security;
    private MetricsCollector $metrics;
    private NotificationConfig $config;
    private array $channels;

    public function send(Notifiable $recipient, Notification $notification): void
    {
        $monitorId = $this->metrics->startOperation('notification.send');
        
        try {
            // Pre-send validation
            $this->validateNotification($notification);
            
            // Process notification
            $this->processNotification($recipient, $notification);
            
            // Record metrics
            $this->metrics->recordSuccess($monitorId);
            
        } catch (\Exception $e) {
            $this->metrics->recordFailure($monitorId, $e);
            $this->handleFailure($e, $recipient, $notification);
            throw $e;
        }
    }

    public function sendUrgent(Notifiable $recipient, UrgentNotification $notification): void
    {
        DB::transaction(function() use ($recipient, $notification) {
            // Send through all urgent channels
            foreach ($this->config->getUrgentChannels() as $channel) {
                $this->sendThroughChannel($recipient, $notification, $channel);
            }
            
            // Log urgent notification
            $this->logUrgentNotification($recipient, $notification);
            
            // Execute urgent protocols
            $this->executeUrgentProtocols($notification);
        });
    }

    public function sendBulk(array $recipients, Notification $notification): void
    {
        foreach (array_chunk($recipients, 100) as $chunk) {
            $this->processBulkNotification($chunk, $notification);
        }
    }

    private function processNotification(Notifiable $recipient, Notification $notification): void
    {
        // Get preferred channels
        $channels = $this->getChannels($recipient, $notification);
        
        // Send through each channel
        foreach ($channels as $channel) {
            $this->sendThroughChannel($recipient, $notification, $channel);
        }
        
        // Store notification
        $this->storeNotification($recipient, $notification);
        
        // Execute notification hooks
        $this->executeNotificationHooks($notification);
    }

    private function validateNotification(Notification $notification): void
    {
        if (!$this->security->validateNotification($notification)) {
            throw new SecurityException('Invalid notification content');
        }
        
        if ($notification->isUrgent() && !$this->validateUrgentNotification($notification)) {
            throw new ValidationException('Invalid urgent notification');
        }
    }

    private function getChannels(Notifiable $recipient, Notification $notification): array
    {
        $channels = $recipient->notificationChannels();
        
        if ($notification->isUrgent()) {
            $channels = array_merge($channels, $this->config->getUrgentChannels());
        }
        
        return array_unique($channels);
    }

    private function sendThroughChannel(Notifiable $recipient, Notification $notification, string $channel): void
    {
        $driver = $this->resolveChannel($channel);
        
        $monitorId = $this->metrics->startOperation("notification.channel.{$channel}");
        
        try {
            $driver->send($recipient, $notification);
            $this->metrics->recordSuccess($monitorId);
            
        } catch (\Exception $e) {
            $this->metrics->recordFailure($monitorId, $e);
            $this->handleChannelFailure($e, $recipient, $notification, $channel);
            
            if ($notification->isUrgent()) {
                throw $e;
            }
        }
    }

    private function resolveChannel(string $channel): NotificationChannel
    {
        if (!isset($this->channels[$channel])) {
            $this->channels[$channel] = app()->make(
                $this->config->getChannelDriver($channel)
            );
        }
        
        return $this->channels[$channel];
    }

    private function storeNotification(Notifiable $recipient, Notification $notification): void
    {
        $this->store->create([
            'recipient_id' => $recipient->getId(),
            'recipient_type' => get_class($recipient),
            'notification' => serialize($notification),
            'channels' => $notification->channels,
            'status' => NotificationStatus::SENT,
            'sent_at' => now(),
        ]);
    }

    private function executeNotificationHooks(Notification $notification): void
    {
        foreach ($notification->getHooks() as $hook) {
            $this->executeHook($hook, $notification);
        }
    }

    private function executeHook(string $hook, Notification $notification): void
    {
        match($hook) {
            'audit' => $this->auditNotification($notification),
            'metrics' => $this->recordMetrics($notification),
            'archive' => $this->archiveNotification($notification),
            default => null
        };
    }

    private function handleFailure(\Exception $e, Notifiable $recipient, Notification $notification): void
    {
        // Log failure
        Log::error('Notification failed', [
            'recipient' => $recipient->getId(),
            'notification' => get_class($notification),
            'error' => $e->getMessage()
        ]);

        // Update metrics
        $this->metrics->incrementCounter('notification.failures');

        // Store failed status
        $this->store->markAsFailed($notification, $e->getMessage());

        // Execute failure protocols
        if ($notification->isUrgent()) {
            $this->executeFailureProtocols($recipient, $notification, $e);
        }
    }

    private function handleChannelFailure(\Exception $e, Notifiable $recipient, Notification $notification, string $channel): void
    {
        // Log channel failure
        Log::warning("Channel {$channel} failed", [
            'recipient' => $recipient->getId(),
            'notification' => get_class($notification),
            'error' => $e->getMessage()
        ]);

        // Update channel metrics
        $this->metrics->incrementCounter("notification.channel.{$channel}.failures");

        // Try fallback channel if available
        if ($fallback = $this->getFallbackChannel($channel)) {
            $this->sendThroughChannel($recipient, $notification, $fallback);
        }
    }

    private function executeFailureProtocols(Notifiable $recipient, Notification $notification, \Exception $e): void
    {
        // Notify admin team
        $this->notifyAdminTeam($recipient, $notification, $e);

        // Execute emergency protocols if needed
        if ($notification instanceof EmergencyNotification) {
            $this->security->executeEmergencyProtocols($notification);
        }

        // Log critical failure
        if ($notification->isCritical()) {
            $this->security->logCriticalFailure('notification', [
                'recipient' => $recipient->getId(),
                'notification' => get_class($notification),
                'error' => $e->getMessage()
            ]);
        }
    }

    private function processBulkNotification(array $recipients, Notification $notification): void
    {
        $monitorId = $this->metrics->startOperation('notification.bulk');
        
        try {
            foreach ($recipients as $recipient) {
                try {
                    $this->send($recipient, $notification);
                } catch (\Exception $e) {
                    $this->handleBulkRecipientFailure($e, $recipient, $notification);
                }
            }
            
            $this->metrics->recordSuccess($monitorId);
            
        } catch (\Exception $e) {
            $this->metrics->recordFailure($monitorId, $e);
            $this->handleBulkFailure($e, $recipients, $notification);
        }
    }
}