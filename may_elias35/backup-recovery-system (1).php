namespace App\Core\Backup;

class BackupManager implements BackupInterface
{
    private SecurityManager $security;
    private StorageService $storage;
    private EncryptionService $encryption;
    private ValidationService $validator;
    private AuditLogger $audit;
    private array $config;

    public function __construct(
        SecurityManager $security,
        StorageService $storage,
        EncryptionService $encryption,
        ValidationService $validator,
        AuditLogger $audit,
        array $config
    ) {
        $this->security = $security;
        $this->storage = $storage;
        $this->encryption = $encryption;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function createBackup(string $type = 'full'): BackupResult
    {
        return $this->security->executeCriticalOperation(new class($type, $this->storage, $this->encryption, $this->validator) implements CriticalOperation {
            private string $type;
            private StorageService $storage;
            private EncryptionService $encryption;
            private ValidationService $validator;

            public function __construct(string $type, StorageService $storage, EncryptionService $encryption, ValidationService $validator)
            {
                $this->type = $type;
                $this->storage = $storage;
                $this->encryption = $encryption;
                $this->validator = $validator;
            }

            public function execute(): OperationResult
            {
                $backupData = match($this->type) {
                    'full' => $this->performFullBackup(),
                    'incremental' => $this->performIncrementalBackup(),
                    'differential' => $this->performDifferentialBackup(),
                    default => throw new BackupException('Invalid backup type')
                };

                $encrypted = $this->encryption->encrypt($backupData);
                $hash = hash('sha256', $encrypted);
                
                $backup = new Backup([
                    'type' => $this->type,
                    'size' => strlen($encrypted),
                    'hash' => $hash,
                    'timestamp' => now()
                ]);

                $path = $this->storage->store($encrypted, $backup->getIdentifier());
                $backup->path = $path;

                $this->validator->validateBackup($backup);
                $this->verifyBackupIntegrity($backup, $encrypted);

                return new OperationResult($backup);
            }

            private function performFullBackup(): array
            {
                return [
                    'database' => DB::backup(),
                    'files' => $this->storage->backupFiles(),
                    'config' => config()->all(),
                    'metadata' => [
                        'timestamp' => now(),
                        'version' => app()->version()
                    ]
                ];
            }

            private function verifyBackupIntegrity(Backup $backup, string $data): void
            {
                if (!hash_equals($backup->hash, hash('sha256', $data))) {
                    throw new BackupException('Backup integrity verification failed');
                }
            }

            public function getValidationRules(): array
            {
                return [
                    'type' => 'required|string|in:full,incremental,differential'
                ];
            }

            public function getData(): array
            {
                return ['type' => $this->type];
            }

            public function getRequiredPermissions(): array
            {
                return ['backup.create'];
            }

            public function getRateLimitKey(): string
            {
                return "backup:create:{$this->type}";
            }
        });
    }

    public function restore(string $identifier): RestoreResult
    {
        return $this->security->executeCriticalOperation(new class($identifier, $this->storage, $this->encryption, $this->validator) implements CriticalOperation {
            private string $identifier;
            private StorageService $storage;
            private EncryptionService $encryption;
            private ValidationService $validator;

            public function __construct(string $identifier, StorageService $storage, EncryptionService $encryption, ValidationService $validator)
            {
                $this->identifier = $identifier;
                $this->storage = $storage;
                $this->encryption = $encryption;
                $this->validator = $validator;
            }

            public function execute(): OperationResult
            {
                $backup = $this->storage->getBackup($this->identifier);
                $this->validator->validateRestore($backup);

                $encrypted = $this->storage->retrieve($backup->path);
                $this->verifyBackupIntegrity($backup, $encrypted);

                $data = $this->encryption->decrypt($encrypted);
                $restorePoint = $this->createRestorePoint();

                try {
                    $this->performRestore($data);
                    $result = new RestoreResult($backup, true);
                } catch (\Exception $e) {
                    $this->rollbackToRestorePoint($restorePoint);
                    throw new RestoreException('Restore failed: ' . $e->getMessage(), 0, $e);
                }

                return new OperationResult($result);
            }

            private function createRestorePoint(): string
            {
                return "restore_point_" . time();
            }

            private function performRestore(array $data): void
            {
                DB::restore($data['database']);
                $this->storage->restoreFiles($data['files']);
                config()->set($data['config']);
            }

            public function getValidationRules(): array
            {
                return ['identifier' => 'required|string'];
            }

            public function getData(): array
            {
                return ['identifier' => $this->identifier];
            }

            public function getRequiredPermissions(): array
            {
                return ['backup.restore'];
            }

            public function getRateLimitKey(): string
            {
                return "backup:restore:{$this->identifier}";
            }
        });
    }

    public function verifyBackups(): array
    {
        return $this->security->executeCriticalOperation(new class($this->storage, $this->encryption) implements CriticalOperation {
            private StorageService $storage;
            private EncryptionService $encryption;

            public function __construct(StorageService $storage, EncryptionService $encryption)
            {
                $this->storage = $storage;
                $this->encryption = $encryption;
            }

            public function execute(): OperationResult
            {
                $backups = $this->storage->listBackups();
                $results = [];

                foreach ($backups as $backup) {
                    $results[$backup->getIdentifier()] = $this->verifyBackup($backup);
                }

                return new OperationResult($results);
            }

            private function verifyBackup(Backup $backup): bool
            {
                try {
                    $data = $this->storage->retrieve($backup->path);
                    return hash_equals($backup->hash, hash('sha256', $data));
                } catch (\Exception $e) {
                    return false;
                }
            }

            public function getValidationRules(): array
            {
                return [];
            }

            public function getData(): array
            {
                return [];
            }

            public function getRequiredPermissions(): array
            {
                return ['backup.verify'];
            }

            public function getRateLimitKey(): string
            {
                return 'backup:verify';
            }
        });
    }
}
