<?php

namespace App\Core\Repositories\Contracts;

use App\Models\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface NotificationRepositoryInterface extends RepositoryInterface
{
    public function getUserNotifications(
        int $userId, 
        bool $unreadOnly = false,
        int $perPage = 15
    ): LengthAwarePaginator;
    
    public function markAsRead(int $notificationId): bool;
    
    public function markAllAsRead(int $userId): bool;
    
    public function createNotification(array $data): Notification;
    
    public function getUnreadCount(int $userId): int;
    
    public function deleteOldNotifications(int $daysOld = 30): int;
    
    public function findByType(string $type, ?int $userId = null): Collection;
}
