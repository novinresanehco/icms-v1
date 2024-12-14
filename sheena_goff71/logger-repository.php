<?php

namespace App\Core\Logger\Repositories;

use App\Core\Logger\Models\Log;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class LogRepository
{
    public function create(array $data): Log
    {
        return Log::create($data);
    }

    public function getWithFilters(array $filters = []): Collection
    {
        $query = Log::query();

        if (!empty($filters['type'])) {
            $query->ofType($filters['type']);
        }

        if (!empty($filters['level'])) {
            $query->ofLevel($filters['level']);
        }

        if (!empty($filters['user_id'])) {
            $query->byUser($filters['user_id']);
        }

        if (!empty($filters['search'])) {
            $query->where(function($q) use ($filters) {
                $q->where('message', 'like', "%{$filters['search']}%")
                  ->orWhere('context', 'like', "%{$filters['search']}%");
            });
        }

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $query->inDateRange($filters['start_date'], $filters['end_date']);
        }

        if (!empty($filters['ip_address'])) {
            $query->where('ip_address', $filters['ip_address']);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    public function getStats(array $filters = []): array
    {
        $query = Log::query();

        if (!empty($filters)) {
            $this->applyFilters($query, $filters);
        }

        return [
            'total_logs' => $query->count(),
            'by_type' => $query->selectRaw('type, count(*) as count')
                              ->groupBy('type')
                              ->pluck('count', 'type')
                              ->toArray(),
            'by_level' => $query->selectRaw('level, count(*) as count')
                               ->groupBy('level')
                               ->pluck('count', 'level')
                               ->toArray(),
            'errors_today' => $query->where('level', 'error')
                                   ->whereDate('created_at', Carbon::today())
                                   ->count()
        ];
    }

    public function deleteOlderThan(int $days): int
    {
        return Log::where('created_at', '<', Carbon::now()->subDays($days))->delete();
    }

    private function applyFilters($query, array $filters): void
    {
        foreach ($filters as $key => $value) {
            switch ($key) {
                case 'type':
                    $query->ofType($value);
                    break;
                case 'level':
                    $query->ofLevel($value);
                    break;
                case 'user_id':
                    $query->byUser($value);
                    break;
                case 'start_date':
                case 'end_date':
                    if (isset($filters['start_date']) && isset($filters['end_date'])) {
                        $query->inDateRange($filters['start_date'], $filters['end_date']);
                    }
                    break;
                case 'ip_address':
                    $query->where('ip_address', $value);
                    break;
            }
        }
    }
}
