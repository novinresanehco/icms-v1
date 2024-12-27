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
        $