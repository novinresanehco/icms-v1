<?php

namespace App\Core\Notification\Listeners;

use App\Core\Notification\Events\{
    NotificationCreated,
    NotificationSent,
    NotificationFailed
};
use App\Core\Monitoring\MetricsCollector;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\Log;

class NotificationEventSubscriber
{
    protected MetricsCollector $metrics;
    protected CacheManager $cache;

    /**
     * Create a new event subscriber instance.
     *
     * @param MetricsCollector $metrics
     * @param CacheManager $cache
     */
    public function __construct(MetricsCollector $metrics, CacheManager $cache)
    {
        $this->metrics = $metrics;
        $this->cache = $cache;
    }

    /**
     * Handle notification created events.
     *
     * @param NotificationCreated $event
     * @return void
     */
    public function handleNotificationCreated(NotificationCreated $event): void
    {
        try {
            // Track metrics
            $this->metrics->increment('notifications.created', 1, [
                'type' => $event->notification->type
            ]);

            // Clear relevant caches
            $this->clearNotificationCaches($event->notification);

            // Log event
            Log::info('Notification created', [
                'notification_id' => $event->notification->id,
                'type' => $event->notification->type
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to handle notification created event', [
                'notification_id' => $event->notification->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle notification sent events.
     *
     * @param NotificationSent $event
     * @return void
     */
    public function handleNotificationSent(NotificationSent $event): void
    {
        try {
            // Track metrics
            $this->metrics->increment('notifications.sent', 1, [
                'type' => $event->notification->type,
                'channel' => $event->channel
            ]);

            // Track delivery time
            $this->metrics->timing(
                'notifications.delivery_time',
                $event->notification->created_at->diffInMilliseconds(now()),
                ['channel' => $event->channel]
            );

            // Log event
            Log::info('Notification sent', [
                'notification_id' => $event->notification->id,
                'channel' => $event->channel
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to handle notification sent event', [
                'notification_id' => $event->notification->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle notification failed events.
     *
     * @param NotificationFailed $event
     * @return void
     */
    public function handleNotificationFailed(NotificationFailed $event): void
    {
        try {
            // Track metrics
            $this->metrics->increment('notifications.failed', 1, [
                'type' => $event->notification->type,
                'channel' => $event->channel
            ]);

            // Log failure
            Log::error('Notification failed', [
                'notification_id' => $event->notification->id,
                'channel' => $event->channel,
                'error' => $event->error
            ]);

            // Potentially trigger alerts or fallback mechanisms
            $this->handleFailure($event);

        } catch (\Exception $e) {
            Log::error('Failed to handle notification failure event', [
                'notification_id' => $event->notification->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Clear notification related caches.
     *
     * @param Notification $notification
     * @return void
     */
    protected function clearNotificationCaches($notification): void
    {
        $tags = [
            'notifications',
            "notifications:user:{$notification->notifiable_id}",
            "notifications:type:{$notification->type}"
        ];

        $this->cache->tags($tags)->flush();
    }

    /**
     * Handle notification failure.
     *
     * @param NotificationFailed $event
     * @return void
     */
    protected function handleFailure(NotificationFailed $event): void
    {
        // Check if we need to trigger alerts
        if ($this->shouldTriggerAlert($event)) {
            // Trigger appropriate alerts
            $this->triggerFailureAlert($event);
        }

        // Check if we should attempt fallback
        if ($this->shouldAttemptFallback($event)) {
            // Attempt fallback delivery
            $this->attemptFallbackDelivery($event);
        }
    }

    /**
     * Register the listeners for the subscriber.
     *
     * @param \Illuminate\Events\Dispatcher $events
     * @return array
     */
    public function subscribe($events): array
    {
        return [
            NotificationCreated::class => 'handleNotificationCreated',
            NotificationSent::class => 'handleNotificationSent',
            NotificationFailed::class => 'handleNotificationFailed',
        ];
    }

    /**
     * Determine if we should trigger an alert for the failure.
     *
     * @param NotificationFailed $event
     * @return bool
     */
    protected function shouldTriggerAlert(NotificationFailed $event): bool
    {
        // Check failure threshold
        $failureCount = $this->metrics->getCount('notifications.failed', [
            'type' => $event->notification->type,
            'channel' => $event->channel
        ]);

        return $failureCount >= config('notifications.alert_threshold');
    }

    /**
     * Trigger an alert for notification failure.
     *
     * @param NotificationFailed $event
     * @return void
     */
    protected function triggerFailureAlert(NotificationFailed $event): void
    {
        // Implementation for triggering alerts (e.g., to monitoring system)
        // This could be implemented based on your monitoring infrastructure
    }

    /**
     * Determine if we should attempt fallback delivery.
     *
     * @param NotificationFailed $event
     * @return bool
     */
    protected function shouldAttemptFallback(NotificationFailed $event): bool
    {
        return config('notifications.enable_fallback') 
            && isset($event->notification->data['fallback_channels']);
    }

    /**
     * Attempt fallback delivery of the notification.
     *
     * @param NotificationFailed $event
     * @return void
     */
    protected function attemptFallbackDelivery(NotificationFailed $event): void
    {
        // Implementation for fallback delivery
        // This could attempt delivery through alternative channels
    }
}