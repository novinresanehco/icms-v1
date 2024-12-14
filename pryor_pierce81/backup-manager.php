<?php

namespace App\Core\Backup;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\BackupException;
use Psr\Log\LoggerInterface;

class BackupManager implements BackupManagerInterface
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $backups = [];
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function createBackup(string $type): string
    {
        $backupId = $this->generateBackupId();

        try {
            DB::beginTransaction();

            $this->security->validateContext('backup:create');
            $this->validateBackupType($type);

            $monitoringId = $this->monitor->startOperation([
                'type' => 'backup_creation',
                'backup_type' => $type
            ]);

            $backup = $this->executeBackup($type, $backupId);
            $this->verifyBackup($backup);

            $this->logBackupCreation($backupId, $type, $backup);
            $this->monitor->stopOperation($monitoringId);

            DB::commit();
            return $backupId;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleBackupFailure($backupId, $type, 'creation', $e);
            throw new BackupException("Backup creation failed: {$type}", 0, $e);
        }
    }

    public function restoreBackup(string $backupId): bool
    {
        try {
            DB::beginTransaction();

            $this->security->validateContext('backup:restore');
            $this->validateBackupExists($backupId);

            $monitoringId = $this->monitor->startOperation([
                'type' => 'backup_restoration',
                'backup_id' => $backupId
            ]);

            $success = $this->executeRestore($backupId);
            $this->verifyRestoration($backupId);

            $this->logBackupRestoration($backupId, $success);
            $this->monitor->stopOperation($monitoringId);

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleBackupFailure($backupId, null, 'restoration', $e);
            throw new BackupException("Backup restoration failed: {$backupId}", 0, $e);
        }
    }

    private function executeBackup(string $type, string $backupId): array
    {
        $backup = [
            'id' => $backupId,
            'type' => $type,
            'created_at' => now(),
            'data' => []
        ];

        switch ($type) {
            case 'database':
                $backup['data'] = $this->createDatabaseBackup();
                break;
            case 'files':
                $backup['data'] = $this->createFileBackup();
                break;
            case 'complete':
                $backup['data'] = $this->createCompleteBackup();
                break;
            default:
                throw new BackupException("Unsupported backup type: {$type}");
        }

        $this->encryptBackup($backup);
        $this->storeBackup($backup);

        return $backup;
    }

    private function executeRestore(string $backupId): bool
    {
        $backup = $this->loadBackup($backupId);
        $this->decryptBackup($backup);

        switch ($backup['type']) {
            case 'database':
                return $this->restoreDatabase($backup['data']);
            case 'files':
                return $this->restoreFiles($backup['data']);
            case 'complete':
                return $this->restoreComplete($backup['data']);
            default:
                throw new BackupException("Unsupported backup type: {$backup['type']}");
        }
    }

    private function createDatabaseBackup(): array
    {
        // Create database dump
        $dump = DB::dump();
        
        // Compress dump
        $compressed = $this->compressData($dump);
        
        return [
            'dump' => $compressed,
            'metadata' => [
                'tables' => DB::getTables(),
                'size' => strlen($dump),
                'compressed_size' => strlen($compressed)
            ]
        ];
    }

    private function createFileBackup(): array
    {
        $files = [];
        $directories = $this->config['backup_directories'];

        foreach ($directories as $dir) {
            $files = array_merge($files, $this->backupDirectory($dir));
        }

        return [
            'files' => $files,
            'metadata' => [
                'total_files' => count($files),
                'total_size' => array_sum(array_column($files, 'size'))
            ]
        ];
    }

    private function encryptBackup(array &$backup): void
    {
        $backup['data'] = $this->security->encrypt(serialize($backup['data']));
        $backup['checksum'] = hash('sha256', $backup['data']);
    }

    private function decryptBackup(array &$backup): void
    {
        if (hash('sha256', $backup['data']) !== $backup['checksum']) {
            throw new BackupException("Backup data integrity check failed");
        }

        $backup['data'] = unserialize(
            $this->security->decrypt($backup['data'])
        );
    }

    private function storeBackup(array $backup): void
    {
        $path = $this->config['backup_path'] . '/' . $backup['id'];
        
        if (!file_put_contents($path, serialize($backup))) {
            throw new BackupException("Failed to store backup");
        }
    }

    private function loadBackup(string $backupId): array
    {
        $path = $this->config['backup_path'] . '/' . $backupId;
        
        if (!file_exists($path)) {
            throw new BackupException("Backup not found: {$backupId}");
        }

        $backup = unserialize(file_get_contents($path));
        
        if (!$backup) {
            throw new BackupException("Failed to load backup");
        }

        return $backup;
    }

    private function validateBackupType(string $type): void
    {
        if (!in_array($type, $this->config['allowed_types'])) {
            throw new BackupException("Invalid backup type: {$type}");
        }
    }

    private function validateBackupExists(string $backupId): void
    {
        $path = $this->config['backup_path'] . '/' . $backupId;
        
        if (!file_exists($path)) {
            throw new BackupException("Backup not found: {$backupId}");
        }
    }

    private function verifyBackup(array $backup): void
    {
        if (!isset($backup['data']) || !isset($backup['checksum'])) {
            throw new BackupException("Invalid backup structure");
        }

        if (hash('sha256', $backup['data']) !== $backup['checksum']) {
            throw new BackupException("Backup verification failed");
        }
    }

    private function verifyRestoration(string $backupId): void
    {
        // Verify database integrity
        if (!DB::verifyIntegrity()) {
            throw new BackupException("Database integrity check failed");
        }

        // Verify file integrity
        if (!$this->verifyFileIntegrity()) {
            throw new BackupException("File integrity check failed");
        }
    }

    private function generateBackupId(): string
    {
        return uniqid('bkp_', true);
    }

    private function logBackupCreation(
        string $backupId,
        string $type,
        array $backup
    ): void {
        $this->logger->info('Backup created', [
            'backup_id' => $backupId,
            'type' => $type,
            'size' => strlen($backup['data']),
            'timestamp' => microtime(true)
        ]);
    }

    private function logBackupRestoration(string $backupId, bool $success): void
    {
        $this->logger->info('Backup restored', [
            'backup_id' => $backupId,
            'success' => $success,
            'timestamp' => microtime(true)
        ]);
    }

    private function handleBackupFailure(
        string $backupId,
        ?string $type,
        string $operation,
        \Exception $e
    ): void {
        $this->logger->error('Backup operation failed', [
            'backup_id' => $backupId,
            'type' => $type,
            'operation' => $operation,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function getDefaultConfig(): array
    {
        return [
            'backup_path' => storage_path('backups'),
            'allowed_types' => ['database', 'files', 'complete'],
            'backup_directories' => [
                storage_path('app'),
                public_path('uploads')
            ],
            'compression' => true,
            'encryption' => true,
            'retention_days' => 30
        ];
    }
}
