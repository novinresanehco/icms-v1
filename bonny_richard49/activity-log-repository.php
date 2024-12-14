<?php

namespace App\Core\ActivityLog\Repository;

use App\Core\ActivityLog\Models\Activity;
use App\Core\ActivityLog\DTO\ActivityData;
use App\Core\ActivityLog\Events\ActivityLogged;
use App\Core\ActivityLog\Events\ActivitiesMarkedAsRead;
use App\Core\ActivityLog\Exceptions\ActivityLogException;
use App\Core\Shared\Repository\BaseRepository;
use App\Core\Shared\Cache\CacheManagerInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

class ActivityLogRepository extends BaseRepository implements ActivityLogRepositoryInterface
{
    protected const CACHE_KEY = 'activity_log';
    protected const CACHE_TTL = 1800; // 30 minutes

    public function __construct(CacheManagerInterface $cache)
    {
        parent::__construct($cache);
        $this->setCacheKey(self::CACHE_KEY);
        $this->setCacheTtl(self::CACHE_TTL);
    }

    protected function getModelClass(): string
    {
        return Activity::class;
    }

    public function log(ActivityData $data): Activity
    {
        DB::beginTransaction();
        try {
            // Create activity record
            $activity = $this->model->create([
                'type' => $data->type,
                'description' => $data->description,
                'user_id' => $data->userId,
                'model_type' => $data->modelType,
                'model_id' => $data->modelId,
                'data' => $data->data,
                'ip_address' => $data->ipAddress,
                'user_agent' => $data->userAgent,
            ]);

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new ActivityLogged($activity));

            DB::commit();
            return $activity;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ActivityLogException('Failed to log activity: ' . $e->getMessage());
        }
    }

    public function getForModel(string $modelType, int $modelId): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey("model:{$modelType}:{$modelId}"),
            fn() => $this->model->where('model_type', $modelType)
                               ->where('model_id', $modelId)
                               ->with('user')
                               ->orderBy('created_at', 'desc')
                               ->get()
        );
    }

    public function getByUser(int $userId, array $options = []): Collection
    {
        $cacheKey = $this->getCacheKey("user:{$userId}:" . md5(serialize($options)));

        return $this->cache->remember($cacheKey, function() use ($userId, $options) {
            $query = $this->model->where('user_id', $userId);

            if (isset($options['type'])) {
                $query->where('type', $options['type']);
            }

            if (isset($options['from_date'])) {
                $query->where('created_at', '>=', $options['from_date']);
            }

            if (isset($options['to_date'])) {
                $query->where('created_at', '<=', $options['to_date']);
            }

            return $query->orderBy('created_at', 'desc')->get();
        });
    }

    public function getPaginated(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = $this->model->with('user');

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (isset($filters['from_date'])) {
            $query->where('created_at', '>=', $filters['from_date']);
        }

        if (isset($filters['to_date'])) {
            $query->where('created_at', '<=', $filters['to_date']);
        }

        if (isset($filters['model_type'])) {
            $query->where('model_type', $filters['model_type']);
        }

        return $query->orderBy('created_at', 'desc')
                    ->paginate($perPage);
    }

    public function getByType(string $type, array $options = []): Collection
    {
        $cacheKey = $this->getCacheKey("type:{$type}:" . md5(serialize($options)));

        return $this->cache->remember($cacheKey, function() use ($type, $options) {
            $query = $this->model->where('type', $type);

            if (isset($options['from_date'])) {
                $query->where('created_at', '>=', $options['from_date']);
            }

            if (isset($options['to_date'])) {
                $query->where('created_at', '<=', $options['to_date']);
            }

            return $query->orderBy('created_at', 'desc')->get();
        });
    }

    public function clean(int $olderThanDays): int
    {
        $date = now()->subDays($olderThanDays);
        $count = $this->model->where('created_at', '<', $date)->delete();

        if ($count > 0) {
            $this->clearCache();
        }

        return $count;
    }

    public function getStatistics(array $options = []): array
    {
        $cacheKey = $this->getCacheKey('stats:' . md5(serialize($options)));

        return $this->cache->remember($cacheKey, function() use ($options) {
            $query = $this->model->query();

            if (isset($options['from_date'])) {
                $query->where('created_at', '>=', $options['from_date']);
            }

            if (isset($options['to_date'])) {
                $query->where('created_at', '<=', $options['to_date']);
            }

            return [
                'total_activities' => $query->count(),
                'by_type' => $query->clone()->groupBy('type')
                    ->selectRaw('type, count(*) as count')
                    ->pluck('count', 'type')
                    ->toArray(),
                'by_user' => $query->clone()->groupBy('user_id')
                    ->selectRaw('user_id, count(*) as count')
                    ->pluck('count', 'user_id')
                    ->toArray(),
                'by_model' => $query->clone()->groupBy('model_type')
                    ->selectRaw('model_type, count(*) as count')
                    ->pluck('count', 'model_type')
                    ->toArray(),
            ];
        });
    }

    public function markAsRead(int $userId, array $activityIds): bool
    {
        DB::beginTransaction();
        try {
            $affected = $this->model->whereIn('id', $activityIds)
                                  ->where('user_id', $userId)
                                  ->update(['read_at' => now()]);

            if ($affected > 0) {
                $this->clearCache();
                Event::dispatch(new ActivitiesMarkedAsRead($userId, $activityIds));
            }

            DB::commit();
            return $affected > 0;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ActivityLogException('Failed to mark activities as read: ' . $e->getMessage());
        }
    }

    public function getUnreadCount(int $userId): int
    {
        return $this->cache->remember(
            $this->getCacheKey("unread:{$userId}"),
            fn() => $this->model->where('user_id', $userId)
                               ->whereNull('read_at')
                               ->count()
        );
    }

    public function export(array $filters, string $format = 'csv'): string
    {
        $activities = $this->buildFilteredQuery($filters)->get();
        
        $exporter = app(ActivityExporter::class);
        return $exporter->export($activities, $format);
    }

    public function search(string $query, array $options = []): Collection
    {
        $searchQuery = $this->model->where(function($q) use ($query) {
            $q->where('description', 'LIKE', "%{$query}%")
              ->orWhere('type', 'LIKE', "%{$query}%")
              ->orWhereHas('user', function($q) use ($query) {
                  $q->where('name', 'LIKE', "%{$query}%");
              });
        });

        if (isset($options['type'])) {
            $searchQuery->where('type', $options['type']);
        }

        if (isset($options['from_date'])) {
            $searchQuery->where('created_at', '>=', $options['from_date']);
        }

        if (isset($options['to_date'])) {
            $searchQuery->where('created_at', '<=', $options['to_date']);
        }

        return $searchQuery->with('user')
                          ->orderBy('created_at', 'desc')
                          ->get();
    }

    private function buildFilteredQuery(array $filters)
    {
        $query = $this->model->with('user');

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (isset($filters['from_date'])) {
            $query->where('created_at', '>=', $filters['from_date']);
        }

        if (isset($filters['to_date'])) {
            $query->where('created_at', '<=', $filters['to_date']);
        }

        return $query->orderBy('created_at', 'desc');
    }
}
