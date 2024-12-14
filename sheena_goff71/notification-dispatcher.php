<?php

namespace App\Core\Notification\Services;

use App\Core\Notification\Models\Notification;
use App\Core\Notification\Channels\{
    EmailChannel,
    SMSChannel,
    PushChannel,
    DatabaseChannel
};

class NotificationDispatcher
{
    private array $channels;

    public function __construct()
    {
        $this->channels = [
            'email' => new EmailChannel(),
            'sms' => new SMSChannel(),
            'push' => new PushChannel(),
            'database' => new DatabaseChannel()
        ];
    }

    public function dispatch(Notification $notification): void
    {
        $channels = $this->getChannelsForNotification($notification);

        foreach ($channels as $channel) {
            try {
                $this->channels[$channel]->send($notification);
            } catch (\Exception $e) {
                $this->handleChannelFailure($notification, $channel, $e);
            }
        }
    }

    protected function getChannelsForNotification(Notification $notification): array
    {
        $configuredChannels = config("notifications.types.{$notification->type}.channels", ['database']);
        $userPreferences = $notification->user->notification_preferences ?? [];

        return array_intersect(
            $configuredChannels,
            array_keys(array_filter($userPreferences))
        );
    }

    protected function handleChannelFailure(Notification $notification, string $channel, \Exception $e): void
    {
        logger()->error("Notification channel failure", [
            'notification_id' => $notification->id,
            'channel' => $channel,
            'error' => $e->getMessage()
        ]);

        if ($notification->shouldRetry()) {
            $this->queueForRetry($notification, $channel);
        }
    }

    protected function queueForRetry(Notification $notification, string $channel): void
    {
        dispatch(new RetryNotificationJob($notification, $channel))
            ->delay(now()->addMinutes(5))
            ->onQueue('notifications');
    }
}
