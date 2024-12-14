<?php

namespace App\Core\Backup;

class BackupService implements BackupInterface
{
    private BackupEngine $engine;
    private IntegrityValidator $validator;
    private EncryptionService $encryption;
    private StorageManager $storage;
    private BackupLogger $logger;
    private EmergencyProtocol $emergency;

    public function __construct(
        BackupEngine $engine,
        IntegrityValidator $validator,
        EncryptionService $encryption,
        StorageManager $storage,
        BackupLogger $logger,
        EmergencyProtocol $emergency
    ) {
        $this->engine = $engine;
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->storage = $storage;
        $this->logger = $logger;
        $this->emergency = $emergency;
    }

    public function createBackup(BackupContext $context): BackupResult
    {
        $backupId = $this->initializeBackup($context);
        
        try {
            DB::beginTransaction();

            $snapshot = $this->engine->createSnapshot($context);
            $this->validateSnapshot($snapshot);

            $encryptedBackup = $this->encryption->encrypt($snapshot);
            $this->validateEncryption($encryptedBackup);

            $storageResult = $this->storage->store($encryptedBackup);
            $this->verifyStorage($storageResult);

            $result = new BackupResult([
                'backupId' => $backupId,
                'snapshot' => $snapshot->getMetadata(),
                'storage' => $storageResult,
                'integrity' => $this->calculateIntegrityHash($encryptedBackup),
                'timestamp' => now()
            ]);

            DB::commit();
            return $result;

        } catch (BackupException $e) {
            DB::rollBack();
            $this->handleBackupFailure($e, $backupId);
            throw new CriticalBackupException($e->getMessage(), $e);
        }
    }

    private function validateSnapshot(Snapshot $snapshot): void
    {
        if (!$this->validator->validateSnapshot($snapshot)) {
            $this->emergency->handleInvalidSnapshot($snapshot);
            throw new SnapshotValidationException('Snapshot validation failed');
        }
    }

    private function validateEncryption(EncryptedBackup $backup): void
    {
        if (!$this->validator->validateEncryption($backup)) {
            $this->emergency->handleEncryptionFailure($backup);
            throw new EncryptionValidationException('Backup encryption validation failed');
        }
    }

    private function handleBackupFailure(BackupException $e, string $backupId): void
    {
        $this->logger->logFailure($e, $backupId);
        
        if ($e->isCritical()) {
            $this->emergency->initiateCriticalRecovery([
                'backupId' => $backupId,
                'error' => $e->getMessage(),
                'severity' => EmergencyLevel::CRITICAL
            ]);
        }
    }
}
