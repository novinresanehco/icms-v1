<?php

namespace App\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Repositories\Contracts\NotificationRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class NotificationRepository implements NotificationRepositoryInterface
{
    protected string $table = 'notifications';

    public function createNotification(array $data): bool
    {
        try {
            DB::table($this->table)->insert(array_merge($data, [
                'id' => \Str::uuid()->toString(),
                'created_at' => now(),
                'updated_at' => now()
            ]));
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function markAsRead(string $id): bool
    {
        return DB::table($this->table)
            ->where('id', $id)
            ->update([
                'read_at' => now(),
                'updated_at' => now()
            ]) > 0;
    }

    public function markAllAsRead(int $userId): bool
    {
        return DB::table($this->table)
            ->where('notifiable_id', $userId)
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
                'updated_at' => now()
            ]) > 0;
    }

    public function deleteNotification(string $id): bool
    {
        return DB::table($this->table)
            ->where('id', $id)
            ->delete() > 0;
    }

    public function getUserUnreadNotifications(int $userId): Collection
    {
        return collect(DB::table($this->table)
            ->where('notifiable_id', $userId)
            ->whereNull('read_at')
            ->orderBy('created_at', 'desc')
            ->get());
    }

    public function getUserNotifications(int $userId): LengthAwarePaginator
    {
        return DB::table($this->table)
            ->where('notifiable_id', $userId)
            ->orderBy('created_at', 'desc')
            ->paginate(config('cms.notifications.per_page', 20));
    }

    public function deleteOlderThan(int $days): bool
    {
        return DB::table($this->table)
            ->where('created_at', '<', Carbon::now()->subDays($days))
            ->delete() > 0;
    }

    public function getUnreadCount(int $userId): int
    {
        return DB::table($this->table)
            ->where('notifiable_id', $userId)
            ->whereNull('read_at')
            ->count();
    }

    public function getNotificationsByType(int $userId, string $type): Collection
    {
        return collect(DB::table($this->table)
            ->where('notifiable_id', $userId)
            ->where('type', $type)
            ->orderBy('created_at', 'desc')
            ->get());
    }

    public function getRecentNotifications(int $userId, int $limit = 5): Collection
    {
        return collect(DB::table($this->table)
            ->where('notifiable_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get());
    }
}
