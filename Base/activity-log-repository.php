<?php

namespace App\Repositories;

use App\Models\ActivityLog;
use App\Repositories\Contracts\ActivityLogRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ActivityLogRepository extends BaseRepository implements ActivityLogRepositoryInterface
{
    protected array $searchableFields = ['description', 'subject_type', 'causer_type'];
    protected array $filterableFields = ['event', 'subject_type', 'causer_type'];

    public function log(string $event, string $description, $subject = null, $causer = null, array $properties = []): ActivityLog
    {
        return $this->create([
            'event' => $event,
            'description' => $description,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id' => $subject ? $subject->id : null,
            'causer_type' => $causer ? get_class($causer) : null,
            'causer_id' => $causer ? $causer->id : null,
            'properties' => $properties,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);
    }

    public function getLatestActivities(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model
            ->with(['causer', 'subject'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function getForSubject($subject, int $limit = 10): Collection
    {
        return $this->model
            ->where('subject_type', get_class($subject))
            ->where('subject_id', $subject->id)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function getForUser(int $userId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model
            ->where('causer_type', 'App\Models\User')
            ->where('causer_id', $userId)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function cleanOldLogs(int $days = 30): int
    {
        return $this->model
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }

    public function getActivityStats(): array
    {
        return [
            'total_logs' => $this->model->count(),
            'logs_by_event' => $this->model
                ->groupBy('event')
                ->selectRaw('event, count(*) as count')
                ->pluck('count', 'event')
                ->toArray(),
            'logs_by_subject' => $this->model
                ->groupBy('subject_type')
                ->selectRaw('subject_type, count(*) as count')
                ->pluck('count', 'subject_type')
                ->toArray(),
            'recent_activity' => $this->model
                ->whereDate('created_at', '>=', now()->subDays(7))
                ->count()
        ];
    }
}
