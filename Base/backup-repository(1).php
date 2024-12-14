<?php

namespace App\Core\Repositories;

use App\Models\Backup;
use App\Core\Services\Cache\CacheService;
use Illuminate\Support\Collection;

class BackupRepository extends AdvancedRepository
{
    protected $model = Backup::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function createBackup(array $data): Backup
    {
        return $this->executeTransaction(function() use ($data) {
            return $this->create([
                'name' => $data['name'],
                'type' => $data['type'],
                'size' => $data['size'],
                'path' => $data['path'],
                'status' => 'pending',
                'created_by' => auth()->id(),
                'created_at' => now()
            ]);
        });
    }

    public function markAsCompleted(Backup $backup): void
    {
        $this->executeTransaction(function() use ($backup) {
            $backup->update([
                'status' => 'completed',
                'completed_at' => now()
            ]);
        });
    }

    public function markAsFailed(Backup $backup, string $error): void
    {
        $this->executeTransaction(function() use ($backup, $error) {
            $backup->update([
                'status' => 'failed',
                'error_message' => $error,
                'failed_at' => now()
            ]);
        });
    }

    public function getRecentBackups(int $limit = 10): Collection
    {
        return $this->executeQuery(function() use ($limit) {
            return $this->model
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();
        });
    }

    public function deleteOldBackups(int $keepLast = 10): int
    {
        return $this->executeTransaction(function() use ($keepLast) {
            $oldBackups = $this->model
                ->orderBy('created_at', 'desc')
                ->skip($keepLast)
                ->take(PHP_INT_MAX)
                ->get();

            foreach ($oldBackups as $backup) {
                // Delete physical file
                if (file_exists($backup->path)) {
                    unlink($backup->path);
                }
                // Delete record
                $backup->delete();
            }

            return $oldBackups->count();
        });
    }
}
