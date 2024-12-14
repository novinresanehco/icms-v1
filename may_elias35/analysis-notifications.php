<?php

namespace App\Core\Audit\Notifications;

class NotificationManager
{
    private array $channels;
    private NotificationFormatter $formatter;
    private EventDispatcher $dispatcher;
    private LoggerInterface $logger;

    public function __construct(
        array $channels,
        NotificationFormatter $formatter,
        EventDispatcher $dispatcher,
        LoggerInterface $logger
    ) {
        $this->channels = $channels;
        $this->formatter = $formatter;
        $this->dispatcher = $dispatcher;
        $this->logger = $logger;
    }

    public function send(AbstractNotification $notification): void
    {
        $formattedNotification = $this->formatter->format($notification);
        
        foreach ($notification->getChannels() as $channelName) {
            if (!isset($this->channels[$channelName])) {
                continue;
            }

            try {
                $channel = $this->channels[$channelName];
                $channel->send($formattedNotification);
                
                $this->dispatcher->dispatch(
                    new NotificationSentEvent($notification, $channelName)
                );
            } catch (\Throwable $e) {
                $this->handleSendError($notification, $channelName, $e);
            }
        }
    }

    public function sendAsync(AbstractNotification $notification): void
    {
        $this->dispatcher->dispatch(
            new NotificationQueuedEvent($notification)
        );
    }

    public function broadcast(AbstractNotification $notification): void
    {
        foreach ($this->channels as $channelName => $channel) {
            try {
                $formattedNotification = $this->formatter->format($notification);
                $channel->send($formattedNotification);
                
                $this->dispatcher->dispatch(
                    new NotificationBroadcastEvent($notification, $channelName)
                );
            } catch (\Throwable $e) {
                $this->handleSendError($notification, $channelName, $e);
            }
        }
    }

    private function handleSendError(
        AbstractNotification $notification,
        string $channelName,
        \Throwable $e
    ): void {
        $this->logger->error('Failed to send notification', [
            'channel' => $channelName,
            'notification' => $notification->toArray(),
            'error' => $e->getMessage()
        ]);

        $this->dispatcher->dispatch(
            new NotificationFailedEvent($notification, $channelName, $e)
        );
    }
}

abstract class AbstractNotification
{
    protected array $data;
    protected array $metadata;
    protected array $channels;

    public function __construct(array $data, array $metadata = [], array $channels = [])
    {
        $this->data = $data;
        $this->metadata = $metadata;
        $this->channels = $channels;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getChannels(): array
    {
        return $this->channels;
    }

    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'metadata' => $this->metadata,
            'channels' => $this->channels
        ];
    }

    abstract public function getType(): string;
}

class AnalysisCompletedNotification extends AbstractNotification
{
    public function getType(): string
    {
        return 'analysis_completed';
    }
}

class AnalysisFailedNotification extends AbstractNotification
{
    public function getType(): string
    {
        return 'analysis_failed';
    }
}

class AnomalyDetectedNotification extends AbstractNotification
{
    public function getType(): string
    {
        return 'anomaly_detected';
    }
}

class PatternDetectedNotification extends AbstractNotification
{
    public function getType(): string
    {
        return 'pattern_detected';
    }
}

class NotificationFormatter
{
    private array $formatters;
    
    public function __construct(array $formatters)
    {
        $this->formatters = $formatters;
    }

    public function format(AbstractNotification $notification): FormattedNotification
    {
        $type = $notification->getType();
        
        if (!isset($this->formatters[$type])) {
            throw new \InvalidArgumentException("No formatter found for type: {$type}");
        }

        $formatter = $this->formatters[$type];
        return $formatter->format($notification);
    }
}

interface NotificationChannel
{
    public function send(FormattedNotification $notification): void;
    public function supports(string $notificationType): bool;
}

class EmailChannel implements NotificationChannel
{
    private EmailService $emailService;
    private array $config;

    public function __construct(EmailService $emailService, array $config)
    {
        $this->emailService = $emailService;
        $this->config = $config;
    }

    public function send(FormattedNotification $notification): void
    {
        $this->emailService->send([
            'to' => $this->getRecipients($notification),
            'subject' => $notification->getSubject(),
            'body' => $notification->getBody(),
            'attachments' => $notification->getAttachments()
        ]);
    }

    public function supports(string $notificationType): bool
    {
        return in_array($notificationType, $this->config['supported_types'] ?? []);
    }

    private function getRecipients(FormattedNotification $notification): array
    {
        return array_merge(
            $this->config['default_recipients'] ?? [],
            $notification->getMetadata()['recipients'] ?? []
        );
    }
}

class SlackChannel implements NotificationChannel
{
    private SlackClient $client;
    private array $config;

    public function __construct(SlackClient $client, array $config)
    {
        $this->client = $client;
        $this->config = $config;
    }

    public function send(FormattedNotification $notification): void
    {
        $this->client->sendMessage([
            'channel' => $this->getChannel($notification),
            'text' => $notification->getBody(),
            'attachments' => $this->formatAttachments($notification)
        ]);
    }

    public function supports(string $notificationType): bool
    {
        return in_array($notificationType, $this->config['supported_types'] ?? []);
    }

    private function getChannel(FormattedNotification $notification): string
    {
        return $notification->getMetadata()['slack_channel'] 
            ?? $this->config['default_channel'];
    }

    private function formatAttachments(FormattedNotification $notification): array
    {
        $attachments = $notification->getAttachments();
        
        return array_map(function ($attachment) {
            return [
                'color' => $this->getAttachmentColor($attachment),
                'fields' => $this->formatFields($attachment),
                'footer' => $attachment['footer'] ?? null
            ];
        }, $attachments);
    }
}

class WebhookChannel implements NotificationChannel
{
    private HttpClient $client;
    private array $config;

    public function __construct(HttpClient $client, array $config)
    {
        $this->client = $client;
        $this->config = $config;
    }

    public function send(FormattedNotification $notification): void
    {
        $endpoints = $this->getEndpoints($notification);

        foreach ($endpoints as $endpoint) {
            $this->client->post($endpoint, [
                'json' => $this->formatPayload($notification)
            ]);
        }
    }

    public function supports(string $notificationType): bool
    {
        return in_array($notificationType, $this->config['supported_types'] ?? []);
    }

    private function getEndpoints(FormattedNotification $notification): array
    {
        return array_merge(
            $this->config['default_endpoints'] ?? [],
            $notification->getMetadata()['endpoints'] ?? []
        );
    }

    private function formatPayload(FormattedNotification $notification): array
    {
        return [
            'type' => $notification->getType(),
            'data' => $notification->getData(),
            'metadata' => $notification->getMetadata(),
            'timestamp' => time()
        ];
    }
}
