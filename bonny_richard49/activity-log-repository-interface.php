<?php

namespace App\Core\ActivityLog\Repository;

use App\Core\ActivityLog\Models\Activity;
use App\Core\ActivityLog\DTO\ActivityData;
use App\Core\Shared\Repository\RepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface ActivityLogRepositoryInterface extends RepositoryInterface
{
    /**
     * Log a new activity.
     *
     * @param ActivityData $data
     * @return Activity
     */
    public function log(ActivityData $data): Activity;

    /**
     * Get activities for a specific model.
     *
     * @param string $modelType
     * @param int $modelId
     * @return Collection
     */
    public function getForModel(string $modelType, int $modelId): Collection;

    /**
     * Get activities by user.
     *
     * @param int $userId
     * @param array $options
     * @return Collection
     */
    public function getByUser(int $userId, array $options = []): Collection;

    /**
     * Get paginated activities.
     *
     * @param int $perPage
     * @param array $filters
     * @return LengthAwarePaginator
     */
    public function getPaginated(int $perPage = 15, array $filters = []): LengthAwarePaginator;

    /**
     * Get activities by type.
     *
     * @param string $type
     * @param array $options
     * @return Collection
     */
    public function getByType(string $type, array $options = []): Collection;

    /**
     * Clean old activity logs.
     *
     * @param int $olderThanDays
     * @return int Number of records deleted
     */
    public function clean(int $olderThanDays): int;

    /**
     * Get activity statistics.
     *
     * @param array $options
     * @return array
     */
    public function getStatistics(array $options = []): array;

    /**
     * Mark activities as read.
     *
     * @param int $userId
     * @param array $activityIds
     * @return bool
     */
    public function markAsRead(int $userId, array $activityIds): bool;

    /**
     * Get unread activities count.
     *
     * @param int $userId
     * @return int
     */
    public function getUnreadCount(int $userId): int;

    /**
     * Export activities to file.
     *
     * @param array $filters
     * @param string $format
     * @return string File path
     */
    public function export(array $filters, string $format = 'csv'): string;

    /**
     * Search activities.
     *
     * @param string $query
     * @param array $options
     * @return Collection
     */
    public function search(string $query, array $options = []): Collection;
}
