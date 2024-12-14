<?php

namespace App\Core\Repository;

use App\Models\Activity;
use App\Core\Events\ActivityEvents;
use App\Core\Exceptions\ActivityRepositoryException;

class ActivityRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Activity::class;
    }

    /**
     * Log activity
     */
    public function logActivity(string $type, string $description, array $data = []): Activity
    {
        try {
            $activity = $this->create([
                'type' => $type,
                'description' => $description,
                'data' => $data,
                'user_id' => auth()->id(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'created_at' => now()
            ]);

            event(new ActivityEvents\ActivityLogged($activity));
            return $activity;

        } catch (\Exception $e) {
            throw new ActivityRepositoryException(
                "Failed to log activity: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get user activity
     */
    public function getUserActivity(int $userId, array $options = []): Collection
    {
        $query = $this->model->where('user_id', $userId);

        if (isset($options['type'])) {
            $query->where('type', $options['type']);
        }

        if (isset($options['from'])) {
            $query->where('created_at', '>=', $options['from']);
        }

        if (isset($options['to'])) {
            $query->where('created_at', '<=', $options['to']);
        }

        return $query->latest()->get();
    }

    /**
     * Get activity by type
     */
    public function getActivityByType(string $type, array $options = []): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("type.{$type}", json_encode($options)),
            $this->cacheTime,
            function() use ($type, $options) {
                $query = $this->model->where('type', $type);

                if (isset($options['from'])) {
                    $query->where('created_at', '>=', $options['from']);
                }

                if (isset($options['to'])) {
                    $query->where('created_at', '<=', $options['to']);
                }

                if (isset($options['limit'])) {
                    $query->limit($options['limit']);
                }

                return $query->latest()->get();
            }
        );
    }

    /**
     * Get recent activity
     */
    public function getRecentActivity(int $limit = 50): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("recent.{$limit}"),
            300, // 5 minutes cache
            fn() => $this->model->with('user')
                               ->latest()
                               ->limit($limit)
                               ->get()
        );
    }

    /**
     * Search activity
     */
    public function searchActivity(array $criteria): Collection
    {
        $query = $this->model->newQuery();

        if (isset($criteria['search'])) {
            $query->where(function($q) use ($criteria) {
                $q->where('description', 'like', "%{$criteria['search']}%")
                  ->orWhere('type', 'like', "%{$criteria['search']}%");
            });
        }

        if (isset($criteria['user_id'])) {
            $query->where('user_id', $criteria['user_id']);
        }

        if (isset($criteria['type'])) {
            $query->where('type', $criteria['type']);
        }

        if (isset($criteria['date_range'])) {
            $query->whereBetween('created_at', $criteria['date_range']);
        }

        return $query->latest()->get();
    }

    /**
     * Get activity statistics
     */
    public function getActivityStats(): array
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('stats'),
            300, // 5 minutes cache
            fn() => [
                'total_activities' => $this->model->count(),
                'activities_today' => $this->model->whereDate('created_at', today())->count(),
                'activities_this_week' => $this->model->whereBetween('created_at', [
                    now()->startOfWeek(),
                    now()->endOfWeek()
                ])->count(),
                'popular_types' => $this->model->select('type', DB::raw('count(*) as count'))
                    ->groupBy('type')
                    ->orderByDesc('count')
                    ->limit(5)
                    ->get()
            ]
        );
    }

    /**
     * Clean old activity logs
     */
    public function cleanOldLogs(int $days = 30): int
    {
        try {
            $deleted = $this->model
                ->where('created_at', '<', now()->subDays($days))
                ->delete();

            $this->clearCache();
            return $deleted;
            
        } catch (\Exception $e) {
            throw new ActivityRepositoryException(
                "Failed to clean old logs: {$e->getMessage()}"
            );
        }
    }
}
