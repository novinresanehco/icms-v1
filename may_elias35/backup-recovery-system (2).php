<?php

namespace App\Core\Recovery;

class BackupManager implements BackupInterface
{
    private SecurityManager $security;
    private StorageManager $storage;
    private EncryptionService $encryption;
    private IntegrityVerifier $verifier;
    private BackupRegistry $registry;

    public function createBackup(string $type = 'full'): BackupResult
    {
        return $this->security->executeCriticalOperation(
            new CreateBackupOperation(
                $type,
                $this->storage,
                $this->encryption,
                $this->verifier
            )
        );
    }

    public function restore(string $backupId): RestoreResult
    {
        return $this->security->executeCriticalOperation(
            new RestoreBackupOperation(
                $backupId,
                $this->storage,
                $this->encryption,
                $this->verifier
            )
        );
    }

    public function verify(string $backupId): VerificationResult
    {
        return $this->security->executeCriticalOperation(
            new VerifyBackupOperation(
                $backupId,
                $this->storage,
                $this->verifier
            )
        );
    }
}

class RecoveryManager implements RecoveryInterface
{
    private BackupManager $backups;
    private SystemState $state;
    private DataVerifier $verifier;
    private AuditLogger $logger;

    public function initiateRecovery(string $backupId): RecoveryProcess
    {
        return $this->security->executeCriticalOperation(
            new InitiateRecoveryOperation(
                $backupId,
                $this->backups,
                $this->state,
                $this->verifier
            )
        );
    }

    public function verifyRecovery(RecoveryProcess $process): VerificationResult
    {
        $verification = $this->verifier->verifyRecovery($process);
        $this->logger->logRecoveryVerification($verification);
        return $verification;
    }
}

class CreateBackupOperation implements CriticalOperation
{
    private string $type;
    private StorageManager $storage;
    private EncryptionService $encryption;
    private IntegrityVerifier $verifier;

    public function execute(): BackupResult
    {
        // Capture system state
        $state = $this->storage->captureState();
        
        // Encrypt backup data
        $encrypted = $this->encryption->encrypt(
            $state->getData(),
            $this->encryption->generateKey()
        );
        
        // Calculate integrity hash
        $hash = $this->verifier->calculateHash($encrypted);
        
        // Store backup
        $backup = $this->storage->storeBackup(
            $encrypted,
            $hash,
            $this->type
        );
        
        // Verify stored backup
        $this->verifyStoredBackup($backup);
        
        return new BackupResult($backup);
    }

    private function verifyStoredBackup(Backup $backup): void
    {
        $stored = $this->storage->retrieveBackup($backup->getId());
        
        if (!$this->verifier->verifyIntegrity($stored)) {
            throw new BackupException('Backup integrity verification failed');
        }
    }
}

class RestoreBackupOperation implements CriticalOperation
{
    private string $backupId;
    private StorageManager $storage;
    private EncryptionService $encryption;
    private IntegrityVerifier $verifier;

    public function execute(): RestoreResult
    {
        // Load and verify backup
        $backup = $this->loadAndVerifyBackup();
        
        // Create system snapshot
        $snapshot = $this->storage->createSnapshot();
        
        try {
            // Decrypt backup data
            $decrypted = $this->encryption->decrypt(
                $backup->getData(),
                $backup->getKey()
            );
            
            // Restore system state
            $this->storage->restoreState($decrypted);
            
            // Verify restored state
            $this->verifyRestoredState($decrypted);
            
            return new RestoreResult(true);
            
        } catch (\Exception $e) {
            // Rollback to snapshot
            $this->storage->restoreSnapshot($snapshot);
            throw $e;
        }
    }

    private function loadAndVerifyBackup(): Backup
    {
        $backup = $this->storage->retrieveBackup($this->backupId);
        
        if (!$this->verifier->verifyIntegrity($backup)) {
            throw new BackupException('Backup integrity check failed');
        }
        
        return $backup;
    }

    private function verifyRestoredState(array $expected): void
    {
        $current = $this->storage->captureState()->getData();
        
        if (!$this->verifier->verifyStateMatch($expected, $current)) {
            throw new RestoreException('State verification failed');
        }
    }
}

class IntegrityVerifier
{
    private string $algorithm = 'sha384';
    private ValidationService $validator;

    public function calculateHash(string $data): string
    {
        return hash_hmac($this->algorithm, $data, $this->getSecretKey());
    }

    public function verifyIntegrity(Backup $backup): bool
    {
        $calculated = $this->calculateHash($backup->getData());
        return hash_equals($calculated, $backup->getHash());
    }

    public function verifyStateMatch(array $expected, array $current): bool
    {
        return $this->validator->validateStateEquality($expected, $current);
    }

    private function getSecretKey(): string
    {
        return config('backup.integrity_key');
    }
}
