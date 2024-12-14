<?php

namespace App\Core\Notification\Repositories;

use App\Core\Notification\Models\Notification;
use App\Core\User\Models\User;
use Illuminate\Support\Collection;

class NotificationRepository
{
    public function create(array $data): Notification
    {
        return Notification::create($data);
    }

    public function findOrFail(int $id): Notification
    {
        return Notification::findOrFail($id);
    }

    public function delete(Notification $notification): bool
    {
        return $notification->delete();
    }

    public function deleteAll(User $user): int
    {
        return Notification::forUser($user)->delete();
    }

    public function markAsRead(Notification $notification): bool
    {
        return $notification->markAsRead();
    }

    public function markAllAsRead(User $user): int
    {
        return Notification::forUser($user)
                         ->unread()
                         ->update(['read_at' => now()]);
    }

    public function getUserNotifications(User $user, array $filters = []): Collection
    {
        $query = Notification::forUser($user);

        if (!empty($filters['type'])) {
            $query->ofType($filters['type']);
        }

        if (isset($filters['read'])) {
            if ($filters['read']) {
                $query->whereNotNull('read_at');
            } else {
                $query->whereNull('read_at');
            }
        }

        if (!empty($filters['status'])) {
            $query->withStatus($filters['status']);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    public function getUnreadCount(User $user): int
    {
        return Notification::forUser($user)->unread()->count();
    }

    public function updateStatus(Notification $notification, string $status): bool
    {
        return $notification->update(['status' => $status]);
    }

    public function logFailure(Notification $notification, string $error): void
    {
        $notification->update([
            'last_error' => $error,
            'retry_count' => $notification->retry_count + 1
        ]);
    }
}
