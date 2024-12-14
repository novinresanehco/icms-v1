<?php

namespace App\Core\Notification\Realtime;

use App\Core\Notification\Events\{
    NotificationCreated,
    NotificationUpdated,
    NotificationDeleted
};
use Illuminate\Support\Facades\Log;

class NotificationWebSocketHandler
{
    /**
     * Handle real-time notification events
     *
     * @param mixed $connection
     * @param array $message
     * @return void
     */
    public function handle($connection, array $message): void
    {
        try {
            switch ($message['type'] ?? '') {
                case 'subscribe':
                    $this->handleSubscribe($connection, $message);
                    break;

                case 'read':
                    $this->handleMarkAsRead($connection, $message);
                    break;

                case 'presence':
                    $this->handlePresence($connection, $message);
                    break;

                default:
                    Log::warning('Unknown message type', ['message' => $message]);
            }
        } catch (\Exception $e) {
            Log::error('WebSocket handler error', [
                'error' => $e->getMessage(),
                'message' => $message
            ]);
            
            $this->sendError($connection, $e->getMessage());
        }
    }

    /**
     * Handle subscription requests
     *
     * @param mixed $connection
     * @param array $message
     * @return void
     */
    protected function handleSubscribe($connection, array $message): void
    {
        $user = $this->authenticateConnection($connection);
        
        $channels = $message['channels'] ?? [];
        foreach ($channels as $channel) {
            if ($this->canSubscribe($user, $channel)) {
                $connection->subscribe($channel);
                $this->sendSubscriptionConfirmed($connection, $channel);
            }
        }
    }

    /**
     * Handle mark as read requests
     *
     * @param mixed $connection
     * @param array $message
     * @return void
     */
    protected function handleMarkAsRead($connection, array $message): void
    {
        $user = $this->authenticateConnection($connection);
        $notificationId = $message['notification_id'] ?? null;

        if ($notificationId) {
            event(new NotificationUpdated($notificationId, [
                'read_at' => now(),
                'user_id' => $user->id
            ]));
        }
    }

    /**
     * Handle presence updates
     *
     * @param mixed $connection
     * @param array $message
     * @return void
     */
    protected function handlePresence($connection, array $message): void
    {
        $user = $this->authenticateConnection($connection);
        $status = $message['status'] ?? 'online';

        $this->updateUserPresence($user, $status);
        $this->broadcastPresenceUpdate($user, $status);
    }

    /**
     * Authenticate WebSocket connection
     *
     * @param mixed $connection
     * @return \App\Models\User
     * @throws \Exception
     */
    protected function authenticateConnection($connection)
    {
        $token = $connection->token ?? null;
        if (!$token) {
            throw new \Exception('Authentication required');
        }

        $user = auth()->guard('sanctum')->user();
        if (!$user) {
            throw new \Exception('Invalid authentication token');
        }

        return $user;
    }

    /**
     * Check if user can subscribe to channel
     *
     * @param \App\Models\User $user
     * @param string $channel
     * @return bool
     */
    protected function canSubscribe($user, string $channel): bool
    {
        // Implement channel authorization logic
        if (str_starts_with($channel, 'private-')) {
            return $user->can('subscribe-to-channel', $channel);
        }

        return true;
    }

    /**
     * Send subscription confirmation
     *
     * @param mixed $connection
     * @param string $channel
     * @return void
     */
    protected function sendSubscriptionConfirmed($connection, string $channel): void
    {
        $connection->send(json_encode([
            'type' => 'subscription_confirmed',
            'channel' => $channel
        ]));
    }

    /**
     * Send error message
     *
     * @param mixed $connection
     * @param string $error
     * @return void
     */
    protected function sendError($connection, string $error): void
    {
        $connection->send(json_encode([
            'type' => 'error',
            'message' => $error
        ]));
    }

    /**
     * Update user presence status
     *
     * @param \App\Models\User $user
     * @param string $status
     * @return void
     */
    protected function updateUserPresence($user, string $status): void
    {
        cache()->put(
            "user_presence:{$user->id}", 
            $status, 
            now()->addMinutes(5)
        );
    }

    /**
     * Broadcast presence update
     *
     * @param \App\Models\User $user
     * @param string $status
     * @return void
     */
    protected function broadcastPresence($user, string $status): void
    {
        broadcast(new UserPresenceUpdated($user, $status))->toPresence('users');
    }
}

class NotificationBroadcaster
{
    /**
     * Broadcast a new notification
     *
     * @param NotificationCreated $event
     * @return void
     */
    public function broadcastNew(NotificationCreated $event): void
    {
        try {
            $notification = $event->notification;
            $channels = $this->getChannelsForNotification($notification);

            foreach ($channels as $channel) {
                broadcast(new NewNotification($notification))->toChannel($channel);
            }
        } catch (\Exception $e) {
            Log::error('Failed to broadcast notification', [
                'notification_id' => $notification->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Broadcast notification update
     *
     * @param NotificationUpdated $event
     * @return void
     */
    public function broadcastUpdate(NotificationUpdated $event): void
    {
        try {
            $notification = $event->notification;
            $channels = $this->getChannelsForNotification($notification);

            foreach ($channels as $channel) {
                broadcast(new NotificationUpdated($notification))->toChannel($channel);
            }
        } catch (\Exception $e) {
            Log::error('Failed to broadcast notification update', [
                'notification_id' => $notification->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get broadcast channels for notification
     *
     * @param \App\Core\Notification\Models\Notification $notification
     * @return array
     */
    protected function getChannelsForNotification($notification): array
    {
        $channels = [
            "private-user.{$notification->notifiable_id}"
        ];

        // Add any additional channels based on notification type/metadata
        if ($notification->isGroupNotification()) {
            $channels[] = "private-group.{$notification->group_id}";
        }

        if ($notification->isBroadcast()) {
            $channels[] = 'broadcast';
        }

        return $channels;
    }
}

class NotificationWebSocketServer
{
    protected $server;
    protected $handler;
    protected $clients = [];

    /**
     * Create a new WebSocket server instance
     *
     * @param NotificationWebSocketHandler $handler
     */
    public function __construct(NotificationWebSocketHandler $handler)
    {
        $this->handler = $handler;
        $this->initializeServer();
    }

    /**
     * Initialize WebSocket server
     *
     * @return void
     */
    protected function initializeServer(): void
    {
        $this->server = new \Ratchet\Server\IoServer(
            new \Ratchet\Http\HttpServer(
                new \Ratchet\WebSocket\WsServer(
                    new \Ratchet\Wamp\WampServer(
                        $this->handler
                    )
                )
            ),
            config('websockets.port', 6001),
            config('websockets.host', '0.0.0.0')
        );
    }

    /**
     * Start the WebSocket server
     *
     * @return void
     */
    public function start(): void
    {
        $this->server->run();
    }

    /**
     * Stop the WebSocket server
     *
     * @return void
     */
    public function stop(): void
    {
        $this->server->socket->close();
    }

    /**
     * Broadcast message to all clients
     *
     * @param string $channel
     * @param array $message
     * @return void
     */
    public function broadcast(string $channel, array $message): void
    {
        foreach ($this->clients as $client) {
            if ($client->isSubscribed($channel)) {
                $client->send(json_encode($message));
            }
        }
    }
}