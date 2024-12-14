<?php

namespace App\Core\Repository;

use App\Models\Backup;
use App\Core\Events\BackupEvents;
use App\Core\Exceptions\BackupRepositoryException;
use Illuminate\Support\Facades\Storage;

class BackupRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Backup::class;
    }

    /**
     * Create new backup
     */
    public function createBackup(array $data): Backup
    {
        try {
            DB::beginTransaction();

            // Create backup record
            $backup = $this->create([
                'name' => $data['name'],
                'type' => $data['type'],
                'size' => 0,
                'status' => 'pending',
                'created_by' => auth()->id()
            ]);

            // Generate backup files
            $files = $this->generateBackupFiles($backup, $data);

            // Update backup size
            $totalSize = collect($files)->sum('size');
            $backup->update([
                'size' => $totalSize,
                'status' => 'completed',
                'completed_at' => now()
            ]);

            DB::commit();
            event(new BackupEvents\BackupCreated($backup));

            return $backup;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new BackupRepositoryException(
                "Failed to create backup: {$e->getMessage()}"
            );
        }
    }

    /**
     * Restore from backup
     */
    public function restore(int $backupId): void
    {
        try {
            $backup = $this->find($backupId);
            if (!$backup) {
                throw new BackupRepositoryException("Backup not found with ID: {$backupId}");
            }

            DB::beginTransaction();

            // Update backup status
            $backup->update(['status' => 'restoring']);

            // Perform restore operations
            $this->restoreFromFiles($backup);

            // Update backup status
            $backup->update([
                'status' => 'restored',
                'restored_at' => now(),
                'restored_by' => auth()->id()
            ]);

            DB::commit();
            event(new BackupEvents\BackupRestored($backup));
        } catch (\Exception $e) {
            DB::rollBack();
            throw new BackupRepositoryException(
                "Failed to restore backup: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get available backups
     */
    public function getAvailableBackups(): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('available'),
            $this->cacheTime,
            fn() => $this->model->where('status', 'completed')
                               ->latest()
                               ->get()
        );
    }

    /**
     * Clean old backups
     */
    public function cleanOldBackups(int $keepLast = 5): void
    {
        try {
            $oldBackups = $this->model->where('status', 'completed')
                                    ->orderByDesc('created_at')
                                    ->skip($keepLast)
                                    ->get();

            foreach ($oldBackups as $backup) {
                // Delete backup files
                $this->deleteBackupFiles($backup);
                
                // Delete backup record
                $backup->delete();
            }
        } catch (\Exception $e) {
            throw new BackupRepositoryException(
                "Failed to clean old backups: {$e->getMessage()}"
            );
        }
    }

    /**
     * Delete backup files
     */
    protected function deleteBackupFiles(Backup $backup): void
    {
        $disk = Storage::disk('backups');
        $path = "backup_{$backup->id}/";
        
        if ($disk->exists($path)) {
            $disk->deleteDirectory($path);
        }
    }
}
