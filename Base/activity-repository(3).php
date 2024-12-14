<?php

namespace App\Repositories;

use App\Models\Activity;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ActivityRepository implements ActivityRepositoryInterface
{
    protected Activity $model;
    
    public function __construct(Activity $model)
    {
        $this->model = $model;
    }

    public function log(string $type, array $data): ?int
    {
        try {
            $activity = $this->model->create([
                'user_id' => auth()->id(),
                'type' => $type,
                'data' => $data,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'subject_type' => $data['subject_type'] ?? null,
                'subject_id' => $data['subject_id'] ?? null,
            ]);

            return $activity->id;
        } catch (\Exception $e) {
            Log::error('Failed to log activity: ' . $e->getMessage());
            return null;
        }
    }

    public function get(int $activityId): ?array
    {
        try {
            $activity = $this->model->with(['user', 'subject'])->find($activityId);
            return $activity ? $activity->toArray() : null;
        } catch (\Exception $e) {
            Log::error('Failed to get activity: ' . $e->getMessage());
            return null;
        }
    }

    public function getUserActivities(int $userId, int $perPage = 15): LengthAwarePaginator
    {
        try {
            return $this->model
                ->where('user_id', $userId)
                ->with(['subject'])
                ->latest()
                ->paginate($perPage);
        } catch (\Exception $e) {
            Log::error('Failed to get user activities: ' . $e->getMessage());
            return new LengthAwarePaginator([], 0, $perPage);
        }
    }

    public function getByType(string $type, int $perPage = 15): LengthAwarePaginator
    {
        try {
            return $this->model
                ->where('type', $type)
                ->with(['user', 'subject'])
                ->latest()
                ->paginate($perPage);
        } catch (\Exception $e) {
            Log::error('Failed to get activities by type: ' . $e->getMessage());
            return new LengthAwarePaginator([], 0, $perPage);
        }
    }

    public function getRecent(int $limit = 10): Collection
    {
        try {
            return $this->model
                ->with(['user', 'subject'])
                ->latest()
                ->limit($limit)
                ->get();
        } catch (\Exception $e) {
            Log::error('Failed to get recent activities: ' . $e->getMessage());
            return collect();
        }
    }

    public function search(array $criteria, int $perPage = 15): LengthAwarePaginator
    {
        try {
            $query = $this->model->query();

            if (isset($criteria['user_id'])) {
                $query->where('user_id', $criteria['user_id']);
            }

            if (isset($criteria['type'])) {
                $query->where('type', $criteria['type']);
            }

            if (isset($criteria['date_from'])) {
                $query->whereDate('created_at', '>=', $criteria['date_from']);
            }

            if (isset($criteria['date_to'])) {
                $query->whereDate('created_at', '<=', $criteria['date_to']);
            }

            if (isset($criteria['subject_type'])) {
                $query->where('subject_type', $criteria['subject_type']);
            }

            return $query->with(['user', 'subject'])
                ->latest()
                ->paginate($perPage);
        } catch (\Exception $e) {
            Log::error('Failed to search activities: ' . $e->getMessage());
            return new LengthAwarePaginator([], 0, $perPage);
        }
    }

    public function deleteOlderThan(int $days): bool
    {
        try {
            DB::beginTransaction();

            $date = now()->subDays($days);
            $this->model->where('created_at', '<', $date)->delete();

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete old activities: ' . $e->getMessage());
            return false;
        }
    }

    public function getStats(string $type, array $dateRange): array
    {
        try {
            return $this->model
                ->where('type', $type)
                ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
                ->groupBy('date')
                ->orderBy('date')
                ->get()
                ->pluck('count', 'date')
                ->toArray();
        } catch (\Exception $e) {
            Log::error('Failed to get activity stats: ' . $e->getMessage());
            return [];
        }
    }
}
