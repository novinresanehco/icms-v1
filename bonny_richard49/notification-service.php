// app/Core/Notification/Services/NotificationService.php
<?php

namespace App\Core\Notification\Services;

use App\Core\Notification\Contracts\{
    NotificationServiceInterface,
    NotifiableInterface,
    NotificationInterface
};
use App\Core\Notification\Repositories\NotificationRepository;
use App\Core\Notification\Channels\ChannelManager;
use App\Core\Cache\CacheManager;
use App\Core\Queue\QueueManager;
use Illuminate\Support\Facades\Log;

class NotificationService implements NotificationServiceInterface
{
    private NotificationRepository $repository;
    private ChannelManager $channelManager;
    private CacheManager $cache;
    private QueueManager $queue;

    public function __construct(
        NotificationRepository $repository,
        ChannelManager $channelManager, 
        CacheManager $cache,
        QueueManager $queue
    ) {
        $this->repository = $repository;
        $this->channelManager = $channelManager;
        $this->cache = $cache;
        $this->queue = $queue;
    }

    public function send(NotifiableInterface $notifiable, NotificationInterface $notification): void
    {
        try {
            // Begin database transaction
            DB::beginTransaction();

            // Save notification record
            $record = $this->repository->create([
                'id' => $notification->getId(),
                'type' => $notification->getType(),
                'notifiable_type' => get_class($notifiable),
                'notifiable_id' => $notifiable->id,
                'data' => $this->serializeNotification($notification),
                'status' => 'pending'
            ]);

            // Send to each channel
            foreach ($notification->via() as $channel) {
                $this->queue->push(new SendNotificationJob(
                    $record->id,
                    $channel,
                    $notifiable->routeNotificationFor($channel),
                    $notification->toChannel($channel)
                ));
            }

            // Commit transaction
            DB::commit();

            // Clear notification cache
            $this->clearNotificationCache($notifiable);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to send notification', [
                'notification_id' => $notification->getId(),
                'error' => $e->getMessage()
            ]);
            throw new NotificationException('Failed to send notification', 0, $e);
        }
    }

    public function schedule(
        NotifiableInterface $notifiable, 
        NotificationInterface $notification,
        \DateTime $scheduledTime
    ): void {
        try {
            // Validate scheduled time
            if ($scheduledTime <= new \DateTime()) {
                throw new \InvalidArgumentException('Scheduled time must be in the future');
            }

            // Create scheduled notification record
            $record = $this->repository->create([
                'id' => $notification->getId(),
                'type' => $notification->getType(),
                'notifiable_type' => get_class($notifiable),
                'notifiable_id' => $notifiable->id,
                'data' => $this->serializeNotification($notification),
                'status' => 'scheduled',
                'scheduled_at' => $scheduledTime
            ]);

            // Schedule job
            $this->queue->later(
                $scheduledTime,
                new ProcessScheduledNotificationJob($record->id)
            );

        } catch (\Exception $e) {
            Log::error('Failed to schedule notification', [
                'notification_id' => $notification->getId(),
                'scheduled_time' => $scheduledTime->format('Y-m-d H:i:s'),
                'error' => $e->getMessage()
            ]);
            throw new NotificationException('Failed to schedule notification', 0, $e);
        }
    }

    public function cancelScheduled(string $notificationId): bool
    {
        try {
            $notification = $this->repository->find($notificationId);
            
            if (!$notification || $notification->status !== 'scheduled') {
                return false;
            }

            $this->repository->update($notificationId, [
                'status' => 'cancelled'
            ]);

            // Remove from queue
            $this->queue->remove(
                ProcessScheduledNotificationJob::class,
                ['notification_id' => $notificationId]
            );

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to cancel scheduled notification', [
                'notification_id' => $notificationId,
                'error' => $e->getMessage()
            ]);
            throw new NotificationException('Failed to cancel notification', 0, $e);
        }
    }

    public function getHistory(NotifiableInterface $notifiable, array $filters = []): array
    {
        $cacheKey = $this->getHistoryCacheKey($notifiable, $filters);

        return $this->cache->remember($cacheKey, 3600, function() use ($notifiable, $filters) {
            return $this->repository->getHistory($notifiable, $filters);
        });
    }

    protected function serializeNotification(NotificationInterface $notification): string
    {
        return json_encode([
            'id' => $notification->getId(),
            'type' => $notification->getType(),
            'channels' => $notification->via(),
            'data' => array_map(
                fn($channel) => $notification->toChannel($channel),
                $notification->via()
            )
        ]);
    }

    protected function clearNotificationCache(NotifiableInterface $notifiable): void
    {
        $this->cache->tags(['notifications', "user:{$notifiable->id}"])->flush();
    }

    protected function getHistoryCacheKey(NotifiableInterface $notifiable, array $filters): string
    {
        return sprintf(
            'notifications.history.%s.%s.%s',
            get_class($notifiable),
            $notifiable->id,
            md5(json_encode($filters))
        );
    }
}