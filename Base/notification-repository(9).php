<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\NotificationRepositoryInterface;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationRepository extends BaseRepository implements NotificationRepositoryInterface
{
    public function __construct(Notification $model)
    {
        parent::__construct($model);
    }

    public function getUserNotifications(
        int $userId, 
        bool $unreadOnly = false,
        int $perPage = 15
    ): LengthAwarePaginator {
        $query = $this->model
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc');

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        return $query->paginate($perPage);
    }

    public function markAsRead(int $notificationId): bool
    {
        return $this->update($notificationId, [
            'read_at' => now()
        ]);
    }

    public function markAllAsRead(int $userId): bool
    {
        return $this->model
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function createNotification(array $data): Notification
    {
        $notification = $this->create([
            'user_id' => $data['user_id'],
            'type' => $data['type'],
            'subject' => $data['subject'],
            'body' => $data['body'],
            'data' => $data['data'] ?? [],
            'action_url' => $data['action_url'] ?? null,
            'action_text' => $data['action_text'] ?? null,
        ]);

        Cache::tags(['notifications', "user:{$data['user_id']}"])->flush();

        return $notification;
    }

    public function getUnreadCount(int $userId): int
    {
        return Cache::tags(['notifications', "user:{$userId}"])->remember(
            "notifications:unread_count:{$userId}",
            now()->addMinutes(5),
            fn () => $this->model
                ->where('user_id', $userId)
                ->whereNull('read_at')
                ->count()
        );
    }

    public function deleteOldNotifications(int $daysOld = 30): int
    {
        $count = $this->model
            ->where('created_at', '<', now()->subDays($daysOld))
            ->whereNotNull('read_at')
            ->delete();

        if ($count > 0) {
            Cache::tags(['notifications'])->flush();
        }

        return $count;
    }

    public function findByType(string $type, ?int $userId = null): Collection
    {
        $query = $this->model->where('type', $type);

        if ($userId) {
            $query->where('user_id', $userId);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }
}
