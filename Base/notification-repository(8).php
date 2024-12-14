<?php

namespace App\Repositories;

use App\Models\Notification;
use App\Core\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

class NotificationRepository extends BaseRepository
{
    public function __construct(Notification $model)
    {
        $this->model = $model;
        parent::__construct();
    }

    public function findUnreadByUser(int $userId): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [$userId], function () use ($userId) {
            return $this->model->where('user_id', $userId)
                             ->whereNull('read_at')
                             ->orderBy('created_at', 'desc')
                             ->get();
        });
    }

    public function markAsRead(int $id): bool
    {
        $result = $this->update($id, ['read_at' => now()]);
        $this->clearCache();
        return $result;
    }

    public function markAllAsRead(int $userId): bool
    {
        $result = $this->model->where('user_id', $userId)
                             ->whereNull('read_at')
                             ->update(['read_at' => now()]);
        
        $this->clearCache();
        return (bool) $result;
    }

    public function createForUsers(array $userIds, array $data): void
    {
        foreach ($userIds as $userId) {
            $this->create(array_merge($data, ['user_id' => $userId]));
        }
        
        $this->clearCache();
    }

    public function deleteOldNotifications(int $days = 30): int
    {
        $count = $this->model->where('created_at', '<', now()->subDays($days))
                            ->whereNotNull('read_at')
                            ->delete();
        
        $this->clearCache();
        return $count;
    }
}
