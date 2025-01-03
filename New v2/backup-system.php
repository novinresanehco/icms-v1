<?php
namespace App\Core\Backup;

class BackupManager implements BackupManagerInterface
{
    private SecurityManager $security;
    private BackupService $backup;
    private StorageService $storage;
    private ValidationService $validator;
    private AuditLogger $audit;

    public function createBackup(SecurityContext $context): Backup
    {
        return $this->security->executeCriticalOperation(
            new CreateBackupOperation(
                $this->backup,
                $this->storage,
                $this->validator,
                $this->audit
            ),
            $context
        );
    }

    public function restore(int $backupId, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(
            new RestoreBackupOperation(
                $backupId,
                $this->backup,
                $this->storage,
                $this->audit
            ),
            $context
        );
    }

    public function verify(int $backupId, SecurityContext $context): BackupVerification
    {
        return $this->security->executeCriticalOperation(
            new VerifyBackupOperation(
                $backupId,
                $this->backup,
                $this->storage,
                $this->audit
            ),
            $context
        );
    }
}

class CreateBackupOperation extends CriticalOperation
{
    private BackupService $backup;
    private StorageService $storage;
    private ValidationService $validator;
    private AuditLogger $audit;

    public function execute(): Backup
    {
        // Validate system state
        $this->validateSystemState();

        // Create backup file
        $file = $this->backup->create();

        // Verify backup integrity
        $this->verifyBackup($file);

        // Store backup
        $path = $this->storage->storeBackup($file);

        // Create record
        $backup = $this->createBackupRecord($path);

        // Log operation
        $this->audit->logBackupCreation($backup);

        return $backup;
    }

    private function validateSystemState(): void
    {
        if (!$this->validator->validateSystemState()) {
            throw new SystemStateException('System not in valid state for backup');
        }
    }

    private function verifyBackup(BackupFile $file): void
    {
        if (!$this->backup->verify($file)) {
            throw new BackupException('Backup verification failed');
        }
    }

    private function createBackupRecord(string $path): Backup
    {
        return Backup::create([
            'path' => $path,
            'size' => $this->storage->size($path),
            'hash' => $this->storage->hash($path),
            'created_at' => now()
        ]);
    }

    public function getRequiredPermissions(): array
    {
        return ['backup.create'];
    }
}

class RestoreBackupOperation extends CriticalOperation
{
    private int $backupId;
    private BackupService $backup;
    private StorageService $storage;
    private AuditLogger $audit;

    public function execute(): void
    {
        // Load backup record
        $backup = $this->loadBackup();

        // Verify backup file
        $this->verifyBackupFile($backup);

        // Create system snapshot
        $snapshot = $this->createSystemSnapshot();

        try {
            // Perform restore
            $this->backup->restore($backup->path);

            // Verify system state
            $this->verifySystemState();

            // Log success
            $this->audit->logBackupRestore($backup);

        } catch (\Exception $e) {
            // Restore from snapshot
            $this->restoreSnapshot($snapshot);

            // Log failure
            $this->audit->logBackupRestoreFailure($backup, $e);

            throw new BackupException('Backup restoration failed: ' . $e->getMessage());
        }
    }

    private function loadBackup(): Backup
    {
        $backup = Backup::find($this->backupId);
        if (!$backup) {
            throw new BackupNotFoundException("Backup not found: {$this->backupId}");
        }
        return $backup;
    }

    private function verifyBackupFile(Backup $backup): void
    {
        if (!$this->storage->exists($backup->path)) {
            throw new BackupException('Backup file not found');
        }

        if (!hash_equals($backup->hash, $this->storage->hash($backup->path))) {
            throw new SecurityException('Backup file corrupted');
        }
    }

    private function createSystemSnapshot(): string
    {
        return $this->backup->createSnapshot();
    }

    private function restoreSnapshot(string $snapshot): void
    {
        $this->backup->restoreSnapshot($snapshot);
    }

    private function verifySystemState(): void
    {
        if (!$this->backup->verifySystemState()) {
            throw new SystemStateException('System in invalid state after restore');
        }
    }

    public function getRequiredPermissions(): array
    {
        return ['backup.restore'];
    }
}

class VerifyBackupOperation extends CriticalOperation
{
    private int $backupId;
    private BackupService $backup;
    private StorageService $storage;
    private AuditLogger $audit;

    public function execute(): BackupVerification
    {
        // Load backup
        $backup = Backup::findOrFail($this->backupId);

        // Verify file exists
        if (!$this->storage->exists($backup->path)) {
            throw new BackupException('Backup file not found');
        }

        // Verify integrity
        $verification = $this->backup->verify($backup->path);

        // Log verification
        $this->audit->logBackupVerification($backup, $verification);

        return $verification;
    }

    public function getRequiredPermissions(): array
    {
        return ['backup.verify'];
    }
}
