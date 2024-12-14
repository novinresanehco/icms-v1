```php
namespace App\Core\Backup;

class BackupManager implements BackupInterface
{
    private StorageManager $storage;
    private SecurityManager $security;
    private DatabaseManager $database;
    private ValidationService $validator;
    private AuditLogger $audit;

    public function createBackup(string $type = 'full'): BackupResult
    {
        return $this->security->executeProtected(function() use ($type) {
            // Create secure backup point
            $backupId = $this->security->generateBackupId();
            
            $this->audit->startBackup($backupId, $type);
            
            try {
                $data = $this->gatherBackupData($type);
                $this->validateBackupData($data);
                
                $encrypted = $this->security->encryptBackup($data);
                $this->storage->store($backupId, $encrypted);

                $result = new BackupResult($backupId, $type);
                $this->audit->completeBackup($result);
                
                return $result;
            } catch (\Throwable $e) {
                $this->audit->failedBackup($backupId, $e);
                throw new BackupFailedException($e->getMessage(), 0, $e);
            }
        });
    }

    private function gatherBackupData(string $type): array
    {
        return match($type) {
            'full' => [
                'database' => $this->database->dump(),
                'files' => $this->storage->snapshot(),
                'config' => $this->gatherConfig()
            ],
            'incremental' => $this->gatherIncrementalData(),
            default => throw new InvalidBackupTypeException()
        };
    }

    private function validateBackupData(array $data): void
    {
        if (!$this->validator->validateBackup($data)) {
            throw new InvalidBackupDataException();
        }
    }

    public function restore(string $backupId): RestoreResult
    {
        return $this->security->executeProtected(function() use ($backupId) {
            $this->audit->startRestore($backupId);
            
            try {
                $encrypted = $this->storage->retrieve($backupId);
                $data = $this->security->decryptBackup($encrypted);
                
                $this->validateRestoreData($data);
                $this->performRestore($data);
                
                $result = new RestoreResult($backupId);
                $this->audit->completeRestore($result);
                
                return $result;
            } catch (\Throwable $e) {
                $this->audit->failedRestore($backupId, $e);
                throw new RestoreFailedException($e->getMessage(), 0, $e);
            }
        });
    }

    private function validateRestoreData(array $data): void
    {
        if (!$this->validator->validateRestoreData($data)) {
            throw new InvalidRestoreDataException();
        }
    }

    private function performRestore(array $data): void
    {
        $this->database->transaction(function() use ($data) {
            $this->database->restore($data['database']);
            $this->storage->restore($data['files']);
            $this->restoreConfig($data['config']);
        });
    }
}

class ValidationService
{
    private SecurityManager $security;
    private HashService $hash;

    public function validateBackup(array $data): bool
    {
        return $this->validateStructure($data) &&
               $this->validateIntegrity($data) &&
               $this->validateCompleteness($data);
    }

    private function validateStructure(array $data): bool
    {
        return isset($data['database']) &&
               isset($data['files']) &&
               isset($data['config']);
    }

    private function validateIntegrity(array $data): bool
    {
        return $this->hash->verify($data['database']) &&
               $this->hash->verify($data['files']) &&
               $this->hash->verify($data['config']);
    }

    private function validateCompleteness(array $data): bool
    {
        return $this->security->validateDataCompleteness($data);
    }
}

class AuditLogger
{
    private LogManager $logger;
    private MetricsCollector $metrics;

    public function startBackup(string $backupId, string $type): void
    {
        $this->logger->info('backup_started', [
            'backup_id' => $backupId,
            'type' => $type,
            'timestamp' => now()
        ]);
        
        $this->metrics->increment('backup.started');
    }

    public function completeBackup(BackupResult $result): void
    {
        $this->logger->info('backup_completed', [
            'backup_id' => $result->getId(),
            'type' => $result->getType(),
            'size' => $result->getSize(),
            'duration' => $result->getDuration(),
            'timestamp' => now()
        ]);
        
        $this->metrics->increment('backup.completed');
    }

    public function failedBackup(string $backupId, \Throwable $e): void
    {
        $this->logger->error('backup_failed', [
            'backup_id' => $backupId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => now()
        ]);
        
        $this->metrics->increment('backup.failed');
    }
}
```
