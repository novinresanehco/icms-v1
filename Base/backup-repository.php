<?php

namespace App\Repositories;

use App\Models\Backup;
use App\Repositories\Contracts\BackupRepositoryInterface;
use Illuminate\Support\Collection;

class BackupRepository extends BaseRepository implements BackupRepositoryInterface
{
    protected array $searchableFields = ['name', 'notes'];
    protected array $filterableFields = ['type', 'status', 'created_by'];

    public function createBackup(array $data): Backup
    {
        return $this->create([
            'name' => $data['name'] ?? 'Backup-' . time(),
            'type' => $data['type'] ?? 'full',
            'path' => $data['path'],
            'size' => $data['size'],
            'hash' => $data['hash'],
            'created_by' => auth()->id(),
            'status' => 'completed',
            'metadata' => $data['metadata'] ?? [],
            'completed_at' => now()
        ]);
    }

    public function getLatestBackups(int $limit = 10): Collection
    {
        return $this->model
            ->where('status', 'completed')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function updateBackupStatus(int $id, string $status, ?string $error = null): bool
    {
        try {
            $data = [
                'status' => $status
            ];

            if ($status === 'completed') {
                $data['completed_at'] = now();
            } elseif ($status === 'failed') {
                $data['error'] = $error;
            }

            return $this->update($id, $data);
        } catch (\Exception $e) {
            \Log::error('Error updating backup status: ' . $e->getMessage());
            return false;
        }
    }

    public function cleanOldBackups(int $keepCount = 10): int
    {
        try {
            $backupsToDelete = $this->model
                ->where('status', 'completed')
                ->orderBy('created_at', 'desc')
                ->skip($keepCount)
                ->take(PHP_INT_MAX)
                ->get();

            $deleted = 0;
            foreach ($backupsToDelete as $backup) {
                if (file_exists($backup->path)) {
                    unlink($backup->path);
                }
                $backup->delete();
                $deleted++;
            }

            return $deleted;
        } catch (\Exception $e) {
            \Log::error('Error cleaning old backups: ' . $e->getMessage());
            return 0;
        }
    }
}
