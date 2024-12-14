<?php

namespace App\Core\Notification\Events;

use App\Core\Notification\Models\Notification;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;

class NotificationCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The notification instance.
     *
     * @var Notification
     */
    public $notification;

    /**
     * Create a new event instance.
     *
     * @param Notification $notification
     */
    public function __construct(Notification $notification)
    {
        $this->notification = $notification;
    }
}

class NotificationUpdated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The notification instance.
     *
     * @var Notification
     */
    public $notification;

    /**
     * Create a new event instance.
     *
     * @param Notification $notification
     */
    public function __construct(Notification $notification)
    {
        $this->notification = $notification;
    }
}

class NotificationSent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The notification instance.
     *
     * @var Notification
     */
    public $notification;

    /**
     * The channel used to send the notification.
     *
     * @var string
     */
    public $channel;

    /**
     * Create a new event instance.
     *
     * @param Notification $notification
     * @param string $channel
     */
    public function __construct(Notification $notification, string $channel)
    {
        $this->notification = $notification;
        $this->channel = $channel;
    }
}

class NotificationFailed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The notification instance.
     *
     * @var Notification
     */
    public $notification;

    /**
     * The channel that failed.
     *
     * @var string
     */
    public $channel;

    /**
     * The error message.
     *
     * @var string
     */
    public $error;

    /**
     * Create a new event instance.
     *
     * @param Notification $notification
     * @param string $channel
     * @param string $error
     */
    public function __construct(Notification $notification, string $channel, string $error)
    {
        $this->notification = $notification;
        $this->channel = $channel;
        $this->error = $error;
    }
}

class NotificationScheduled
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The notification instance.
     *
     * @var Notification
     */
    public $notification;

    /**
     * The scheduled time.
     *
     * @var \DateTime
     */
    public $scheduledAt;

    /**
     * Create a new event instance.
     *
     * @param Notification $notification
     * @param \DateTime $scheduledAt
     */
    public function __construct(Notification $notification, \DateTime $scheduledAt)
    {
        $this->notification = $notification;
        $this->scheduledAt = $scheduledAt;
    }
}