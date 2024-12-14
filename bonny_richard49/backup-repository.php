<?php

namespace App\Core\Backup\Repository;

use App\Core\Backup\Models\Backup;
use App\Core\Backup\DTO\BackupData;
use App\Core\Backup\Events\BackupCreated;
use App\Core\Backup\Events\BackupRestored;
use App\Core\Backup\Events\BackupDeleted;
use App\Core\Backup\Services\BackupManager;
use App\Core\Backup\Services\BackupScheduler;
use App\Core\Backup\Exceptions\BackupNotFoundException;
use App\Core\Backup\Exceptions\BackupIntegrityException;
use App\Core\Shared\Repository\BaseRepository;
use App\Core\Shared\Cache\CacheManagerInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

class BackupRepository extends BaseRepository implements BackupRepositoryInterface
{
    protected const CACHE_KEY = 'backups';
    protected const CACHE_TTL = 3600; // 1 hour

    protected BackupManager $backupManager;
    protected BackupScheduler $scheduler;

    public function __construct(
        CacheManagerInterface $cache,
        BackupManager $backupManager,
        BackupScheduler $scheduler
    ) {
        parent::__construct($cache);
        $this->backupManager = $backupManager;
        $this->scheduler = $scheduler;
        $this->setCacheKey(self::CACHE_KEY);
        $this->setCacheTtl(self::CACHE_TTL);
    }

    protected function getModelClass(): string
    {
        return Backup::class;
    }

    public function createBackup(BackupData $data): Backup
    {
        DB::beginTransaction();
        try {
            // Create backup record
            $backup = $this->model->create([
                'name' => $data->name,
                'type' => $data->type,
                'description' => $data->description,
                'includes' => $data->includes,
                'excludes' => $data->excludes,
                'options' => $data->options,
            ]);

            // Perform backup
            $result = $this->backupManager->create($backup);
            
            // Update backup details
            $backup->update([
                'path' => $result['path'],
                'size' => $result['size'],
                'manifest' => $result['manifest'],
                'checksum' => $result['checksum'],
                'completed_at' => now(),
            ]);

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new BackupCreated($backup));

            DB::commit();
            return $backup->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function restore(int $id): bool
    {
        DB::beginTransaction();
        try {
            $backup = $this->findOrFail($id);

            // Verify backup integrity
            if (!$this->verifyIntegrity($id)['valid']) {
                throw new BackupIntegrityException('Backup integrity check failed');
            }

            // Perform restore
            $result = $this->backupManager->restore($backup);

            // Update backup details
            $backup->update([
                'last_restored_at' => now(),
                'restore_count' => $backup->restore_count + 1,
            ]);

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new BackupRestored($backup));

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getByType(string $type): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey("type:{$type}"),
            fn() => $this->model->where('type', $type)
                               ->orderBy('created_at', 'desc')
                               ->get()
        );
    }

    public function getLatest(?string $type = null): ?Backup
    {
        $query = $this->model->orderBy('created_at', 'desc');
        
        if ($type) {
            $query->where('type', $type);
        }

        return $query->first();
    }

    public function cleanOldBackups(int $keepLast): int
    {
        $backups = $this->model->orderBy('created_at', 'desc')
                              ->skip($keepLast)
                              ->get();

        $count = 0;
        foreach ($backups as $backup) {
            if ($this->delete($backup->id)) {
                $count++;
            }
        }

        return $count;
    }

    public function download(int $id): string
    {
        $backup = $this->findOrFail($id);
        return $this->backupManager->getDownloadPath($backup);
    }

    public function verifyIntegrity(int $id): array
    {
        $backup = $this->findOrFail($id);
        return $this->backupManager->verifyIntegrity($backup);
    }

    public function getManifest(int $id): array
    {
        $backup = $this->findOrFail($id);
        return $backup->manifest ?? [];
    }

    public function scheduleBackup(BackupData $data, string $schedule): bool
    {
        return $this->scheduler->schedule($data, $schedule);
    }

    public function cancelScheduledBackup(int $id): bool
    {
        $backup = $this->findOrFail($id);
        return $this->scheduler->cancel($backup);
    }

    public function getBackupSize(int $id): int
    {
        $backup = $this->findOrFail($id);
        return $backup->size ?? $this->backupManager->getBackupSize($backup);
    }

    public function getStatistics(): array
    {
        return $this->cache->remember(
            $this->getCacheKey('stats'),
            function() {
                return [
                    'total_backups' => $this->model->count(),
                    'total_size' => $this->model->sum('size'),
                    'average_size' => $this->model->avg('size'),
                    'by_type' => $this->model->groupBy('type')
                        ->selectRaw('type, count(*) as count, sum(size) as total_size')
                        ->get()
                        ->keyBy('type')
                        ->toArray(),
                    'last_backup' => $this->getLatest(),
                    'last_restore' => $this->model->whereNotNull('last_restored_at')
                        ->orderBy('last_restored_at', 'desc')
                        ->first(),
                ];
            }
        );
    }

    public function delete($id): bool
    {
        DB::beginTransaction();
        try {
            $backup = $this->findOrFail($id);

            // Delete backup files
            $this->backupManager->delete($backup);

            // Delete record
            $deleted = $backup->delete();

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new BackupDeleted($backup));

            DB::commit();
            return $deleted;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
