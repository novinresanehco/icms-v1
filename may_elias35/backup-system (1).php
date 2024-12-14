<?php

namespace App\Core\Backup;

use App\Core\Interfaces\BackupInterface;
use App\Core\Exceptions\{BackupException, SecurityException};
use Illuminate\Support\Facades\{DB, Storage, Log};

class BackupManager implements BackupInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private IntegrityManager $integrity;
    private array $backupConfig;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        IntegrityManager $integrity,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->integrity = $integrity;
        $this->backupConfig = $config['backup'];
    }

    public function createBackup(string $type = 'full'): string
    {
        $backupId = $this->generateBackupId();
        
        try {
            DB::beginTransaction();

            // Lock system for backup
            $this->security->lockSystemForBackup();
            
            // Create snapshot
            $snapshot = $this->createSystemSnapshot();
            
            // Validate snapshot
            $this->validateSnapshot($snapshot);
            
            // Encrypt backup
            $encryptedBackup = $this->security->encryptBackup($snapshot);
            
            // Store with integrity checks
            $this->storeBackup($backupId, $encryptedBackup);
            
            // Verify stored backup
            $this->verifyStoredBackup($backupId);
            
            DB::commit();
            
            return $backupId;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleBackupFailure($e, $backupId);
            throw new BackupException('Backup creation failed: ' . $e->getMessage(), $e);
        } finally {
            $this->security->unlockSystemForBackup();
        }
    }

    protected function createSystemSnapshot(): array
    {
        return [
            'system_state' => $this->captureSystemState(),
            'database' => $this->captureDatabaseState(),
            'files' => $this->captureFileState(),
            'configuration' => $this->captureConfiguration(),
            'metadata' => [
                'timestamp' => microtime(true),
                'version' => $this->getSystemVersion(),
                'checksum' => $this->calculateSystemChecksum()
            ]
        ];
    }

    protected function validateSnapshot(array $snapshot): void
    {
        if (!$this->validator->validateSnapshot($snapshot)) {
            throw new BackupException('Invalid system snapshot');
        }

        if (!$this->integrity->verifySnapshotIntegrity($snapshot)) {
            throw new SecurityException('Snapshot integrity verification failed');
        }
    }

    protected function storeBackup(string $backupId, string $encryptedBackup): void
    {
        $path = $this->getBackupPath($backupId);
        Storage::put($path, $encryptedBackup);
        
        $this->storeBackupMetadata($backupId, [
            'size' => strlen($encryptedBackup),
            'checksum' => hash('sha256', $encryptedBackup),
            'timestamp' => microtime(true)
        ]);
    }

    protected function verifyStoredBackup(string $backupId): void
    {
        $storedBackup = Storage::get($this->getBackupPath($backupId));
        $metadata = $this->getBackupMetadata($backupId);
        
        if (!$this->verifyBackupIntegrity($storedBackup, $metadata)) {
            throw new SecurityException('Stored backup verification failed');
        }
    }

    protected function verifyBackupIntegrity(string $backup, array $metadata): bool
    {
        return hash('sha256', $backup) === $metadata['checksum'] &&
               strlen($backup) === $metadata['size'];
    }

    protected function storeBackupMetadata(string $backupId, array $metadata): void
    {
        DB::table('backup_metadata')->insert([
            'backup_id' => $backupId,
            'metadata' => json_encode($metadata),
            'created_at' => now()
        ]);
    }

    protected function getBackupMetadata(string $backupId): array
    {
        $metadata = DB::table('backup_metadata')
            ->where('backup_id', $backupId)
            ->first();
            
        return json_decode($metadata->metadata, true);
    }

    protected function handleBackupFailure(\Exception $e, string $backupId): void
    {
        Log::critical('Backup creation failed', [
            'backup_id' => $backupId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->cleanupFailedBackup($backupId);
    }

    protected function cleanupFailedBackup(string $backupId): void
    {
        Storage::delete($this->getBackupPath($backupId));
        DB::table('backup_metadata')->where('backup_id', $backupId)->delete();
    }

    protected function getBackupPath(string $backupId): string
    {
        return "backups/{$backupId}.enc";
    }

    protected function generateBackupId(): string
    {
        return uniqid('backup:', true);
    }
}
