<?php

namespace App\Core\Backup;

use App\Core\Storage\StorageManager;
use App\Core\Security\SecurityManager;
use App\Core\Monitoring\SystemMonitor;

class BackupManager implements BackupInterface
{
    private StorageManager $storage;
    private SecurityManager $security;
    private SystemMonitor $monitor;
    private array $config;

    public function __construct(
        StorageManager $storage,
        SecurityManager $security,
        SystemMonitor $monitor,
        array $config
    ) {
        $this->storage = $storage;
        $this->security = $security;
        $this->monitor = $monitor;
        $this->config = $config;
    }

    public function createBackup(string $type = 'full'): Backup
    {
        $monitoringId = $this->monitor->startOperation('backup_create');
        
        try {
            $this->validateBackupType($type);
            $this->validateStorageSpace();
            
            $backup = new Backup([
                'type' => $type,
                'status' => 'processing',
                'started_at' => now()
            ]);

            $path = $this->performBackup($backup);
            
            $backup->path = $path;
            $backup->size = filesize($path);
            $backup->checksum = hash_file('sha256', $path);
            $backup->completed_at = now();
            $backup->status = 'completed';
            $backup->save();

            $this->verifyBackup($backup);
            $this->rotateBackups($type);
            
            $this->monitor->recordSuccess($monitoringId);
            
            return $backup;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new BackupException('Backup creation failed', 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function restoreBackup(int $id): bool
    {
        $monitoringId = $this->monitor->startOperation('backup_restore');
        
        try {
            $backup = Backup::findOrFail($id);
            
            $this->validateBackup($backup);
            $this->createRestorePoint();
            
            $success = $this->performRestore($backup);
            
            if ($success) {
                $this->verifyRestore($backup);
                $this->monitor->recordSuccess($monitoringId);
            }
            
            return $success;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new BackupException('Backup restoration failed', 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function validateBackupType(string $type): void
    {
        if (!in_array($type, ['full', 'incremental', 'differential'])) {
            throw new BackupValidationException('Invalid backup type');
        }
    }

    private function validateStorageSpace(): void
    {
        $freeSpace = disk_free_space($this->config['backup_path']);
        $requiredSpace = $this->estimateBackupSize();
        
        if ($freeSpace < $requiredSpace * 1.2) {
            throw new BackupStorageException('Insufficient storage space');
        }
    }

    private function performBackup(Backup $backup): string
    {
        $path = $this->getBackupPath($backup);
        
        switch ($backup->type) {
            case 'full':
                return $this->performFullBackup($path);
            case 'incremental':
                return $this->performIncrementalBackup($path);
            case 'differential':
                return $this->performDifferentialBackup($path);
            default:
                throw new BackupException('Unknown backup type');
        }
    }

    private function verifyBackup(Backup $backup): void
    {
        if (!file_exists($backup->path)) {
            throw new BackupVerificationException('Backup file not found');
        }

        if (filesize($backup->path) === 0) {
            throw new BackupVerificationException('Empty backup file');
        }

        $checksum = hash_file('sha256', $backup->path);
        if ($checksum !== $backup->checksum) {
            throw new BackupVerificationException('Backup checksum mismatch');
        }
    }

    private function rotateBackups(string $type): void
    {
        $backups = Backup::where('type', $type)
                        ->orderBy('created_at', 'desc')
                        ->get();

        $maxBackups = $this->config['max_backups'][$type];
        
        foreach ($backups->slice($maxBackups) as $backup) {
            $this->storage->delete($backup->path);
            $backup->delete();
        }
    }

    private function createRestorePoint(): void
    {
        $path = $this->config['restore_point_path'] . '/' . time();
        
        $this->storage->copy(
            $this->config['data_path'],
            $path
        );
    }

    private function verifyRestore(Backup $backup): void
    {
        // Verify data integrity
        if (!$this->verifyDataIntegrity()) {
            throw new BackupVerificationException('Data integrity check failed');
        }

        // Verify system functionality
        if (!$this->verifySystemFunctionality()) {
            throw new BackupVerificationException('System functionality check failed');
        }

        // Verify security context
        if (!$this->security->verifySystemSecurity()) {
            throw new BackupVerificationException('Security verification failed');
        }
    }
}
