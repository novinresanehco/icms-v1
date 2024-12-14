<?php

namespace App\Core\Backup;

class CriticalBackupService
{
    private $storage;
    private $monitor;
    private $security;

    public function createBackup(string $context): string
    {
        $backupId = uniqid('backup_', true);

        try {
            // Get data to backup
            $data = $this->storage->getData($context);

            // Encrypt backup
            $encrypted = $this->security->encrypt($data);

            // Store with integrity check
            $hash = $this->storeBackup($backupId, $encrypted);

            // Verify backup
            $this->verifyBackup($backupId, $hash);

            $this->monitor->backupSuccess($backupId);
            return $backupId;

        } catch (\Exception $e) {
            $this->monitor->backupFailure($backupId, $e);
            throw new BackupException('Backup failed', 0, $e);
        }
    }

    private function storeBackup(string $id, string $data): string
    {
        $hash = hash('sha256', $data);
        $this->storage->store($id, [
            'data' => $data,
            'hash' => $hash,
            'timestamp' => time()
        ]);
        return $hash;
    }

    private function verifyBackup(string $id, string $hash): void
    {
        $stored = $this->storage->retrieve($id);
        if (!hash_equals($hash, hash('sha256', $stored['data']))) {
            throw new BackupVerificationException('Backup verification failed');
        }
    }
}
