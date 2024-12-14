<?php

namespace App\Core\Backup;

use Illuminate\Support\Facades\{DB, Storage, Log};
use App\Core\Security\SecurityManager;
use App\Core\Monitoring\MonitoringService;

class BackupManager implements BackupInterface
{
    protected SecurityManager $security;
    protected MonitoringService $monitor;
    protected BackupRepository $repository;
    protected array $config;

    public function __construct(
        SecurityManager $security,
        MonitoringService $monitor,
        BackupRepository $repository,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->repository = $repository;
        $this->config = $config;
    }

    public function createBackup(string $type = 'full'): BackupEntity
    {
        return $this->security->executeCriticalOperation(function() use ($type) {
            return DB::transaction(function() use ($type) {
                $this->validateBackupType($type);
                $this->ensureStorageSpace();
                
                $backup = $this->repository->create([
                    'type' => $type,
                    'status' => 'in_progress',
                    'started_at' => now()
                ]);

                try {
                    $this->performBackup($backup);
                    $this->verifyBackup($backup);
                    $this->updateBackupStatus($backup, 'completed');
                    
                } catch (\Exception $e) {
                    $this->handleBackupFailure($backup, $e);
                    throw $e;
                }

                $this->cleanupOldBackups();
                return $backup;
            });
        });
    }

    public function restore(int $backupId): bool
    {
        return $this->security->executeCriticalOperation(function() use ($backupId) {
            return DB::transaction(function() use ($backupId) {
                $backup = $this->repository->findOrFail($backupId);
                $this->validateBackupForRestore($backup);
                
                $this->monitor->startSystemRestore($backup);
                
                try {
                    $this->performRestore($backup);
                    $this->verifyRestore($backup);
                    $this->monitor->completeSystemRestore($backup);
                    
                    return true;
                    
                } catch (\Exception $e) {
                    $this->handleRestoreFailure($backup, $e);
                    throw $e;
                }
            });
        });
    }

    public function verify(int $backupId): bool
    {
        return $this->security->executeCriticalOperation(function() use ($backupId) {
            $backup = $this->repository->findOrFail($backupId);
            
            return $this->verifyBackupIntegrity($backup) && 
                   $this->verifyBackupCompleteness($backup);
        });
    }

    protected function performBackup(BackupEntity $backup): void
    {
        $path = $this->getBackupPath($backup);
        
        match ($backup->type) {
            'full' => $this->performFullBackup($path),
            'incremental' => $this->performIncrementalBackup($path, $backup),
            'differential' => $this->performDifferentialBackup($path, $backup)
        };
    }

    protected function performFullBackup(string $path): void
    {
        // Database backup
        $this->backupDatabase($path . '/database');
        
        // File system backup
        $this->backupFileSystem($path . '/files');
        
        // Configuration backup
        $this->backupConfiguration($path . '/config');
    }

    protected function performIncrementalBackup(string $path, BackupEntity $backup): void
    {
        $lastBackup = $this->repository->getLastSuccessfulBackup('incremental');
        $changedFiles = $this->getChangedFiles($lastBackup);
        
        foreach ($changedFiles as $file) {
            $this->backupFile($file, $path);
        }
    }

    protected function verifyBackup(BackupEntity $backup): void
    {
        if (!$this->verifyBackupIntegrity($backup)) {
            throw new BackupVerificationException('Backup integrity check failed');
        }

        if (!$this->verifyBackupCompleteness($backup)) {
            throw new BackupVerificationException('Backup completeness check failed');
        }
    }

    protected function verifyBackupIntegrity(BackupEntity $backup): bool
    {
        $path = $this->getBackupPath($backup);
        $manifest = $this->loadBackupManifest($path);
        
        foreach ($manifest['files'] as $file => $hash) {
            if (!$this->verifyFileHash($path . '/' . $file, $hash)) {
                return false;
            }
        }

        return true;
    }

    protected function verifyBackupCompleteness(BackupEntity $backup): bool
    {
        $path = $this->getBackupPath($backup);
        $manifest = $this->loadBackupManifest($path);
        
        return $this->verifyDatabaseBackup($path . '/database') &&
               $this->verifyFileSystemBackup($path . '/files', $manifest) &&
               $this->verifyConfigurationBackup($path . '/config');
    }

    protected function cleanupOldBackups(): void
    {
        $backups = $this->repository->getOldBackups(
            $this->config['retention_period'],
            $this->config['max_backups']
        );

        foreach ($backups as $backup) {
            $this->deleteBackup($backup);
        }
    }

    protected function deleteBackup(BackupEntity $backup): void
    {
        $path = $this->getBackupPath($backup);
        Storage::deleteDirectory($path);
        $this->repository->delete($backup->id);
    }

    protected function handleBackupFailure(BackupEntity $backup, \Exception $e): void
    {
        $this->updateBackupStatus($backup, 'failed');
        
        Log::error('Backup failed', [
            'backup_id' => $backup->id,
            'type' => $backup->type,
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->monitor->reportBackupFailure($backup, $e);
    }

    protected function handleRestoreFailure(BackupEntity $backup, \Exception $e): void
    {
        Log::error('Restore failed', [
            'backup_id' => $backup->id,
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->monitor->reportRestoreFailure($backup, $e);
    }

    protected function validateBackupType(string $type): void
    {
        if (!in_array($type, ['full', 'incremental', 'differential'])) {
            throw new InvalidBackupTypeException("Invalid backup type: {$type}");
        }
    }

    protected function validateBackupForRestore(BackupEntity $backup): void
    {
        if ($backup->status !== 'completed') {
            throw new InvalidBackupException('Cannot restore incomplete backup');
        }

        if (!$this->verifyBackupIntegrity($backup)) {
            throw new InvalidBackupException('Backup integrity check failed');
        }
    }

    protected function getBackupPath(BackupEntity $backup): string
    {
        return "backups/{$backup->type}/{$backup->id}";
    }
}
