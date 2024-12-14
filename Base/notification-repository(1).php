<?php

namespace App\Repositories;

use App\Models\Notification;
use App\Repositories\Contracts\NotificationRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationRepository extends BaseRepository implements NotificationRepositoryInterface
{
    protected array $searchableFields = ['title', 'content', 'type'];
    protected array $filterableFields = ['status', 'priority', 'user_id'];

    public function getUserNotifications(int $userId, bool $unreadOnly = false): LengthAwarePaginator
    {
        $query = $this->model
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc');

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        return $query->paginate(15);
    }

    public function markAsRead(int $id): bool
    {
        try {
            return $this->update($id, [
                'read_at' => now(),
                'status' => 'read'
            ]);
        } catch (\Exception $e) {
            \Log::error('Error marking notification as read: ' . $e->getMessage());
            return false;
        }
    }

    public function markAllAsRead(int $userId): int
    {
        try {
            return $this->model
                ->where('user_id', $userId)
                ->whereNull('read_at')
                ->update([
                    'read_at' => now(),
                    'status' => 'read'
                ]);
        } catch (\Exception $e) {
            \Log::error('Error marking all notifications as read: ' . $e->getMessage());
            return 0;
        }
    }

    public function cleanOldNotifications(int $days = 30): int
    {
        return $this->model
            ->where('created_at', '<', now()->subDays($days))
            ->whereNotNull('read_at')
            ->delete();
    }
}
