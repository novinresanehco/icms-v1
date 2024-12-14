<?php

namespace App\Core\Activity\Repositories;

use App\Core\Activity\Models\Activity;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class ActivityRepository
{
    public function create(array $data): Activity
    {
        return Activity::create($data);
    }

    public function getWithFilters(array $filters = []): Collection
    {
        $query = Activity::with('user');

        if (!empty($filters['type'])) {
            $query->ofType($filters['type']);
        }

        if (!empty($filters['user_id'])) {
            $query->byUser($filters['user_id']);
        }

        if (!empty($filters['subject'])) {
            $query->bySubject($filters['subject']);
        }

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $query->inDateRange($filters['start_date'], $filters['end_date']);
        }

        if (!empty($filters['ip_address'])) {
            $query->where('ip_address', $filters['ip_address']);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    public function getUserActivity(int $userId, array $filters = []): Collection
    {
        return $this->getWithFilters(array_merge($filters, ['user_id' => $userId]));
    }

    public function getByType(string $type, array $filters = []): Collection
    {
        return $this->getWithFilters(array_merge($filters, ['type' => $type]));
    }

    public function getBySubject(string $subject, array $filters = []): Collection
    {
        return $this->getWithFilters(array_merge($filters, ['subject' => $subject]));
    }

    public function getActivityStats(array $filters = []): array
    {
        $query = Activity::query();

        if (!empty($filters)) {
            $query = $this->applyFilters($query, $filters);
        }

        return [
            'total' => $query->count(),
            'by_type' => $query->groupBy('type')
                              ->selectRaw('type, count(*) as count')
                              ->pluck('count', 'type')
                              ->toArray(),
            'by_user' => $query->groupBy('user_id')
                              ->selectRaw('user_id, count(*) as count')
                              ->pluck('count', 'user_id')
                              ->toArray()
        ];
    }

    public function deleteOlderThan(int $days): int
    {
        return Activity::where('created_at', '<', Carbon::now()->subDays($days))
                      ->delete();
    }

    public function exportActivities(array $filters = []): array
    {
        $activities = $this->getWithFilters($filters);

        return $activities->map(function ($activity) {
            return [
                'id' => $activity->id,
                'date' => $activity->created_at->toDateTimeString(),
                'type' => $activity->type,
                'user' => $activity->user ? $activity->user->name : 'System',
                'subject' => $activity->subject,
                'description' => $activity->description,
                'ip_address' => $activity->ip_address,
                'user_agent' => $activity->user_agent,
                'data' => json_encode($activity->data)
            ];
        })->toArray();
    }

    private function applyFilters($query, array $filters)
    {
        foreach ($filters as $key => $value) {
            switch ($key) {
                case 'type':
                    $query->ofType($value);
                    break;
                case 'user_id':
                    $query->byUser($value);
                    break;
                case 'subject':
                    $query->bySubject($value);
                    break;
                case 'start_date':
                case 'end_date':
                    if (isset($filters['start_date']) && isset($filters['end_date'])) {
                        $query->inDateRange($filters['start_date'], $filters['end_date']);
                    }
                    break;
            }
        }

        return $query;
    }
}
