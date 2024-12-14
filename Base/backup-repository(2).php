<?php

namespace App\Repositories;

use App\Models\Backup;
use App\Core\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

class BackupRepository extends BaseRepository
{
    public function __construct(Backup $model)
    {
        $this->model = $model;
        parent::__construct();
    }

    public function createBackup(array $data): Backup
    {
        $backup = $this->create([
            'name' => $data['name'] ?? 'Backup-' . now()->format('Y-m-d-H-i-s'),
            'size' => $data['size'] ?? 0,
            'path' => $data['path'],
            'type' => $data['type'] ?? 'full',
            'status' => 'completed',
            'metadata' => $data['metadata'] ?? []
        ]);

        $this->clearCache();
        return $backup;
    }

    public function findRecent(int $limit = 10): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [$limit], function () use ($limit) {
            return $this->model->where('status', 'completed')
                             ->orderBy('created_at', 'desc')
                             ->limit($limit)
                             ->get();
        });
    }

    public function findByType(string $type): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [$type], function () use ($type) {
            return $this->model->where('type', $type)
                             ->orderBy('created_at', 'desc')
                             ->get();
        });
    }

    public function updateStatus(int $id, string $status, ?string $error = null): bool
    {
        $data = ['status' => $status];
        if ($error) {
            $data['error'] = $error;
        }
        
        $result = $this->update($id, $data);
        $this->clearCache();
        return $result;
    }

    public function cleanOldBackups(int $days = 30): int
    {
        $count = $this->model->where('created_at', '<', now()->subDays($days))
                            ->delete();
        
        $this->clearCache();
        return $count;
    }
}
