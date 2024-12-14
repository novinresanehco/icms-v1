<?php

namespace App\Core\Notification\Analytics\Providers;

use App\Core\Notification\Analytics\Contracts\AnalyticsDataProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Core\Notification\Models\Notification;
use App\Core\Notification\Exceptions\DataProviderException;

class NotificationDataProvider implements AnalyticsDataProvider
{
    /**
     * Gather performance data with optimized queries
     *
     * @param array $filters
     * @return Collection
     * @throws DataProviderException
     */
    public function gatherPerformanceData(array $filters): Collection
    {
        try {
            return DB::table('notifications')
                ->when(isset($filters['start_date']), function($query) use ($filters) {
                    return $query->where('created_at', '>=', $filters['start_date']);
                })
                ->when(isset($filters['end_date']), function($query) use ($filters) {
                    return $query->where('created_at', '<=', $filters['end_date']);
                })
                ->select([
                    'type',
                    'status',
                    DB::raw('COUNT(*) as total'),
                    DB::raw('AVG(TIMESTAMPDIFF(SECOND, created_at, delivered_at)) as avg_delivery_time'),
                    DB::raw('AVG(TIMESTAMPDIFF(SECOND, created_at, read_at)) as avg_read_time'),
                    DB::raw('COUNT(CASE WHEN status = "failed" THEN 1 END) as failure_count'),
                    DB::raw('COUNT(CASE WHEN read_at IS NOT NULL THEN 1 END) as read_count')
                ])
                ->groupBy(['type', 'status'])
                ->get();
        } catch (\Exception $e) {
            throw new DataProviderException(
                "Failed to gather performance data: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Gather segment data with user metrics
     *
     * @param array $filters
     * @return Collection
     * @throws DataProviderException
     */
    public function gatherSegmentData(array $filters): Collection
    {
        try {
            return DB::table('notifications')
                ->join('users', 'notifications.notifiable_id', '=', 'users.id')
                ->select([
                    'users.segment',
                    DB::raw('COUNT(DISTINCT notifications.notifiable_id) as unique_users'),
                    DB::raw('COUNT(*) as total_notifications'),
                    DB::raw('COUNT(CASE WHEN notifications.read_at IS NOT NULL THEN 1 END) as read_count'),
                    DB::raw('AVG(CASE WHEN notifications.read_at IS NOT NULL 
                        THEN TIMESTAMPDIFF(SECOND, notifications.created_at, notifications.read_at) 
                        END) as avg_time_to_read'),
                    DB::raw('COUNT(CASE WHEN notifications.clicked_at IS NOT NULL THEN 1 END) as interaction_count')
                ])
                ->groupBy('users.segment')
                ->get();
        } catch (\Exception $e) {
            throw new DataProviderException(
                "Failed to gather segment data: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Gather channel data with performance metrics
     *
     * @param array $filters
     * @return Collection
     * @throws DataProviderException
     */
    public function gatherChannelData(array $filters): Collection
    {
        try {
            return DB::table('notifications')
                ->select([
                    'channel',
                    DB::raw('COUNT(*) as total_sent'),
                    DB::raw('COUNT(CASE WHEN status = "delivered" THEN 1 END) as delivered_count'),
                    DB::raw('COUNT(CASE WHEN status = "failed" THEN 1 END) as failed_count'),
                    DB::raw('AVG(TIMESTAMPDIFF(SECOND, created_at, delivered_at)) as avg_delivery_time'),
                    DB::raw('COUNT(CASE WHEN error_type IS NOT NULL THEN 1 END) as error_count'),
                    DB::raw('SUM(delivery_cost) as total_cost')
                ])
                ->groupBy('channel')
                ->get();
        } catch (\Exception $e) {
            throw new DataProviderException(
                "Failed to gather channel data: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Gather A/B test data with detailed metrics
     *
     * @param string $testId
     * @return Collection
     * @throws DataProviderException
     */
    public function gatherTestData(string $testId): Collection
    {
        try {
            return DB::table('notification_ab_tests')
                ->where('test_id', $testId)
                ->select([
                    'variant',
                    DB::raw('COUNT(*) as total_sent'),
                    DB::raw('COUNT(CASE WHEN read_at IS NOT NULL THEN 1 END) as read_count'),
                    DB::raw('COUNT(CASE WHEN clicked_at IS NOT NULL THEN 1 END) as click_count'),
                    DB::raw('COUNT(CASE WHEN converted_at IS NOT NULL THEN 1 END) as conversion_count'),
                    DB::raw('AVG(TIMESTAMPDIFF(SECOND, created_at, read_at)) as avg_time_to_read')
                ])
                ->groupBy('variant')
                ->get();
        } catch (\Exception $e) {
            throw new DataProviderException(
                "Failed to gather A/B test data: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }
}
