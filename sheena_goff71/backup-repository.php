<?php

namespace App\Core\Backup\Repositories;

use App\Core\Backup\Models\Backup;
use Illuminate\Support\Collection;

class BackupRepository
{
    public function create(array $data): Backup
    {
        return Backup::create($data);
    }

    public function update(Backup $backup, array $data): Backup
    {
        $backup->update($data);
        return $backup->fresh();
    }

    public function delete(Backup $backup): bool
    {
        return $backup->delete();
    }

    public function getWithFilters(array $filters = []): Collection
    {
        $query = Backup::query();

        if (!empty($filters['type'])) {
            $query->ofType($filters['type']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['verified'])) {
            $query->whereNotNull('verified_at');
        }

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    public function getLatestBackup(?string $type = null): ?Backup
    {
        $query = Backup::completed();

        if ($type !== null) {
            $query->ofType($type);
        }

        return $query->latest()->first();
    }

    public function getOldBackups(int $keepLast): Collection
    {
        return Backup::completed()
                    ->orderByDesc('created_at')
                    ->skip($keepLast)
                    ->take(PHP_INT_MAX)
                    ->get();
    }

    public function getStats(): array
    {
        return [
            'total_backups' => Backup::count(),
            'completed_backups' => Backup::completed()->count(),
            'failed_backups' => Backup::failed()->count(),
            'total_size' => Backup::completed()->sum('file_size'),
            'by_type' => Backup::selectRaw('type, count(*) as count')
                              ->groupBy('type')
                              ->pluck('count', 'type')
                              ->toArray(),
            'latest_backup' => $this->getLatestBackup()?->created_at,
            'verified_backups' => Backup::whereNotNull('verified_at')->count()
        ];
    }
}
