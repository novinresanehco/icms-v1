<?php

namespace App\Core\Notification;

class NotificationManager
{
    private array $channels = [];
    private NotificationRepository $repository;
    private array $templates = [];
    private PreferenceManager $preferenceManager;

    public function send(Notifiable $recipient, Notification $notification): void
    {
        $channels = $this->resolveChannels($recipient, $notification);
        
        foreach ($channels as $channel) {
            try {
                $channel->send($recipient, $notification);
                $this->repository->markAsSent($notification, $channel->getName());
            } catch (\Exception $e) {
                $this->repository->markAsFailed($notification, $channel->getName(), $e->getMessage());
                if (!$notification->shouldFailSilently()) {
                    throw $e;
                }
            }
        }
    }

    public function sendBatch(array $recipients, Notification $notification): void
    {
        foreach ($recipients as $recipient) {
            try {
                $this->send($recipient, $notification);
            } catch (\Exception $e) {
                if (!$notification->shouldFailSilently()) {
                    throw $e;
                }
            }
        }
    }

    public function scheduleNotification(Notifiable $recipient, Notification $notification, \DateTime $scheduledAt): void
    {
        $this->repository->schedule($recipient, $notification, $scheduledAt);
    }

    private function resolveChannels(Notifiable $recipient, Notification $notification): array
    {
        $preferences = $this->preferenceManager->getPreferences($recipient);
        $availableChannels = $notification->getChannels();
        
        return array_filter(
            $this->channels,
            fn($channel) => in_array($channel->getName(), $availableChannels) && 
                           $preferences->isChannelEnabled($channel->getName())
        );
    }

    public function registerChannel(string $name, NotificationChannel $channel): void
    {
        $this->channels[$name] = $channel;
    }

    public function registerTemplate(string $name, NotificationTemplate $template): void
    {
        $this->templates[$name] = $template;
    }
}

interface Notifiable
{
    public function routeNotificationFor(string $channel): string;
    public function getPreferredLocale(): string;
    public function getNotificationPreferences(): array;
}

abstract class Notification
{
    protected string $id;
    protected $data;
    protected bool $failSilently = false;
    protected array $channels = [];

    public function __construct($data = null)
    {
        $this->id = uniqid('notif_', true);
        $this->data = $data;
    }

    abstract public function getTitle(): string;
    abstract public function getMessage(): string;

    public function getData()
    {
        return $this->data;
    }

    public function getChannels(): array
    {
        return $this->channels;
    }

    public function shouldFailSilently(): bool
    {
        return $this->failSilently;
    }

    public function failSilently(): self
    {
        $this->failSilently = true;
        return $this;
    }
}

interface NotificationChannel
{
    public function getName(): string;
    public function send(Notifiable $recipient, Notification $notification): void;
}

class EmailChannel implements NotificationChannel
{
    private $mailer;
    private TemplateRenderer $renderer;

    public function getName(): string
    {
        return 'email';
    }

    public function send(Notifiable $recipient, Notification $notification): void
    {
        $address = $recipient->routeNotificationFor('email');
        $template = $this->renderer->render($notification->getTemplate(), [
            'title' => $notification->getTitle(),
            'message' => $notification->getMessage(),
            'data' => $notification->getData()
        ]);

        $this->mailer->send($address, $template);
    }
}

class SMSChannel implements NotificationChannel
{
    private $smsProvider;

    public function getName(): string
    {
        return 'sms';
    }

    public function send(Notifiable $recipient, Notification $notification): void
    {
        $number = $recipient->routeNotificationFor('sms');
        $this->smsProvider->sendMessage($number, $notification->getMessage());
    }
}

class PushChannel implements NotificationChannel
{
    private $pushService;

    public function getName(): string
    {
        return 'push';
    }

    public function send(Notifiable $recipient, Notification $notification): void
    {
        $token = $recipient->routeNotificationFor('push');
        $this->pushService->send($token, [
            'title' => $notification->getTitle(),
            'body' => $notification->getMessage(),
            'data' => $notification->getData()
        ]);
    }
}

class NotificationRepository
{
    private $connection;

    public function store(Notification $notification): void
    {
        $this->connection->table('notifications')->insert([
            'id' => $notification->getId(),
            'type' => get_class($notification),
            'data' => serialize($notification->getData()),
            'created_at' => now()
        ]);
    }

    public function markAsSent(Notification $notification, string $channel): void
    {
        $this->connection->table('notification_deliveries')->insert([
            'notification_id' => $notification->getId(),
            'channel' => $channel,
            'status' => 'sent',
            'sent_at' => now()
        ]);
    }

    public function markAsFailed(Notification $notification, string $channel, string $error): void
    {
        $this->connection->table('notification_deliveries')->insert([
            'notification_id' => $notification->getId(),
            'channel' => $channel,
            'status' => 'failed',
            'error' => $error,
            'failed_at' => now()
        ]);
    }

    public function schedule(Notifiable $recipient, Notification $notification, \DateTime $scheduledAt): void
    {
        $this->connection->table('scheduled_notifications')->insert([
            'notification_id' => $notification->getId(),
            'recipient_id' => $recipient->getId(),
            'scheduled_at' => $scheduledAt,
            'created_at' => now()
        ]);
    }

    public function getDueNotifications(): array
    {
        return $this->connection->table('scheduled_notifications')
            ->where('scheduled_at', '<=', now())
            ->where('processed_at', null)
            ->get();
    }
}

class PreferenceManager
{
    private $connection;

    public function getPreferences(Notifiable $recipient): NotificationPreferences
    {
        $preferences = $this->connection->table('notification_preferences')
            ->where('user_id', $recipient->getId())
            ->first();

        return new NotificationPreferences($preferences ? json_decode($preferences->settings, true) : []);
    }

    public function updatePreferences(Notifiable $recipient, array $settings): void
    {
        $this->connection->table('notification_preferences')
            ->updateOrInsert(
                ['user_id' => $recipient->getId()],
                ['settings' => json_encode($settings)]
            );
    }
}

class NotificationPreferences
{
    private array $settings;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    public function isChannelEnabled(string $channel): bool
    {
        return $this->settings[$channel]['enabled'] ?? true;
    }

    public function getChannelSettings(string $channel): array
    {
        return $this->settings[$channel] ?? [];
    }
}

class NotificationTemplate
{
    private string $name;
    private string $content;
    private array $variables;

    public function __construct(string $name, string $content, array $variables = [])
    {
        $this->name = $name;
        $this->content = $content;
        $this->variables = $variables;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getVariables(): array
    {
        return $this->variables;
    }
}

class TemplateRenderer
{
    public function render(NotificationTemplate $template, array $data): string
    {
        $content = $template->getContent();
        
        foreach ($data as $key => $value) {
            $content = str_replace("{{$key}}", $value, $content);
        }
        
        return $content;
    }
}
