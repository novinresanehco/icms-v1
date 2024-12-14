<?php

namespace App\Core\Import\Repositories;

use App\Core\Import\Models\ImportJob;
use Illuminate\Support\Collection;

class ImportRepository
{
    public function create(array $data): ImportJob
    {
        return ImportJob::create($data);
    }

    public function findOrFail(int $id): ImportJob
    {
        return ImportJob::findOrFail($id);
    }

    public function updateStatus(ImportJob $job, string $status): bool
    {
        return $job->update(['status' => $status]);
    }

    public function getWithFilters(array $filters = []): Collection
    {
        $query = ImportJob::query();

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    public function getStats(): array
    {
        return [
            'total_imports' => ImportJob::count(),
            'completed_imports' => ImportJob::where('status', 'completed')->count(),
            'failed_imports' => ImportJob::where('status', 'failed')->count(),
            'total_rows_processed' => ImportJob::sum('processed_rows'),
            'total_rows_failed' => ImportJob::sum('failed_rows'),
            'by_type' => ImportJob::selectRaw('type, count(*) as count')
                ->groupBy('type')
                ->pluck('count', 'type')
                ->toArray()
        ];
    }
}
