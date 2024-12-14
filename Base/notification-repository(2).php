<?php

namespace App\Repositories;

use App\Models\Notification;
use App\Repositories\Contracts\NotificationRepositoryInterface;
use Illuminate\Support\Collection;

class NotificationRepository extends BaseRepository implements NotificationRepositoryInterface
{
    protected array $searchableFields = ['type', 'data'];
    protected array $filterableFields = ['notifiable_type', 'notifiable_id', 'read_at'];

    public function __construct(Notification $model)
    {
        parent::__construct($model);
    }

    public function getUserUnread(int $userId): Collection
    {
        return $this->model->where('notifiable_type', User::class)
            ->where('notifiable_id', $userId)
            ->whereNull('read_at')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function markAsRead(string $id): void
    {
        $notification = $this->findOrFail($id);
        $notification->update(['read_at' => now()]);
        $this->clearModelCache();
    }

    public function markAllAsRead(int $userId): void
    {
        $this->model->where('notifiable_type', User::class)
            ->where('notifiable_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
            
        $this->clearModelCache();
    }

    public function deleteOlderThan(int $days): int
    {
        $count = $this->model->where('created_at', '<', now()->subDays($days))->delete();
        $this->clearModelCache();
        return $count;
    }
}
