<?php

namespace App\Core\Export\Repositories;

use App\Core\Export\Models\ExportJob;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class ExportRepository
{
    public function create(array $data): ExportJob
    {
        return ExportJob::create($data);
    }

    public function findOrFail(int $id): ExportJob
    {
        return ExportJob::findOrFail($id);
    }

    public function updateStatus(ExportJob $job, string $status): bool
    {
        return $job->update(['status' => $status]);
    }

    public function getWithFilters(array $filters = []): Collection
    {
        $query = ExportJob::query();

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['format'])) {
            $query->where('format', $filters['format']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    public function getOlderThan(int $days): Collection
    {
        return ExportJob::where('created_at', '<', Carbon::now()->subDays($days))
                       ->whereNotNull('file_path')
                       ->get();
    }

    public function getStats(): array
    {
        return [
            'total_exports' => ExportJob::count(),
            'completed_exports' => ExportJob::where('status', 'completed')->count(),
            'failed_exports' => ExportJob::where('status', 'failed')->count(),
            'by_type' => ExportJob::selectRaw('type, count(*) as count')
                ->groupBy('type')
                ->pluck('count', 'type')
                ->toArray(),
            'by_format' => ExportJob::selectRaw('format, count(*) as count')
                ->groupBy('format')
                ->pluck('count', 'format')
                ->toArray()
        ];
    }
}
