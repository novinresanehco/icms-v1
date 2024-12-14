<?php

namespace App\Core\Repositories;

use App\Models\Notification;
use App\Core\Services\Cache\CacheService;
use Illuminate\Support\Collection;

class NotificationRepository extends AdvancedRepository
{
    protected $model = Notification::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function getUnreadForUser(int $userId): Collection
    {
        return $this->executeQuery(function() use ($userId) {
            return $this->model
                ->where('notifiable_id', $userId)
                ->whereNull('read_at')
                ->orderBy('created_at', 'desc')
                ->get();
        });
    }

    public function markAsRead(Notification $notification): void
    {
        $this->executeTransaction(function() use ($notification) {
            $notification->update(['read_at' => now()]);
            $this->cache->forget("user.{$notification->notifiable_id}.notifications");
        });
    }

    public function markAllAsRead(int $userId): void
    {
        $this->executeTransaction(function() use ($userId) {
            $this->model
                ->where('notifiable_id', $userId)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
            
            $this->cache->forget("user.{$userId}.notifications");
        });
    }

    public function deleteOldNotifications(int $days = 30): int
    {
        return $this->executeTransaction(function() use ($days) {
            $deleted = $this->model
                ->where('created_at', '<=', now()->subDays($days))
                ->whereNotNull('read_at')
                ->delete();
                
            $this->cache->tags('notifications')->flush();
            return $deleted;
        });
    }
}
