<?php

namespace App\Repositories;

use App\Models\Activity;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ActivityRepository implements ActivityRepositoryInterface
{
    protected Activity $model;
    protected int $cacheTTL = 1800; // 30 minutes

    public function __construct(Activity $model)
    {
        $this->model = $model;
    }

    public function log(array $data): ?int
    {
        try {
            $activity = $this->model->create([
                'user_id' => auth()->id(),
                'action' => $data['action'],
                'model_type' => $data['model_type'] ?? null,
                'model_id' => $data['model_id'] ?? null,
                'description' => $data['description'],
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'before' => $data['before'] ?? null,
                'after' => $data['after'] ?? null,
                'metadata' => $data['metadata'] ?? [],
            ]);

            $this->clearActivityCache();
            return $activity->id;
        } catch (\Exception $e) {
            Log::error('Failed to log activity: ' . $e->getMessage());
            return null;
        }
    }

    public function get(int $id): ?array
    {
        try {
            return Cache::remember(
                "activity.{$id}",
                $this->cacheTTL,
                fn() => $this->model->with(['user'])
                    ->findOrFail($id)
                    ->toArray()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get activity: ' . $e->getMessage());
            return null;
        }
    }

    public function getAllPaginated(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        try {
            $query = $this->model->query()
                ->with(['user']);

            if (!empty($filters['user_id'])) {
                $query->where('user_id', $filters['user_id']);
            }

            if (!empty($filters['action'])) {
                $query->where('action', $filters['action']);
            }

            if (!empty($filters['model_type'])) {
                $query->where('model_type', $filters['model_type']);
            }

            if (!empty($filters['model_id'])) {
                $query->where('model_id', $filters['model_id']);
            }

            if (!empty($filters['date_from'])) {
                $query->where('created_at', '>=', $filters['date_from']);
            }

            if (!empty($filters['date_to'])) {
                $query->where('created_at', '<=', $filters['date_to']);
            }

            if (!empty($filters['ip_address'])) {
                $query->where('ip_address', $filters['ip_address']);
            }

            return $query->latest()->paginate($perPage);
        } catch (\Exception $e) {
            Log::error('Failed to get paginated activities: ' . $e->getMessage());
            return new LengthAwarePaginator([], 0, $perPage);
        }
    }

    public function getForModel(string $modelType, int $modelId): Collection
    {
        try {
            return Cache::remember(
                "activity.model.{$modelType}.{$modelId}",
                $this->cacheTTL,
                fn() => $this->model->with(['user'])
                    ->where('model_type', $modelType)
                    ->where('model_id', $modelId)
                    ->latest()
                    ->get()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get activities for model: ' . $e->getMessage());
            return new Collection();
        }
    }

    public function getForUser(int $userId): Collection
    {
        try {
            return Cache::remember(
                "activity.user.{$userId}",
                $this->cacheTTL,
                fn() => $this->model->where('user_id', $userId)
                    ->latest()
                    ->get()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get activities for user: ' . $e->getMessage());
            return new Collection();
        }
    }

    public function getByAction(string $action): Collection
    {
        try {
            return Cache::remember(
                "activity.action.{$action}",
                $this->cacheTTL,
                fn() => $this->model->with(['user'])
                    ->where('action', $action)
                    ->latest()
                    ->get()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get activities by action: ' . $e->getMessage());
            return new Collection();
        }
    }

    public function deleteOlderThan(int $days): bool
    {
        try {
            DB::beginTransaction();

            $this->model->where('created_at', '<', now()->subDays($days))->delete();

            $this->clearActivityCache();
            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete old activities: ' . $e->getMessage());
            return false;
        }
    }

    public function getStats(array $filters = []): array
    {
        try {
            $query = $this->model->query();

            if (!empty($filters['date_from'])) {
                $query->where('created_at', '>=', $filters['date_from']);
            }

            if (!empty($filters['date_to'])) {
                $query->where('created_at', '<=', $filters['date_to']);
            }

            return [
                'total_count' => $query->count(),
                'user_count' => $query->distinct('user_id')->count('user_id'),
                'action_summary' => $query->groupBy('action')
                    ->select('action', DB::raw('count(*) as count'))
                    ->pluck('count', 'action')
                    ->toArray(),
                'recent_activities' => $query->with(['user'])
                    ->latest()
                    ->limit(10)
                    ->get()
                    ->toArray()
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get activity stats: ' . $e->getMessage());
            return [];
        }
    }

    protected function clearActivityCache(): void
    {
        Cache::tags(['activities'])->flush();
    }
}
