<?php

namespace App\Core\Backup;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Storage\StorageManagerInterface;
use App\Core\Exception\BackupException;
use Psr\Log\LoggerInterface;

class BackupService implements BackupServiceInterface
{
    private SecurityManagerInterface $security;
    private StorageManagerInterface $storage;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        StorageManagerInterface $storage,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->storage = $storage;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function createBackup(string $type, array $context = []): string
    {
        $backupId = $this->generateBackupId();
        
        try {
            // Validate security context
            $this->security->validateOperation('backup:create', $type);

            // Begin backup process
            $this->logger->info('Starting backup process', [
                'backup_id' => $backupId,
                'type' => $type
            ]);

            // Create backup
            $data = $this->collectBackupData($type);
            $encrypted = $this->encryptBackupData($data);
            
            // Store backup
            $this->storage->store("backups/{$backupId}", [
                'data' => $encrypted,
                'metadata' => $this->createBackupMetadata($type, $context),
                'checksum' => $this->calculateChecksum($encrypted)
            ]);

            // Verify backup
            $this->verifyBackup($backupId);

            $this->logger->info('Backup completed successfully', [
                'backup_id' => $backupId
            ]);

            return $backupId;

        } catch (\Exception $e) {
            $this->logger->error('Backup failed', [
                'backup_id' => $backupId,
                'error' => $e->getMessage()
            ]);
            throw new BackupException('Backup creation failed', 0, $e);
        }
    }

    public function restoreBackup(string $backupId): void
    {
        try {
            // Validate security
            $this->security->validateOperation('backup:restore', $backupId);

            // Verify backup integrity
            $this->verifyBackup($backupId);

            // Begin restoration
            $this->logger->info('Starting backup restoration', [
                'backup_id' => $backupId
            ]);

            // Get backup data
            $backup = $this->storage->get("backups/{$backupId}");
            if (!$backup) {
                throw new BackupException('Backup not found');
            }

            // Decrypt and restore
            $data = $this->decryptBackupData($backup['data']);
            $this->performRestore($data, $backup['metadata']);

            $this->logger->info('Backup restored successfully', [
                'backup_id' => $backupId
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Backup restoration failed', [
                'backup_id' => $backupId,
                'error' => $e->getMessage()
            ]);
            throw new BackupException('Backup restoration failed', 0, $e);
        }
    }

    public function verifyBackup(string $backupId): bool
    {
        try {
            $backup = $this->storage->get("backups/{$backupId}");
            if (!$backup) {
                throw new BackupException('Backup not found');
            }

            // Verify checksum
            $currentChecksum = $this->calculateChecksum($backup['data']);
            if ($currentChecksum !== $backup['checksum']) {
                throw new BackupException('Backup integrity check failed');
            }

            // Verify encryption
            if (!$this->verifyEncryption($backup['data'])) {
                throw new BackupException('Backup encryption verification failed');
            }

            return true;

        } catch (\Exception $e) {
            $this->logger->error('Backup verification failed', [
                'backup_id' => $backupId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function generateBackupId(): string
    {
        return uniqid('backup_', true);
    }

    private function collectBackupData(string $type): array
    {
        return match($type) {
            'full' => $this->collectFullBackup(),
            'incremental' => $this->collectIncrementalBackup(),
            'differential' => $this->collectDifferentialBackup(),
            default => throw new BackupException('Invalid backup type')
        };
    }

    private function encryptBackupData(array $data): string
    {
        return $this->security->encrypt(
            json_encode($data),
            $this->config['encryption_key']
        );
    }

    private function decryptBackupData(string $encrypted): array
    {
        $decrypted = $this->security->decrypt(
            $encrypted,
            $this->config['encryption_key']
        );
        return json_decode($decrypted, true);
    }

    private function createBackupMetadata(string $type, array $context): array
    {
        return [
            'type' => $type,
            'created_at' => time(),
            'created_by' => $this->security->getCurrentUserId(),
            'context' => $context,
            'version' => $this->config['version']
        ];
    }

    private function calculateChecksum(string $data): string
    {
        return hash('sha256', $data);
    }

    private function verifyEncryption(string $data): bool
    {
        try {
            $this->decryptBackupData($data);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function performRestore(array $data, array $metadata): void
    {
        // Implementation specific to backup type
        match($metadata['type']) {
            'full' => $this->performFullRestore($data),
            'incremental' => $this->performIncrementalRestore($data),
            'differential' => $this->performDifferentialRestore($data),
            default => throw new BackupException('Invalid backup type')
        };
    }

    private function getDefaultConfig(): array
    {
        return [
            'version' => '1.0',
            'encryption_key' => env('BACKUP_ENCRYPTION_KEY'),
            'storage_path' => storage_path('backups'),
            'compression' => true,
            'retention_days' => 30
        ];
    }
}
