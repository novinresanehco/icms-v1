<?php

namespace App\Core\Logging\Notifications;

class NotificationDispatcher implements NotificationDispatcherInterface
{
    private ChannelManager $channelManager;
    private NotificationFormatter $formatter;
    private DeliveryTracker $tracker;
    private Config $config;

    public function __construct(
        ChannelManager $channelManager,
        NotificationFormatter $formatter,
        DeliveryTracker $tracker,
        Config $config
    ) {
        $this->channelManager = $channelManager;
        $this->formatter = $formatter;
        $this->tracker = $tracker;
        $this->config = $config;
    }

    public function dispatch(AlertNotification $notification, array $channels): void
    {
        $dispatchId = Str::uuid();
        
        try {
            // Start tracking
            $this->tracker->startDispatch($dispatchId, $notification, $channels);

            // Format notification for each channel
            foreach ($channels as $channelName) {
                $this->dispatchToChannel($notification, $channelName, $dispatchId);
            }

            // Complete tracking
            $this->tracker->completeDispatch($dispatchId);

        } catch (\Exception $e) {
            // Handle dispatch failure
            $this->handleDispatchFailure($notification, $e, $dispatchId);
        }
    }

    protected function dispatchToChannel(
        AlertNotification $notification,
        string $channelName,
        string $dispatchId
    ): void {
        try {
            // Get channel instance
            $channel = $this->channelManager->getChannel($channelName);

            // Format for channel
            $formattedNotification = $this->formatter->formatForChannel(
                $notification,
                $channelName
            );

            // Send notification
            $result = $channel->send($formattedNotification);

            // Track delivery
            $this->tracker->recordDelivery($dispatchId, $channelName, $result);

        } catch (\Exception $e) {
            // Handle channel failure
            $this->handleChannelFailure($notification, $channelName, $e, $dispatchId);

            // Try fallback if available
            $this->tryFallbackChannel($notification, $channelName, $dispatchId);
        }
    }

    protected function tryFallbackChannel(
        AlertNotification $notification,
        string $failedChannel,
        string $dispatchId
    ): void {
        $fallbackChannel = $this->config->get(
            "notifications.channels.{$failedChannel}.fallback"
        );

        if ($fallbackChannel && 
            $this->channelManager->channelExists($fallbackChannel)) {
            $this->dispatchToChannel($notification, $fallbackChannel, $dispatchId);
        }
    }

    protected function handleDispatchFailure(
        AlertNotification $notification,
        \Exception $e,
        string $dispatchId
    ): void {
        // Log failure
        Log::error('Notification dispatch failed', [
            'dispatch_id' => $dispatchId,
            'notification' => $notification->toArray(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        // Update tracking
        $this->tracker->failDispatch($dispatchId, $e->getMessage());

        // Notify admin if critical
        if ($notification->severity === 'critical') {
            $this->notifyAdminOfFailure($notification, $e);
        }
    }

    protected function handleChannelFailure(
        AlertNotification $notification,
        string $channel,
        \Exception $e,
        string $dispatchId
    ): void {
        // Log channel failure
        Log::error("Channel {$channel} delivery failed", [
            'dispatch_id' => $dispatchId,
            'channel' => $channel,
            'notification' => $notification->toArray(),
            'error' => $e->getMessage()
        ]);

        // Update tracking
        $this->tracker->recordFailure($dispatchId, $channel, $e->getMessage());

        // Mark channel as failing
        $this->channelManager->markChannelFailing($channel);
    }

    protected function notifyAdminOfFailure(
        AlertNotification $notification,
        \Exception $e
    ): void {
        $adminChannel = $this->channelManager->getAdminChannel();

        if ($adminChannel) {
            $adminChannel->send(new AdminNotification(
                'Notification System Failure',
                [
                    'notification' => $notification->toArray(),
                    'error' => $e->getMessage(),
                    'timestamp' => now()
                ]
            ));
        }
    }
}
