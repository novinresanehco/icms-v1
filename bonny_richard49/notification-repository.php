<?php

namespace App\Core\Notification\Analytics\Repositories;

use App\Core\Notification\Analytics\Models\NotificationData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class AnalyticsRepository
{
    private const CACHE_TTL = 3600; // 1 hour
    private const CACHE_PREFIX = 'notification_analytics:';

    public function store(array $data): void
    {
        DB::table('notification_analytics')->insert(array_merge(
            $data,
            ['created_at' => Carbon::now()]
        ));

        $this->invalidateCache($data['notification_id']);
    }

    public function getByNotificationId(string $notificationId): ?array
    {
        $cacheKey = self::CACHE_PREFIX . $notificationId;

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($notificationId) {
            return DB::table('notification_analytics')
                ->where('notification_id', $notificationId)
                ->first();
        });
    }

    public function getAnalyticsByType(
        string $type,
        Carbon $startDate = null,
        Carbon $endDate = null
    ): Collection {
        $query = DB::table('notification_analytics')
            ->where('type', $type);

        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('created_at', '<=', $endDate);
        }

        return $query->get();
    }

    public function getAggregatedMetrics(
        string $type,
        string $metric,
        string $groupBy = 'daily'
    ): Collection {
        $query = DB::table('notification_analytics')
            ->where('type', $type)
            ->select(
                DB::raw($this->getGroupByClause($groupBy)),
                DB::raw("AVG({$metric}) as average"),
                DB::raw("COUNT(*) as count")
            )
            ->groupBy(DB::raw($this->getGroupByClause($groupBy)));

        return $query->get();
    }

    public function deleteOldRecords(int $daysToKeep = 90): int
    {
        $cutoffDate = Carbon::now()->subDays($daysToKeep);

        return DB::table('notification_analytics')
            ->where('created_at', '<', $cutoffDate)
            ->delete();
    }

    private function getGroupByClause(string $groupBy): string
    {
        return match($groupBy) {
            'hourly' => 'DATE_FORMAT(created_at, "%Y-%m-%d %H:00:00")',
            'daily' => 'DATE(created_at)',
            'weekly' => 'YEARWEEK(created_at)',
            'monthly' => 'DATE_FORMAT(created_at, "%Y-%m-01")',
            default => throw new \InvalidArgumentException("Invalid group by: {$groupBy}")
        };
    }

    private function invalidateCache(string $notificationId): void
    {
        Cache::forget(self::CACHE_PREFIX . $notificationId);
    }
}
