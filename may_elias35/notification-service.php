<?php

namespace App\Core\Notification;

class NotificationService
{
    private NotificationRepository $repository;
    private array $channels;
    private NotificationLogger $logger;
    private NotificationMetrics $metrics;

    public function __construct(
        NotificationRepository $repository,
        array $channels,
        NotificationLogger $logger,
        NotificationMetrics $metrics
    ) {
        $this->repository = $repository;
        $this->channels = $channels;
        $this->logger = $logger;
        $this->metrics = $metrics;
    }

    public function send(Notification $notification): NotificationResult
    {
        $startTime = microtime(true);
        $results = [];

        try {
            foreach ($notification->getChannels() as $channelName) {
                if (!isset($this->channels[$channelName])) {
                    throw new ChannelNotFoundException($channelName);
                }

                $channel = $this->channels[$channelName];
                $results[$channelName] = $channel->send($notification);
            }

            $success = !in_array(false, $results, true);
            $this->logNotification($notification, $results, $success);
            $this->recordMetrics($notification, microtime(true) - $startTime, $success);

            return new NotificationResult($success, $results);
        } catch (\Exception $e) {
            $this->handleError($notification, $e);
            throw $e;
        }
    }

    public function schedule(Notification $notification, \DateTime $sendAt): void
    {
        $this->repository->schedule($notification, $sendAt);
        $this->logger->logScheduled($notification, $sendAt);
    }

    public function cancel(string $notificationId): void
    {
        $notification = $this->repository->find($notificationId);
        
        if ($notification) {
            $this->repository->cancel($notificationId);
            $this->logger->logCancelled($notification);
        }
    }

    protected function logNotification(
        Notification $notification,
        array $results,
        bool $success
    ): void {
        $this->logger->log([
            'notification_id' => $notification->getId(),
            'channels' => $notification->getChannels(),
            'results' => $results,
            'success' => $success,
            'timestamp' => time()
        ]);
    }

    protected function recordMetrics(
        Notification $notification,
        float $duration,
        bool $success
    ): void {
        $this->metrics->record([
            'type' => $notification->getType(),
            'channels' => $notification->getChannels(),
            'duration' => $duration,
            'success' => $success
        ]);
    }

    protected function handleError(Notification $notification, \Exception $e): void
    {
        $this->logger->logError($notification, $e);
        $this->metrics->recordError($notification->getType());
    }
}

interface NotificationChannel
{
    public function send(Notification $notification): bool;
    public function validateNotification(Notification $notification): bool;
}

class NotificationRepository
{
    private DatabaseConnection $db;

    public function schedule(Notification $notification, \DateTime $sendAt): void
    {
        $this->db->insert('scheduled_notifications', [
            'id' => $notification->getId(),
            'type' => $notification->getType(),
            'channels' => json_encode($notification->getChannels()),
            'payload' => json_encode($notification->getPayload()),
            'send_at' => $sendAt->format('Y-m-d H:i:s'),
            'status' => 'scheduled'
        ]);
    }

    public function cancel(string $notificationId): void
    {
        $this->db->update('scheduled_notifications', [
            'status' => 'cancelled'
        ], [
            'id' => $notificationId
        ]);
    }

    public function find(string $notificationId): ?Notification
    {
        $data = $this->db->fetchOne('scheduled_notifications', [
            'id' => $notificationId
        ]);

        return $data ? new Notification(
            $data['type'],
            json_decode($data['channels'], true),
            json_decode($data['payload'], true)
        ) : null;
    }
}

class NotificationLogger
{
    private LoggerInterface $logger;

    public function log(array $data): void
    {
        $this->logger->info('Notification sent', $data);
    }

    public function logError(Notification $notification, \Exception $e): void
    {
        $this->logger->error('Notification failed', [
            'notification_id' => $notification->getId(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    public function logScheduled(Notification $notification, \DateTime $sendAt): void
    {
        $this->logger->info('Notification scheduled', [
            'notification_id' => $notification->getId(),
            'send_at' => $sendAt->format('Y-m-d H:i:s')
        ]);
    }

    public function logCancelled(Notification $notification): void
    {
        $this->logger->info('Notification cancelled', [
            'notification_id' => $notification->getId()
        ]);
    }
}

class NotificationMetrics
{
    private MetricsCollector $collector;

    public function record(array $data): void
    {
        $this->collector->increment('notifications_sent', 1, [
            'type' => $data['type'],
            'channels' => implode(',', $data['channels']),
            'success' => $data['success']
        ]);

        $this->collector->gauge('notification_duration', $data['