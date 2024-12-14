<?php

namespace App\Core\Backup;

class BackupService
{
    private BackupStrategy $strategy;
    private StorageProvider $storage;
    private EncryptionService $encryption;
    private CompressionService $compression;
    private BackupLogger $logger;

    public function __construct(
        BackupStrategy $strategy,
        StorageProvider $storage,
        EncryptionService $encryption,
        CompressionService $compression,
        BackupLogger $logger
    ) {
        $this->strategy = $strategy;
        $this->storage = $storage;
        $this->encryption = $encryption;
        $this->compression = $compression;
        $this->logger = $logger;
    }

    public function createBackup(BackupConfig $config): BackupResult
    {
        $startTime = microtime(true);

        try {
            // Collect data to backup
            $data = $this->strategy->collectData($config);

            // Compress data
            $compressed = $this->compression->compress($data);

            // Encrypt data
            $encrypted = $this->encryption->encrypt($compressed);

            // Store backup
            $backupId = $this->storage->store($encrypted, $config);

            $duration = microtime(true) - $startTime;
            $this->logger->logSuccess($backupId, $duration);

            return new BackupResult(true, $backupId);
        } catch (\Exception $e) {
            $this->logger->logError($e);
            throw new BackupException('Backup failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function restore(string $backupId, RestoreConfig $config): RestoreResult
    {
        $startTime = microtime(true);

        try {
            // Retrieve backup
            $encrypted = $this->storage->retrieve($backupId);

            // Decrypt data
            $compressed = $this->encryption->decrypt($encrypted);

            // Decompress data
            $data = $this->compression->decompress($compressed);

            // Restore data
            $this->strategy->restoreData($data, $config);

            $duration = microtime(true) - $startTime;
            $this->logger->logRestoreSuccess($backupId, $duration);

            return new RestoreResult(true);
        } catch (\Exception $e) {
            $this->logger->logRestoreError($backupId, $e);
            throw new RestoreException('Restore failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function listBackups(): array
    {
        return $this->storage->list();
    }

    public function getBackupInfo(string $backupId): BackupInfo
    {
        return $this->storage->getInfo($backupId);
    }

    public function deleteBackup(string $backupId): void
    {
        $this->storage->delete($backupId);
        $this->logger->logDeletion($backupId);
    }

    public function validateBackup(string $backupId): ValidationResult
    {
        try {
            $encrypted = $this->storage->retrieve($backupId);
            $compressed = $this->encryption->decrypt($encrypted);
            $data = $this->compression->decompress($compressed);

            return $this->strategy->validateData($data);
        } catch (\Exception $e) {
            $this->logger->logValidationError($backupId, $e);
            return new ValidationResult(false, $e->getMessage());
        }
    }
}

class BackupLogger
{
    private LoggerInterface $logger;

    public function logSuccess(string $backupId, float $duration): void
    {
        $this->logger->info('Backup completed successfully', [
            'backup_id' => $backupId,
            'duration' => $duration,
            'timestamp' => time()
        ]);
    }

    public function logError(\Exception $e): void
    {
        $this->logger->error('Backup failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => time()
        ]);
    }

    public function logRestoreSuccess(string $backupId, float $duration): void
    {
        $this->logger->info('Restore completed successfully', [
            'backup_id' => $backupId,
            'duration' => $duration,
            'timestamp' => time()
        ]);
    }

    public function logRestoreError(string $backupId, \Exception $e): void
    {
        $this->logger->error('Restore failed', [
            'backup_id' => $backupId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => time()
        ]);
    }

    public function logDeletion(string $backupId): void
    {
        $this->logger->info('Backup deleted', [
            'backup_id' => $backupId,
            'timestamp' => time()
        ]);
    }

    public function logValidationError(string $backupId, \Exception $e): void
    {
        $this->logger->error('Backup validation failed', [
            'backup_id' => $backupId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => time()
        ]);
    }
}

interface BackupStrategy
{
    public function collectData(BackupConfig $config): array;
    public function restoreData(array $data, RestoreConfig $config): void;
    public function validateData(array $data): ValidationResult;
}

interface StorageProvider
{
    public function store(string $data, BackupConfig $config): string;
    public function retrieve(string $backupId): string;
    public function delete(string $backupId): void;
    public function list(): array;
    public function getInfo(string $backupId): BackupInfo;
}

class ValidationResult
{
    private bool $valid;
    private ?string $error;

    public function __construct(bool $valid, ?string $error = null)
    {
        $this->valid = $valid;
        $this->error = $error;
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function getError(): ?string
    {
        return $this->error;
    }
}

class BackupResult
{
    private bool $success;
    private string $backupId;

    public function __construct(bool $success, string $backupId)
    {
        $this->success = $success;
        $this->backupId = $backupId;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getBackupId(): string
    {
        return $this->backupId;
    }
}
