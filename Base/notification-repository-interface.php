<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface NotificationRepositoryInterface
{
    public function createNotification(array $data): bool;
    public function markAsRead(string $id): bool;
    public function markAllAsRead(int $userId): bool;
    public function deleteNotification(string $id): bool;
    public function getUserUnreadNotifications(int $userId): Collection;
    public function getUserNotifications(int $userId): LengthAwarePaginator;
    public function deleteOlderThan(int $days): bool;
    public function getUnreadCount(int $userId): int;
    public function getNotificationsByType(int $userId, string $type): Collection;
    public function getRecentNotifications(int $userId, int $limit = 5): Collection;
}
