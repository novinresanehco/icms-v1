<?php

namespace App\Core\Notifications\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $fillable = [
        'type',
        'notifiable_type',
        'notifiable_id',
        'data',
        'read_at',
        'status',
        'priority'
    ];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];
}

namespace App\Core\Notifications\Services;

class NotificationManager
{
    private NotificationSender $sender;
    private NotificationRepository $repository;
    private ChannelManager $channelManager;

    public function __construct(
        NotificationSender $sender,
        NotificationRepository $repository,
        ChannelManager $channelManager
    ) {
        $this->sender = $sender;
        $this->repository = $repository;
        $this->channelManager = $channelManager;
    }

    public function send($notifiable, string $type, array $data): void
    {
        $notification = $this->repository->create([
            'type' => $type,
            'notifiable_type' => get_class($notifiable),
            'notifiable_id' => $notifiable->id,
            'data' => $data
        ]);

        $this->sender->send($notification);
    }

    public function markAsRead(int $id): void
    {
        $this->repository->markAsRead($id);
    }

    public function markAllAsRead($notifiable): void
    {
        $this->repository->markAllAsRead($notifiable);
    }

    public function delete(int $id): void
    {
        $this->repository->delete($id);
    }
}

class NotificationSender
{
    private ChannelManager $channelManager;

    public function __construct(ChannelManager $channelManager)
    {
        $this->channelManager = $channelManager;
    }

    public function send(Notification $notification): void
    {
        $channels = $this->getChannelsForNotification($notification);

        foreach ($channels as $channel) {
            $this->channelManager->send($channel, $notification);
        }
    }

    private function getChannelsForNotification(Notification $notification): array
    {
        $notifiable = $this->getNotifiable($notification);
        return $notifiable->notificationChannels($notification);
    }

    private function getNotifiable(Notification $notification)
    {
        return $notification->notifiable_type::find($notification->notifiable_id);
    }
}

class ChannelManager
{
    private array $channels = [];

    public function register(string $name, NotificationChannel $channel): void
    {
        $this->channels[$name] = $channel;
    }

    public function send(string $channel, Notification $notification): void
    {
        if (!isset($this->channels[$channel])) {
            throw new \Exception("Channel {$channel} not found");
        }

        $this->channels[$channel]->send($notification);
    }
}

abstract class NotificationChannel
{
    abstract public function send(Notification $notification): void;
}

class EmailChannel extends NotificationChannel
{
    private Mailer $mailer;

    public function __construct(Mailer $mailer)
    {
        $this->mailer = $mailer;
    }

    public function send(Notification $notification): void
    {
        $notifiable = $this->getNotifiable($notification);
        $email = $this->buildEmail($notification);
        
        $this->mailer->send($notifiable->email, $email);
    }

    private function buildEmail(Notification $notification): array
    {
        return [
            'subject' => $notification->data['subject'] ?? 'New Notification',
            'body' => $notification->data['body'] ?? '',
            'template' => $notification->data['template'] ?? 'default'
        ];
    }
}

class DatabaseChannel extends NotificationChannel
{
    public function send(Notification $notification): void
    {
        // Notification is already stored in database
    }
}

class PushChannel extends NotificationChannel
{
    private PushService $pushService;

    public function __construct(PushService $pushService)
    {
        $this->pushService = $pushService;
    }

    public function send(Notification $notification): void
    {
        $notifiable = $this->getNotifiable($notification);
        $deviceTokens = $notifiable->pushTokens;

        foreach ($deviceTokens as $token) {
            $this->pushService->send($token, [
                'title' => $notification->data['title'] ?? 'New Notification',
                'body' => $notification->data['body'] ?? '',
                'data' => $notification->data
            ]);
        }
    }
}

namespace App\Core\Notifications\Events;

class NotificationSent
{
    public $notification;

    public function __construct(Notification $notification)
    {
        $this->notification = $notification;
    }
}

namespace App\Core\Notifications\Http\Controllers;

use App\Core\Notifications\Services\NotificationManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    private NotificationManager $notificationManager;

    public function __construct(NotificationManager $notificationManager)
    {
        $this->notificationManager = $notificationManager;
    }

    public function index(Request $request): JsonResponse
    {
        $notifications = Notification::where('notifiable_id', auth()->id())
            ->where('notifiable_type', get_class(auth()->user()))
            ->orderByDesc('created_at')
            ->paginate($request->per_page ?? 15);

        return response()->json($notifications);
    }

    public function markAsRead(int $id): JsonResponse
    {
        $this->notificationManager->markAsRead($id);
        return response()->json(['message' => 'Notification marked as read']);
    }

    public function markAllAsRead(): JsonResponse
    {
        $this->notificationManager->markAllAsRead(auth()->user());
        return response()->json(['message' => 'All notifications marked as read']);
    }

    public function delete(int $id): JsonResponse
    {
        $this->notificationManager->delete($id);
        return response()->json(['message' => 'Notification deleted']);
    }
}

namespace App\Core\Notifications\Traits;

trait Notifiable
{
    public function notifications()
    {
        return $this->morphMany(Notification::class, 'notifiable');
    }

    public function notificationChannels(Notification $notification): array
    {
        return ['database'];
    }

    public function routeNotificationFor(string $channel)
    {
        switch ($channel) {
            case 'mail':
                return $this->email;
            case 'push':
                return $this->device_tokens;
            default:
                return null;
        }
    }
}
