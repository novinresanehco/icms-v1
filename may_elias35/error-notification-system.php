// File: app/Core/Error/Notification/ErrorNotifier.php
<?php

namespace App\Core\Error\Notification;

class ErrorNotifier
{
    protected array $channels = [];
    protected NotificationFormatter $formatter;
    protected NotificationConfig $config;

    public function notify(\Throwable $exception): void
    {
        $notification = $this->formatter->format($exception);
        
        foreach ($this->channels as $channel) {
            if ($this->shouldNotifyChannel($channel, $exception)) {
                $channel->send($notification);
            }
        }
    }

    protected function shouldNotifyChannel(NotificationChannel $channel, \Throwable $exception): bool
    {
        return $exception->getSeverity() >= $channel->getMinimumSeverity();
    }

    public function addChannel(NotificationChannel $channel): void
    {
        $this->channels[] = $channel;
    }
}

// File: app/Core/Error/Notification/Channels/EmailChannel.php
<?php

namespace App\Core\Error\Notification\Channels;

class EmailChannel implements NotificationChannel
{
    protected Mailer $mailer;
    protected EmailConfig $config;

    public function send(ErrorNotification $notification): void
    {
        $this->mailer->send(
            $this->config->getRecipients(),
            $notification->getSubject(),
            $notification->getBody()
        );
    }

    public function getMinimumSeverity(): int
    {
        return $this->config->getMinimumSeverity();
    }
}
