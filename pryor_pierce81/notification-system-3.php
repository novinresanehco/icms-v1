<?php

namespace App\Core\Monitoring\Jobs\Notification;

class NotificationManager
{
    private ChannelRegistry $channelRegistry;
    private NotificationQueue $queue;
    private TemplateEngine $templateEngine;
    private PreferenceManager $preferences;
    private DeliveryTracker $tracker;

    public function notify(JobEvent $event, NotificationConfig $config): void
    {
        $notification = $this->createNotification($event, $config);
        $channels = $this->resolveChannels($notification, $config);

        foreach ($channels as $channel) {
            $this->queue->enqueue(new NotificationTask($notification, $channel));
        }
    }

    public function createNotification(JobEvent $event, NotificationConfig $config): Notification
    {
        $template = $this->templateEngine->getTemplate($event->getType());
        $content = $template->render([
            'event' => $event,
            'config' => $config,
            'metadata' => $this->getMetadata($event)
        ]);

        return new Notification([
            'id' => Uuid::generate(),
            'type' => $event->getType(),
            'content' => $content,
            'priority' => $config->getPriority(),
            'recipients' => $config->getRecipients(),
            'metadata' => $this->getMetadata($event)
        ]);
    }

    private function resolveChannels(Notification $notification, NotificationConfig $config): array
    {
        $channels = [];
        foreach ($config->getRecipients() as $recipient) {
            $preferences = $this->preferences->getPreferences($recipient);
            $recipientChannels = $this->getChannelsForRecipient($recipient, $preferences, $notification);
            $channels = array_merge($channels, $recipientChannels);
        }
        return array_unique($channels);
    }

    private function getChannelsForRecipient(string $recipient, array $preferences, Notification $notification): array
    {
        $channels = [];
        foreach ($preferences['channels'] as $channelType => $settings) {
            if ($this->shouldUseChannel($channelType, $settings, $notification)) {
                $channel = $this->channelRegistry->getChannel($channelType);
                if ($channel) {
                    $channels[] = $channel;
                }
            }
        }
        return $channels;
    }

    private function shouldUseChannel(string $channelType, array $settings, Notification $notification): bool
    {
        return $settings['enabled'] &&
               $notification->getPriority() >= ($settings['minimum_priority'] ?? 0) &&
               $this->isWithinTimeWindow($settings['time_window'] ?? null);
    }
}

class NotificationQueue
{
    private PriorityQueue $queue;
    private DeliveryManager $deliveryManager;
    private RetryManager $retryManager;

    public function enqueue(NotificationTask $task): void
    {
        $this->queue->insert($task, $this->calculatePriority($task));
    }

    public function process(): void
    {
        while (!$this->queue->isEmpty()) {
            $task = $this->queue->extract();
            
            try {
                $result = $this->deliveryManager->deliver($task);
                if (!$result->isSuccessful() && $this->retryManager->shouldRetry($task)) {
                    $this->scheduleRetry($task);
                }
            } catch (DeliveryException $e) {
                if ($this->retryManager->shouldRetry($task)) {
                    $this->scheduleRetry($task);
                }
            }
        }
    }

    private function calculatePriority(NotificationTask $task): int
    {
        $priority = $task->getNotification()->getPriority();
        $attempts = $task->getAttempts();
        
        // Decrease priority with each retry attempt
        return max(1, $priority - ($attempts * 10));
    }

    private function scheduleRetry(NotificationTask $task): void
    {
        $delay = $this->retryManager->getNextRetryDelay($task);
        $task->incrementAttempts();
        
        $this->queue->insertDelayed($task, $this->calculatePriority($task), $delay);
    }
}

class DeliveryManager
{
    private ChannelManager $channelManager;
    private RateLimiter $rateLimiter;
    private DeliveryLogger $logger;

    public function deliver(NotificationTask $task): DeliveryResult
    {
        if (!$this->rateLimiter->allowDelivery($task)) {
            throw new RateLimitExceededException("Rate limit exceeded for notification delivery");
        }

        $channel = $task->getChannel();
        $notification = $task->getNotification();

        try {
            $result = $channel->send($notification);
            $this->logger->logDelivery($task, $result);
            return $result;
        } catch (\Exception $e) {
            $this->logger->logError($task, $e);
            throw new DeliveryException("Failed to deliver notification: " . $e->getMessage(), 0, $e);
        }
    }
}

class RetryManager
{
    private array $retryDelays = [30, 60, 300, 900, 3600];
    private int $maxAttempts = 5;

    public function shouldRetry(NotificationTask $task): bool
    {
        return $task->getAttempts() < $this->maxAttempts &&
               $this->isRetryableError($task->getLastError());
    }

    public function getNextRetryDelay(NotificationTask $task): int
    {
        $attempt = $task->getAttempts();
        return $this->retryDelays[$attempt] ?? end($this->retryDelays);
    }

    private function isRetryableError(\Exception $error = null): bool
    {
        if (!$error) {
            return false;
        }

        return $error instanceof TemporaryFailureException ||
               $error instanceof NetworkException ||
               $error instanceof TimeoutException;
    }
}

class Notification
{
    private string $id;
    private string $type;
    private string $content;
    private int $priority;
    private array $recipients;
    private array $metadata;

    public function __construct(array $data)
    {
        $this->id = $data['id'];
        $this->type = $data['type'];
        $this->content = $data['content'];
        $this->priority = $data['priority'];
        $this->recipients = $data['recipients'];
        $this->metadata = $data['metadata'];
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getRecipients(): array
    {
        return $this->recipients;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}

class NotificationTask
{
    private Notification $notification;
    private NotificationChannel $channel;
    private int $attempts = 0;
    private ?\Exception $lastError = null;

    public function __construct(Notification $notification, NotificationChannel $channel)
    {
        $this->notification = $notification;
        $this->channel = $channel;
    }

    public function getNotification(): Notification
    {
        return $this->notification;
    }

    public function getChannel(): NotificationChannel
    {
        return $this->channel;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function incrementAttempts(): void
    {
        $this->attempts++;
    }

    public function setLastError(\Exception $error): void
    {
        $this->lastError = $error;
    }

    public function getLastError(): ?\Exception
    {
        return $this->lastError;
    }
}
