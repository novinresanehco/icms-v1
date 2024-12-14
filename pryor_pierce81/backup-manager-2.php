<?php

namespace App\Core\Backup;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\BackupException;
use Psr\Log\LoggerInterface;

class BackupManager implements BackupManagerInterface
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $config;
    private array $handlers = [];

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function createBackup(string $type, array $options = []): string
    {
        $backupId = $this->generateBackupId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('backup:create', [
                'type' => $type
            ]);

            $this->validateBackupType($type);
            $this->validateBackupOptions($options);

            $backup = $this->processBackup($type, $options);
            $this->validateBackup($backup);
            
            $this->storeBackup($backupId, $backup);
            $this->logBackupOperation($backupId, 'create');

            DB::commit();
            return $backupId;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleBackupFailure($backupId, 'create', $e);
            throw new BackupException('Backup creation failed', 0, $e);
        }
    }

    public function restoreBackup(string $backupId, array $options = []): bool
    {
        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('backup:restore', [
                'backup_id' => $backupId
            ]);

            $this->validateBackupExists($backupId);
            $this->validateRestoreOptions($options);

            $backup = $this->loadBackup($backupId);
            $this->validateBackupIntegrity($backup);

            $success = $this->processRestore($backup, $options);
            $this->verifyRestore($backup);

            $this->logBackupOperation($backupId, 'restore');

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleRestoreFailure($backupId, $e);
            throw new BackupException('Backup restoration failed', 0, $e);
        }
    }

    private function processBackup(string $type, array $options): Backup
    {
        $handler = $this->getBackupHandler($type);
        $data = $handler->collectData($options);
        
        $backup = new Backup();
        $backup->setType($type);
        $backup->setData($this->encryptBackupData($data));
        $backup->setMetadata($this->generateBackupMetadata($type, $options));
        
        return $backup;
    }

    private function validateBackupType(string $type): void
    {
        if (!isset($this->config['backup_types'][$type])) {
            throw new BackupException("Unsupported backup type: {$type}");
        }
    }

    private function validateBackupOptions(array $options): void
    {
        foreach ($this->config['required_options'] as $option) {
            if (!isset($options[$option])) {
                throw new BackupException("Missing required option: {$option}");
            }
        }
    }

    private function validateBackup(Backup $backup): void
    {
        if (!$backup->isValid()) {
            throw new BackupException('Invalid backup data');
        }

        if ($backup->getSize() > $this->config['max_backup_size']) {
            throw new BackupException('Backup size exceeds limit');
        }
    }

    private function handleBackupFailure(string $id, string $operation, \Exception $e): void
    {
        $this->logger->error('Backup operation failed', [
            'backup_id' => $id,
            'operation' => $operation,
            'error' => $e->getMessage()
        ]);

        $this->notifyBackupFailure($id, $operation, $e);
    }

    private function getDefaultConfig(): array
    {
        return [
            'backup_types' => [
                'full' => FullBackupHandler::class,
                'incremental' => IncrementalBackupHandler::class,
                'differential' => DifferentialBackupHandler::class
            ],
            'max_backup_size' => 107374182400,
            'retention_period' => 30 * 86400,
            'encryption_enabled' => true,
            'compression_enabled' => true
        ];
    }
}
