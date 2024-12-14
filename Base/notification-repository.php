<?php

namespace App\Repositories;

use App\Models\Notification;
use App\Repositories\Contracts\NotificationRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class NotificationRepository extends BaseRepository implements NotificationRepositoryInterface
{
    protected array $searchableFields = ['title', 'content'];
    protected array $filterableFields = ['type', 'status', 'priority'];

    public function getUserNotifications(int $userId, bool $unreadOnly = false): Collection
    {
        $cacheKey = "notifications.user.{$userId}" . ($unreadOnly ? '.unread' : '');

        return Cache::tags(['notifications'])->remember($cacheKey, 300, function() use ($userId, $unreadOnly) {
            $query = $this->model->where('user_id', $userId);

            if ($unreadOnly) {
                $query->whereNull('read_at');
            }

            return $query->orderByDesc('created_at')->get();
        });
    }

    public function markAsRead(int $notificationId): bool
    {
        try {
            $notification = $this->find($notificationId);
            
            if (!$notification->read_at) {
                $notification->update(['read_at' => now()]);
                Cache::tags(['notifications'])->flush();
            }
            
            return true;
        } catch (\Exception $e) {
            \Log::error('Error marking notification as read: ' . $e->getMessage());
            return false;
        }
    }

    public function markAllAsRead(int $userId): bool
    {
        try {
            $this->model
                ->where('user_id', $userId)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            Cache::tags(['notifications'])->flush();
            
            return true;
        } catch (\Exception $e) {
            \Log::error('Error marking all notifications as read: ' . $e->getMessage());
            return false;
        }
    }

    public function sendNotification(array $data): Notification
    {
        $notification = $this->create(array_merge($data, [
            'status' => 'pending',
            'sent_at' => null
        ]));

        // Process notification queue
        dispatch(new \App\Jobs\ProcessNotification($notification));

        Cache::tags(['notifications'])->flush();

        return $notification;
    }

    public function sendBulkNotifications(array $data, array $userIds): bool
    {
        try {
            $notifications = [];
            foreach ($userIds as $userId) {
                $notifications[] = array_merge($data, [
                    'user_id' => $userId,
                    'status' => 'pending',
                    'sent_at' => null,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            $this->model->insert($notifications);

            // Process notification queue in chunks
            foreach (array_chunk($notifications, 100) as $chunk) {
                dispatch(new \App\Jobs\ProcessBulkNotifications($chunk));
            }

            Cache::tags(['notifications'])->flush();

            return true;
        } catch (\Exception $e) {
            \Log::error('Error sending bulk notifications: ' . $e->getMessage());
            return false;
        }
    }

    public function getPendingNotifications(): Collection
    {
        return $this->model
            ->where('status', 'pending')
            ->whereNull('sent_at')
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'asc')
            ->get();
    }

    public function updateStatus(int $id, string $status, ?string $error = null): bool
    {
        try {
            $this->update($id, [
                'status' => $status,
                'sent_at' => $status === 'sent' ? now() : null,
                'error' => $error,
                'attempts' => $this->model->find($id)->attempts + 1
            ]);

            Cache::tags(['notifications'])->flush();
            
            return true;
        } catch (\Exception $e) {
            \Log::error('Error updating notification status: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteOldNotifications(int $days = 30): int
    {
        $count = $this->model
            ->where('created_at', '<', now()->subDays($days))
            ->where('status', '!=', 'pending')
            ->delete();

        if ($count > 0) {
            Cache::tags(['notifications'])->flush();
        }

        return $count;
    }

    public function getUnreadCount(int $userId): int
    {
        $cacheKey = "notifications.unread_count.{$userId}";

        return Cache::tags(['notifications'])->remember($cacheKey, 300, function() use ($userId) {
            return $this->model
                ->where('user_id', $userId)
                ->whereNull('read_at')
                ->count();
        });
    }
}
