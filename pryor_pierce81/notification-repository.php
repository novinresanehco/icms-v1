<?php

namespace App\Core\Repository;

use App\Models\Notification;
use App\Core\Events\NotificationEvents;
use App\Core\Exceptions\NotificationRepositoryException;

class NotificationRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Notification::class;
    }

    /**
     * Create notification for users
     */
    public function createNotification(array $data, array $userIds): Collection
    {
        try {
            $notifications = collect();

            DB::transaction(function() use ($data, $userIds, &$notifications) {
                foreach ($userIds as $userId) {
                    $notification = $this->create([
                        'user_id' => $userId,
                        'type' => $data['type'],
                        'subject' => $data['subject'],
                        'content' => $data['content'],
                        'data' => $data['data'] ?? [],
                        'priority' => $data['priority'] ?? 'normal',
                        'status' => 'unread'
                    ]);

                    $notifications->push($notification);
                }
            });

            event(new NotificationEvents\NotificationsCreated($notifications));
            return $notifications;

        } catch (\Exception $e) {
            throw new NotificationRepositoryException(
                "Failed to create notifications: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get user notifications
     */
    public function getUserNotifications(int $userId, array $options = []): Collection
    {
        $query = $this->model->where('user_id', $userId);

        if (isset($options['status'])) {
            $query->where('status', $options['status']);
        }

        if (isset($options['type'])) {
            $query->where('type', $options['type']);
        }

        if (isset($options['priority'])) {
            $query->where('priority', $options['priority']);
        }

        return $query->latest()->get();
    }

    /**
     * Mark notifications as read
     */
    public function markAsRead(array $notificationIds): void
    {
        try {
            $this->model->whereIn('id', $notificationIds)
                       ->update(['status' => 'read', 'read_at' => now()]);

            $this->clearCache();
            event(new NotificationEvents\NotificationsMarkedAsRead($notificationIds));
        } catch (\Exception $e) {
            throw new NotificationRepositoryException(
                "Failed to mark notifications as read: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get unread count
     */
    public function getUnreadCount(int $userId): int
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("unread.{$userId}"),
            300, // 5 minutes cache
            fn() => $this->model->where('user_id', $userId)
                               ->where('status', 'unread')
                               ->count()
        );
    }

    /**
     * Clean old notifications
     */
    public function cleanOldNotifications(int $days = 30): void
    {
        try {
            $this->model->where('created_at', '<', now()->subDays($days))
                       ->where('status', 'read')
                       ->delete();
            
            $this->clearCache();
        } catch (\Exception $e) {
            throw new NotificationRepositoryException(
                "Failed to clean old notifications: {$e->getMessage()}"
            );
        }
    }
}
