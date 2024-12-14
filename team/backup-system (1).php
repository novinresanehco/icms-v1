<?php

namespace App\Core\Backup;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Storage\StorageManager;
use App\Core\Events\BackupEvent;
use App\Core\Exceptions\{BackupException, RecoveryException};
use Illuminate\Support\Facades\{DB, File};

class BackupManager implements BackupInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private StorageManager $storage;
    private array $config;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        StorageManager $storage,
        array $config = []
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->storage = $storage;
        $this->config = array_merge([
            'backup_path' => 'backups',
            'max_backups' => 10,
            'compression' => true,
            'encryption' => true,
            'verification' => true
        ], $config);
    }

    public function createBackup(array $options = []): BackupResult
    {
        return $this->security->executeCriticalOperation(
            function() use ($options) {
                DB::beginTransaction();
                try {
                    // Create backup identifier
                    $backupId = $this->generateBackupId();
                    
                    // Create backup metadata
                    $metadata = $this->createBackupMetadata($backupId, $options);
                    
                    // Backup database
                    $dbBackup = $this->backupDatabase($backupId);
                    
                    // Backup files
                    $fileBackup = $this->backupFiles($backupId);
                    
                    // Verify backup integrity
                    if ($this->config['verification']) {
                        $this->verifyBackup($backupId, $dbBackup, $fileBackup);
                    }
                    
                    // Store metadata
                    $this->storeBackupMetadata($metadata);
                    
                    // Cleanup old backups
                    $this->cleanupOldBackups();
                    
                    event(new BackupEvent('created', $backupId));
                    
                    DB::commit();
                    
                    return new BackupResult($backupId, $metadata);
                    
                } catch (\Exception $e) {
                    DB::rollBack();
                    $this->cleanupFailedBackup($backupId ?? null);
                    throw new BackupException('Backup creation failed: ' . $e->getMessage());
                }
            },
            ['operation' => 'create_backup']
        );
    }

    public function restore(string $backupId, array $options = []): RestoreResult
    {
        return $this->security->executeCriticalOperation(
            function() use ($backupId, $options) {
                // Validate backup
                $metadata = $this->getBackupMetadata($backupId);
                $this->validateBackup($backupId, $metadata);
                
                // Create restore point
                $restorePoint = $this->createRestorePoint();
                
                try {
                    // Restore database
                    $this->restoreDatabase($backupId, $metadata);
                    
                    // Restore files
                    $this->restoreFiles($backupId, $metadata);
                    
                    // Verify restoration
                    $this->verifyRestoration($backupId, $metadata);
                    
                    event(new BackupEvent('restored', $backupId));
                    
                    return new RestoreResult($backupId, $metadata);
                    
                } catch (\Exception $e) {
                    $this->rollbackToRestorePoint($restorePoint);
                    throw new RecoveryException('Restoration failed: ' . $e->getMessage());
                }
            },
            ['operation' => 'restore_backup']
        );
    }

    public function verify(string $backupId): VerificationResult
    {
        return $this->security->executeCriticalOperation(
            function() use ($backupId) {
                $metadata = $this->getBackupMetadata($backupId);
                
                $results = [
                    'metadata' => $this->verifyMetadata($backupId, $metadata),
                    'database' => $this->verifyDatabaseBackup($backupId, $metadata),
                    'files' => $this->verifyFileBackup($backupId, $metadata)
                ];
                
                return new VerificationResult($backupId, $results);
            },
            ['operation' => 'verify_backup']
        );
    }

    protected function generateBackupId(): string
    {
        return date('Y-m-d_H-i-s') . '_' . bin2hex(random_bytes(8));
    }

    protected function createBackupMetadata(string $backupId, array $options): array
    {
        return [
            'id' => $backupId,
            'timestamp' => time(),
            'options' => $options,
            'database' => $this->getDatabaseMetadata(),
            'files' => $this->getFilesMetadata(),
            'hash' => null // Will be set after backup completion
        ];
    }

    protected function backupDatabase(string $backupId): string
    {
        $filename = "{$backupId}_database.sql";
        $path = $this->config['backup_path'] . '/' . $filename;
        
        // Create database dump
        $dump = $this->createDatabaseDump();
        
        // Compress if enabled
        if ($this->config['compression']) {
            $dump = gzencode($dump, 9);
            $path .= '.gz';
        }
        
        // Encrypt if enabled
        if ($this->config['encryption']) {
            $dump = $this->security->encrypt($dump);
            $path .= '.enc';
        }
        
        // Store backup file
        $this->storage->put($path, $dump);
        
        return $path;
    }

    protected function backupFiles(string $backupId): string
    {
        $filename = "{$backupId}_files.zip";
        $path = $this->config['backup_path'] . '/' . $filename;
        
        // Create files archive
        $archive = $this->createFilesArchive();
        
        // Encrypt if enabled
        if ($this->config['encryption']) {
            $archive = $this->security->encrypt($archive);
            $path .= '.enc';
        }
        
        // Store backup file
        $this->storage->put($path, $archive);
        
        return $path;
    }

    protected function verifyBackup(string $backupId, string $dbBackup, string $fileBackup): void
    {
        // Verify database backup integrity
        if (!$this->storage->exists($dbBackup)) {
            throw new BackupException('Database backup file missing');
        }
        
        // Verify files backup integrity
        if (!$this->storage->exists($fileBackup)) {
            throw new BackupException('Files backup archive missing');
        }
        
        // Verify backup contents
        $this->verifyBackupContents($backupId, $dbBackup, $fileBackup);
    }

    protected function cleanupOldBackups(): void
    {
        $backups = $this->storage->files($this->config['backup_path']);
        $count = count($backups);
        
        if ($count > $this->config['max_backups']) {
            $toDelete = array_slice($backups, 0, $count - $this->config['max_backups']);
            foreach ($toDelete as $backup) {
                $this->storage->delete($backup);
            }
        }
    }

    protected function cleanupFailedBackup(?string $backupId): void
    {
        if ($backupId) {
            $pattern = $this->config['backup_path'] . '/' . $backupId . '_*';
            foreach ($this->storage->files($pattern) as $file) {
                $this->storage->delete($file);
            }
        }
    }

    protected function validateBackup(string $backupId, array $metadata): void
    {
        if (!$this->verifyMetadata($backupId, $metadata)) {
            throw new BackupException('Invalid backup metadata');
        }
        
        if (!$this->verifyBackupFiles($backupId)) {
            throw new BackupException('Backup files verification failed');
        }
    }

    protected function createRestorePoint(): string
    {
        $restorePoint = $this->generateBackupId() . '_restore_point';
        
        // Backup current state
        $this->backupDatabase($restorePoint);
        $this->backupFiles($restorePoint);
        
        return $restorePoint;
    }

    protected function rollbackToRestorePoint(string $restorePoint): void
    {
        try {
            $this->restore($restorePoint, ['validate' => false]);
        } catch (\Exception $e) {
            // Log rollback failure
            throw new RecoveryException('Rollback to restore point failed: ' . $e->getMessage());
        }
    }
}
