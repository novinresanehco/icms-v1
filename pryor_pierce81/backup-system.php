namespace App\Core\Backup;

class BackupManager implements BackupInterface
{
    private SecurityManager $security;
    private StorageManager $storage;
    private EncryptionService $encryption;
    private CompressionService $compression;
    private ValidationService $validator;
    private MetricsCollector $metrics;
    private AuditLogger $logger;

    public function createBackup(BackupConfig $config): BackupResult
    {
        return $this->security->executeCriticalOperation(
            new BackupOperation(function() use ($config) {
                DB::beginTransaction();
                
                try {
                    // Create backup point
                    $backupId = $this->generateBackupId();
                    
                    // Collect and validate data
                    $data = $this->collectData($config);
                    $this->validateData($data);
                    
                    // Process backup
                    $compressed = $this->compression->compress($data);
                    $encrypted = $this->encryption->encrypt($compressed);
                    
                    // Store with integrity check
                    $hash = hash('sha256', $encrypted);
                    $this->storage->store($backupId, $encrypted, [
                        'hash' => $hash,
                        'config' => $config,
                        'metadata' => $this->generateMetadata()
                    ]);
                    
                    // Verify stored backup
                    $this->verifyBackup($backupId, $hash);
                    
                    DB::commit();
                    
                    $this->logger->info('backup.created', [
                        'backup_id' => $backupId,
                        'size' => strlen($encrypted),
                        'hash' => $hash
                    ]);
                    
                    return new BackupResult($backupId, true);
                    
                } catch (\Exception $e) {
                    DB::rollBack();
                    $this->handleBackupError($e);
                    throw $e;
                }
            })
        );
    }

    public function restore(string $backupId): RestoreResult
    {
        return $this->security->executeCriticalOperation(
            new RestoreOperation(function() use ($backupId) {
                DB::beginTransaction();
                
                try {
                    // Load and verify backup
                    $backup = $this->storage->retrieve($backupId);
                    $this->verifyBackupIntegrity($backup);
                    
                    // Decrypt and decompress
                    $decrypted = $this->encryption->decrypt($backup->data);
                    $decompressed = $this->compression->decompress($decrypted);
                    
                    // Validate before restore
                    $this->validateRestoreData($decompressed);
                    
                    // Perform restore
                    $result = $this->performRestore($decompressed);
                    
                    // Verify restored state
                    $this->verifyRestoredState($result);
                    
                    DB::commit();
                    
                    $this->logger->info('backup.restored', [
                        'backup_id' => $backupId,
                        'result' => $result->summary()
                    ]);
                    
                    return $result;
                    
                } catch (\Exception $e) {
                    DB::rollBack();
                    $this->handleRestoreError($backupId, $e);
                    throw $e;
                }
            })
        );
    }

    public function verifyBackups(): array
    {
        $results = [];
        
        foreach ($this->storage->listBackups() as $backupId) {
            try {
                $backup = $this->storage->retrieve($backupId);
                $verified = $this->verifyBackupIntegrity($backup);
                
                $results[$backupId] = [
                    'status' => $verified ? 'valid' : 'invalid',
                    'timestamp' => $backup->metadata['timestamp'],
                    'size' => strlen($backup->data)
                ];
                
            } catch (\Exception $e) {
                $results[$backupId] = [
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }

    private function verifyBackupIntegrity($backup): bool
    {
        if (!isset($backup->metadata['hash'])) {
            throw new BackupCorruptedException('Missing integrity hash');
        }
        
        $currentHash = hash('sha256', $backup->data);
        
        if (!hash_equals($backup->metadata['hash'], $currentHash)) {
            throw new BackupCorruptedException('Integrity check failed');
        }
        
        return true;
    }

    private function generateBackupId(): string
    {
        return uniqid('backup_', true);
    }

    private function collectData(BackupConfig $config): array
    {
        $data = [];
        
        foreach ($config->getTargets() as $target) {
            $data[$target] = match ($target) {
                'database' => $this->collectDatabaseData(),
                'files' => $this->collectFileData(),
                'media' => $this->collectMediaData(),
                default => throw new InvalidBackupTargetException()
            };
        }
        
        return $data;
    }

    private function validateData(array $data): void
    {
        foreach ($data as $target => $targetData) {
            if (!$this->validator->validateBackupData($target, $targetData)) {
                throw new InvalidBackupDataException("Invalid data for target: $target");
            }
        }
    }

    private function generateMetadata(): array
    {
        return [
            'timestamp' => now(),
            'version' => config('app.version'),
            'environment' => app()->environment(),
            'system_info' => $this->collectSystemInfo()
        ];
    }

    private function handleBackupError(\Exception $e): void
    {
        $this->metrics->increment('backup.failures');
        
        $this->logger->error('backup.failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function handleRestoreError(string $backupId, \Exception $e): void
    {
        $this->metrics->increment('restore.failures');
        
        $this->logger->error('restore.failed', [
            'backup_id' => $backupId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
