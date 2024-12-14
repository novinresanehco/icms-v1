<?php

namespace App\Core\Repositories;

use App\Models\Notification;
use Illuminate\Support\Collection;

class NotificationRepository extends AdvancedRepository
{
    protected $model = Notification::class;

    public function createNotification(array $data): Notification
    {
        return $this->executeTransaction(function() use ($data) {
            return $this->create([
                'user_id' => $data['user_id'],
                'type' => $data['type'],
                'subject' => $data['subject'],
                'content' => $data['content'],
                'data' => $data['data'] ?? [],
                'read_at' => null,
                'created_at' => now()
            ]);
        });
    }

    public function markAsRead(int $id): void
    {
        $this->executeTransaction(function() use ($id) {
            $this->model->where('id', $id)->update([
                'read_at' => now()
            ]);
        });
    }

    public function getUserNotifications(int $userId, bool $unreadOnly = false): Collection
    {
        return $this->executeQuery(function() use ($userId, $unreadOnly) {
            $query = $this->model->where('user_id', $userId);

            if ($unreadOnly) {
                $query->whereNull('read_at');
            }

            return $query->orderBy('created_at', 'desc')->get();
        });
    }

    public function deleteOldNotifications(int $days = 30): int
    {
        return $this->executeTransaction(function() use ($days) {
            return $this->model
                ->where('created_at', '<=', now()->subDays($days))
                ->whereNotNull('read_at')
                ->delete();
        });
    }

    public function getBroadcastNotifications(): Collection
    {
        return $this->executeQuery(function() {
            return $this->model
                ->whereNull('user_id')
                ->where('created_at', '>=', now()->subDays(7))
                ->orderBy('created_at', 'desc')
                ->get();
        });
    }

    public function getUnreadCount(int $userId): int
    {
        return $this->executeWithCache(__METHOD__, function() use ($userId) {
            return $this->model
                ->where('user_id', $userId)
                ->whereNull('read_at')
                ->count();
        }, $userId);
    }
}
