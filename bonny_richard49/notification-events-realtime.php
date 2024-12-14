<?php

namespace App\Core\Notification\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;
use App\Core\Notification\Models\Notification;

class NewNotification implements ShouldBroadcast
{
    use SerializesModels;

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

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel("user.{$this->notification->notifiable_id}")
        ];

        // Add additional channels based on notification type
        if ($this->notification->isGroupNotification()) {
            $channels[] = new PrivateChannel("group.{$this->notification->group_id}");
        }

        if ($this->notification->isBroadcast()) {
            $channels[] = new Channel('broadcast');
        }

        return $channels;
    }

    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->notification->id,
            'type' => $this->notification->type,
            'data' => $this->notification->data,
            'created_at' => $this->notification->created_at->toIso8601String()
        ];
    }
}

class NotificationUpdated implements ShouldBroadcast
{
    use SerializesModels;

    public $notification;
    public $changes;

    /**
     * Create a new event instance.
     *
     * @param Notification $notification
     * @param array $changes
     */
    public function __construct(Notification $notification, array $changes = [])
    {
        $this->notification = $notification;
        $this->changes = $changes;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("user.{$this->notification->notifiable_id}")
        ];
    }

    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->notification->id,
            'changes' => $this->changes,
            'updated_at' => now()->toIso8601String()
        ];
    }
}

class UserPresenceUpdated implements ShouldBroadcast
{
    use SerializesModels;

    public $user;
    public $status;

    /**
     * Create a new event instance.
     *
     * @param \App\Models\User $user
     * @param string $status
     */
    public function __construct($user, string $status)
    {
        $this->user = $user;
        $this->status = $status;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array
     */
    public function broadcastOn(): array
    {
        return [
            new PresenceChannel('users')
        ];
    }

    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->user->id,
            'status' => $this->status,
            'timestamp' => now()->toIso8601String()
        ];
    }
}

class NotificationBroadcastEvent implements ShouldBroadcast
{
    use SerializesModels;

    protected $channels;
    protected $data;
    protected $socket;

    /**
     * Create a new event instance.
     *
     * @param array $channels
     * @param array $data
     * @param string|null $socket
     */
    public function __construct(array $channels, array $data, ?string $socket = null)
    {
        $this->channels = $channels;
        $this->data = $data;
        $this->socket = $socket;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array
     */
    public function broadcastOn(): array
    {
        return array_map(function ($channel) {
            if (str_starts_with($channel, 'private-')) {
                return new PrivateChannel(substr($channel, 8));
            } elseif (str_starts_with($channel, 'presence-')) {
                return new PresenceChannel(substr($channel, 9));
            }
            return new Channel($channel);
        }, $this->channels);
    }

    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith(): array
    {
        return array_merge($this->data, [
            'socket' => $this->socket
        ]);
    }
}