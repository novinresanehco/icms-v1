<?php

namespace App\Repositories;

use App\Models\Activity;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class ActivityRepository extends BaseRepository implements ActivityRepositoryInterface
{
    protected array $searchableFields = ['description', 'properties'];
    protected array $filterableFields = ['type', 'user_id', 'subject_type'];

    public function logActivity(string $type, string $description, $subject = null, array $properties = []): Activity
    {
        $activity = $this->create([
            'type' => $type,
            'description' => $description,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id' => $subject ? $subject->id : null,
            'user_id' => auth()->id(),
            'properties' => array_merge($properties, [
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent()
            ])
        ]);

        Cache::tags(['activity'])->flush();

        return $activity;
    }

    public function getUserActivity(int $userId, int $limit = 20): Collection
    {
        $cacheKey = "activity.user.{$userId}.{$limit}";

        return Cache::tags(['activity'])->remember($cacheKey, 3600, function() use ($userId, $limit) {
            return $this->model
                ->where('user_id', $userId)
                ->with(['subject', 'user'])
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();
        });
    }

    public function getSubjectActivity($subject, int $limit = 20): Collection
    {
        $cacheKey = "activity.subject." . get_class($subject) . ".{$subject->id}.{$limit}";

        return Cache::tags(['activity'])->remember($cacheKey, 3600, function() use ($subject, $limit) {
            return $this->model
                ->where('subject_type', get_class($subject))
                ->where('subject_id', $subject->id)
                ->with(['user'])
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();
        });
    }

    public function getRecentActivity(int $limit = 20): Collection
    {
        $cacheKey = "activity.recent.{$limit}";

        return Cache::tags(['activity'])->remember($cacheKey, 3600, function() use ($limit) {
            return $this->model
                ->with(['subject', 'user'])
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();
        });
    }

    public function getActivityByType(string $type, int $limit = 20): Collection
    {
        $cacheKey = "activity.type.{$type}.{$limit}";

        return Cache::tags(['activity'])->remember($cacheKey, 3600, function() use ($type, $limit) {
            return $this->model
                ->where('type', $type)
                ->with(['subject', 'user'])
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();
        });
    }

    public function clearOldActivity(int $days = 30): int
    {
        $count = $this->model
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        if ($count > 0) {
            Cache::tags(['activity'])->flush();
        }

        return $count;
    }

    public function getActivityStats(array $dateRange = []): array
    {
        $cacheKey = 'activity.stats.' . md5(serialize($dateRange));

        return Cache::tags(['activity'])->remember($cacheKey, 3600, function() use ($dateRange) {
            $query = $this->model->newQuery();

            if (!empty($dateRange)) {
                $query->whereBetween('created_at', $dateRange);
            }

            return [
                'total' => $query->count(),
                'by_type' => $query->groupBy('type')
                    ->selectRaw('type, count(*) as count')
                    ->pluck('count', 'type'),
                'by_user' => $query->groupBy('user_id')
                    ->selectRaw('user_id, count(*) as count')
                    ->pluck('count', 'user_id')
            ];
        });
    }
}
