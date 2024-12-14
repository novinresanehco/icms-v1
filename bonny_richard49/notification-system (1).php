<?php

namespace App\Core\Notification\Contracts;

interface NotificationServiceInterface
{
    public function send(Notifiable $notifiable, Notification $notification): void;
    public function sendNow(Notifiable $notifiable, Notification $notification): void;
    public function sendLater(Notifiable $notifiable, Notification $notification, Carbon $delay): void;
    public function cancel(string $notificationId): bool;
}

interface NotificationChannelInterface
{
    public function send(Notifiable $notifiable, Notification $notification): void;
    public function supports(Notification $notification): bool;
}

namespace App\Core\Notification\Services;

class NotificationService implements NotificationServiceInterface
{
    protected NotificationManager $manager;
    protected ChannelManager $channels;
    protected QueueManager $queue;
    protected NotificationLogger $logger;

    public function __construct(
        NotificationManager $manager,
        ChannelManager $channels,
        QueueManager $queue,
        NotificationLogger $logger
    ) {
        $this->manager = $manager;
        $this->channels = $channels;
        $this->queue = $queue;
        $this->logger = $logger;
    }

    public function send(Notifiable $notifiable, Notification $notification): void
    {
        $this->queue->push(new SendNotificationJob($notifiable, $notification));
    }

    public function sendNow(Notifiable $notifiable, Notification $notification): void
    {
        try {
            // Get channels for notification
            $channels = $this->getChannels($notification);

            // Send through each channel
            foreach ($channels as $channel) {
                if ($channel->supports($notification)) {
                    $channel->send($notifiable, $notification);
                }
            }

            // Log success
            $this->logger->logSuccess($notification, $notifiable);

        } catch (\Exception $e) {
            // Log failure
            $this->logger->logFailure($notification, $notifiable, $e);
            throw $e;
        }
    }

    public function sendLater(Notifiable $notifiable, Notification $notification, Carbon $delay): void
    {
        $this->queue->later($delay, new SendNotificationJob($notifiable, $notification));
    }

    public function cancel(string $notificationId): bool
    {
        return $this->manager->cancelNotification($notificationId);
    }

    protected function getChannels(Notification $notification): array
    {
        return array_map(
            fn(string $channel) => $this->channels->driver($channel),
            $notification->via()
        );
    }
}

class ChannelManager
{
    protected array $channels = [];
    protected array $customChannels = [];
    protected Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function driver(string $name): NotificationChannelInterface
    {
        if (isset($this->channels[$name])) {
            return $this->channels[$name];
        }

        return $this->resolve($name);
    }

    public function extend(string $driver, Closure $callback): void
    {
        $this->customChannels[$driver] = $callback;
    }

    protected function resolve(string $name): NotificationChannelInterface
    {
        if (isset($this->customChannels[$name])) {
            return $this->resolveCustomChannel($name);
        }

        $method = 'create' . Str::studly($name) . 'Driver';
        if (method_exists($this, $method)) {
            return $this->$method();
        }

        throw new InvalidArgumentException("Driver [{$name}] not supported.");
    }

    protected function resolveCustomChannel(string $name): NotificationChannelInterface
    {
        return $this->customChannels[$name]($this->container);
    }

    protected function createMailDriver(): NotificationChannelInterface
    {
        return new MailChannel(
            $this->container->make(Mailer::class)
        );
    }

    protected function createDatabaseDriver(): NotificationChannelInterface
    {
        return new DatabaseChannel(
            $this->container->make(DatabaseNotificationRepository::class)
        );
    }
}

namespace App\Core\Notification\Channels;

class MailChannel implements NotificationChannelInterface
{
    protected Mailer $mailer;

    public function __construct(Mailer $mailer)
    {
        $this->mailer = $mailer;
    }

    public function send(Notifiable $notifiable, Notification $notification): void
    {
        $message = $notification->toMail($notifiable);

        if (!$message instanceof MailMessage) {
            throw new InvalidArgumentException('Mail notifications must return a MailMessage instance.');
        }

        $this->mailer->send(
            $message->view ?? 'notifications::email',
            $message->data(),
            function ($m) use ($message, $notifiable) {
                $this->buildMessage($m, $notifiable, $message);
            }
        );
    }

    public function supports(Notification $notification): bool
    {
        return method_exists($notification, 'toMail');
    }

    protected function buildMessage($mailMessage, Notifiable $notifiable, MailMessage $message): void
    {
        $mailMessage->subject($message->subject)
            ->to($notifiable->routeNotificationFor('mail'))
            ->from($message->from['address'] ?? config('mail.from.address'),
                  $message->from['name'] ?? config('mail.from.name'));

        if ($message->cc) {
            $mailMessage->cc($message->cc);
        }

        if ($message->bcc) {
            $mailMessage->bcc($message->bcc);
        }

        if ($message->replyTo) {
            $mailMessage->replyTo($message->replyTo);
        }

        foreach ($message->attachments as $attachment) {
            $mailMessage->attach($attachment['file'], $attachment['options']);
        }
    }
}

class DatabaseChannel implements NotificationChannelInterface
{
    protected DatabaseNotificationRepository $repository;

    public function __construct(DatabaseNotificationRepository $repository)
    {
        $this->repository = $repository;
    }

    public function send(Notifiable $notifiable, Notification $notification): void
    {
        $data = $notification->toDatabase($notifiable);

        if (!is_array($data)) {
            throw new InvalidArgumentException('Database notifications must return an array.');
        }

        $this->repository->create([
            'id' => Str::uuid(),
            'type' => get_class($notification),
            'notifiable_type' => get_class($notifiable),
            'notifiable_id' => $notifiable->getKey(),
            'data' => $data,
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    public function supports(Notification $notification): bool
    {
        return method_exists($notification, 'toDatabase');
    }
}

namespace App\Core\Notification\Models;

class DatabaseNotification extends Model
{
    protected $table = 'notifications';

    protected $guarded = [];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    public function markAsRead(): void
    {
        if (is_null($this->read_at)) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }

    public function markAsUnread(): void
    {
        if (!is_null($this->read_at)) {
            $this->forceFill(['read_at' => null])->save();
        }
    }

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }
}

trait Notifiable
{
    public function notifications(): MorphMany
    {
        return $this->morphMany(DatabaseNotification::class, 'notifiable')
            ->orderBy('created_at', 'desc');
    }

    public function unreadNotifications(): MorphMany
    {
        return $this->notifications()
            ->whereNull('read_at');
    }

    public function routeNotificationFor(string $driver, ?Notification $notification = null)
    {
        if (method_exists($this, $method = 'routeNotificationFor'.Str::studly($driver))) {
            return $this->{$method}($notification);
        }

        switch ($driver) {
            case 'database':
                return $this->notifications();
            case 'mail':
                return $this->email;
        }
    }
}
