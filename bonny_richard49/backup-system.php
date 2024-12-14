<?php

namespace App\Core\Backup;

use App\Core\Interfaces\{
    BackupServiceInterface,
    EncryptionServiceInterface,
    ValidationServiceInterface
};
use App\Core\Exceptions\BackupException;
use Illuminate\Support\Facades\{DB, Storage, Log};

class BackupService implements BackupServiceInterface
{
    private EncryptionServiceInterface $encryption;
    private ValidationServiceInterface $validator;
    private string $backupPath;
    private array $config;

    public function __construct(
        EncryptionServiceInterface $encryption,
        ValidationServiceInterface $validator,
        array $config = []
    ) {
        $this->encryption = $encryption;
        $this->validator = $validator;
        $this->config = $config;
        $this->backupPath = storage_path('backups');
    }

    public function createBackup(array $context = []): string
    {
        try {
            DB::beginTransaction();

            // Generate backup ID
            $backupId = $this->generateBackupId();
            
            // Create backup metadata
            $metadata = $this->createBackupMetadata($backupId, $context);
            
            // Create backup directory
            $backupDir = $this->createBackupDirectory($backupId);
            
            // Backup database
            $this->backupDatabase($backupDir);
            
            // Backup files
            $this->backupFiles($backupDir);
            
            // Create backup manifest
            $this->createBackupManifest($backupDir, $metadata);
            
            // Encrypt backup
            $this->encryptBackup($backupDir);
            
            // Verify backup
            $this->verifyBackup($backupId);

            DB::commit();
            
            $this->logBackupSuccess($backupId, $metadata);
            
            return $backupId;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleBackupFailure($backupId ?? null, $e);
            throw $e;
        }
    }

    public function restoreBackup(string $backupId, array $context = []): bool
    {
        try {
            DB::beginTransaction();

            // Validate backup
            $this->validateBackup($backupId);
            
            // Decrypt backup
            $backupDir = $this->decryptBackup($backupId);
            
            // Verify manifest
            $metadata = $this->verifyBackupManifest($backupDir);
            
            // Restore database
            $this->restoreDatabase($backupDir);
            
            // Restore files
            $this->restoreFiles($backupDir);
            
            // Verify restoration
            $this->verifyRestoration($backupId, $metadata);

            DB::commit();
            
            $this->logRestoreSuccess($backupId, $metadata);
            
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleRestoreFailure($backupId, $e);
            throw $e;
        }
    }

    public function verifyBackup(string $backupId): bool
    {
        try {
            // Check backup existence
            $backupDir = $this->getBackupDirectory($backupId);
            if (!Storage::exists($backupDir)) {
                throw new BackupException("Backup not found: $backupId");
            }

            // Verify manifest
            $metadata = $this->verifyBackupManifest($backupDir);

            // Verify database backup
            $this->verifyDatabaseBackup($backupDir);

            // Verify file backups
            $this->verifyFileBackups($backupDir);

            // Verify encryption
            $this->verifyEncryption($backupDir);

            return true;

        } catch (\Exception $e) {
            $this->logVerificationFailure($backupId, $e);
            return false;
        }
    }

    public function listBackups(): array
    {
        try {
            $backups = [];
            $directories = Storage::directories($this->backupPath);

            foreach ($directories as $dir) {
                $backupId = basename($dir);
                $metadata = $this->getBackupMetadata($backupId);
                if ($metadata) {
                    $backups[] = $metadata;
                }
            }

            return $backups;

        } catch (\Exception $e) {
            Log::error('Failed to list backups', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new BackupException('Failed to list backups', 0, $e);
        }
    }

    public function cleanupBackups(int $keepLast = 5): bool
    {
        try {
            $backups = $this->listBackups();
            usort($backups, fn($a, $b) => $b['created_at'] <=> $a['created_at']);

            // Keep required number of backups
            $backups = array_slice($backups, $keepLast);

            foreach ($backups as $backup) {
                $this->deleteBackup($backup['id']);
            }

            return true;

        } catch (\Exception $e) {
            Log::error('Backup cleanup failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    protected function generateBackupId(): string
    {
        return date('Ymd_His') . '_' . uniqid();
    }

    protected function createBackupMetadata(string $backupId, array $context): array
    {
        return [
            'id' => $backupId,
            'type' => 'full',
            'created_at' => now()->toIso8601String(),
            'created_by' => $context['user_id'] ?? 'system',
            'checksum' => null,
            'size' => 0,
            'context' => $context
        ];
    }

    protected function createBackupDirectory(string $backupId): string
    {
        $path = "$this->backupPath/$backupId";
        Storage::makeDirectory($path);
        return $path;
    }

    protected function backupDatabase(string $backupDir): void
    {
        $filename = "$backupDir/database.sql";
        $command = sprintf(
            'mysqldump -u%s -p%s %s > %s',
            config('database.connections.mysql.username'),
            config('database.connections.mysql.password'),
            config('database.connections.mysql.database'),
            $filename
        );
        
        exec($command);
    }

    protected function backupFiles(string $backupDir): void
    {
        $directories = $this->config['backup_directories'] ?? [
            storage_path('app'),
            public_path('uploads')
        ];

        foreach ($directories as $dir) {
            $targetDir = "$backupDir/files/" . basename($dir);
            Storage::makeDirectory($targetDir);
            
            $files = Storage::allFiles($dir);
            foreach ($files as $file) {
                Storage::copy($file, "$targetDir/" . basename($file));
            }
        }
    }

    protected function createBackupManifest(string $backupDir, array $metadata): void
    {
        $manifest = [
            'metadata' => $metadata,
            'files' => $this->getBackupFileList($backupDir),
            'checksum' => $this->calculateBackupChecksum($backupDir)
        ];

        Storage::put(
            "$backupDir/manifest.json",
            json_encode($manifest, JSON_PRETTY_PRINT)
        );
    }

    protected function encryptBackup(string $backupDir): void
    {
        $files = Storage::allFiles($backupDir);
        
        foreach ($files as $file) {
            $content = Storage::get($file);
            $encrypted = $this->encryption->encrypt($content);
            Storage::put("$file.enc", $encrypted);
            Storage::delete($file);
        }
    }

    protected function decryptBackup(string $backupId): string
    {
        $backupDir = $this->getBackupDirectory($backupId);
        $files = Storage::allFiles($backupDir);
        
        foreach ($files as $file) {
            if (substr($file, -4) === '.enc') {
                $content = Storage::get($file);
                $decrypted = $this->encryption->decrypt($content);
                Storage::put(substr($file, 0, -4), $decrypted);
                Storage::delete($file);
            }
        }

        return $backupDir;
    }

    protected function validateBackup(string $backupId): void
    {
        if (!$this->verifyBackup($backupId)) {
            throw new BackupException("Invalid backup: $backupId");
        }
    }

    protected function verifyBackupManifest(string $backupDir): array
    {
        $manifestPath = "$backupDir/manifest.json";
        if (!Storage::exists($manifestPath)) {
            throw new BackupException('Backup manifest not found');
        }

        $manifest = json_decode(Storage::get($manifestPath), true);
        if (!$manifest) {
            throw new BackupException('Invalid backup manifest');
        }

        // Verify checksum
        $currentChecksum = $this->calculateBackupChecksum($backupDir);
        if ($currentChecksum !== $manifest['checksum']) {
            throw new BackupException('Backup checksum verification failed');
        }

        return $manifest['metadata'];
    }

    protected function verifyDatabaseBackup(string $backupDir): void
    {
        if (!Storage::exists("$backupDir/database.sql")) {
            throw new BackupException('Database backup not found');
        }
    }

    protected function verifyFileBackups(string $backupDir): void
    {
        $manifest = json_decode(
            Storage::get("$backupDir/manifest.json"),
            true
        );

        foreach ($manifest['files'] as $file => $checksum) {
            if (!Storage::exists("$backupDir/$file")) {
                throw new BackupException("Backup file missing: $file");
            }

            $currentChecksum = hash_file('sha256', "$backupDir/$file");
            if ($currentChecksum !== $checksum) {
                throw new BackupException("Checksum mismatch for file: $file");
            }
        }
    }

    protected function verifyEncryption(string $backupDir): void
    {
        $files = Storage::allFiles($backupDir);
        foreach ($files as $file) {
            if (substr($file, -4) !== '.enc') {
                throw new BackupException("Unencrypted file found: $file");
            }
        }
    }

    protected function getBackupDirectory(string $backupId): string
    {
        return "$this->backupPath/$backupId";
    }

    protected function getBackupFileList(string $backupDir): array
    {
        $files = [];
        $allFiles = Storage::allFiles($backupDir);
        
        foreach ($allFiles as $file) {
            if (basename($file) !== 'manifest.json') {
                $files[basename($file)] = hash_file('sha256', $file);
            }
        }

        return $files;
    }

    protected function calculateBackupChecksum(string $backupDir): string
    {
        $files = Storage::allFiles($backupDir);
        sort($files);
        
        $checksums = [];
        foreach ($files as $file) {
            if (basename($file) !== 'manifest.json') {
                $checksums[] = hash_file('sha256', $file);
            }
        }

        return hash('sha256', implode('', $checksums));
    }

    protected function logBackupSuccess(string $backupId, array $metadata): void
    {
        Log::info('Backup created successfully', [
            'backup_id' => $backupId,
            'metadata' => $metadata
        ]);
    }

    protected function logRestoreSuccess(string $backupId, array $metadata): void
    {
        Log::info('Backup restored successfully', [
            'backup_id' => $backupId,
            'metadata' => $metadata
        ]);
    }

    protected function logVerificationFailure(string $backupId, \Exception $e): void
    {
        Log::error('Backup verification failed', [
            'backup_id' => $backupId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    protected function handleBackupFailure(
        ?string $backupId,
        \Exception $e
    ): void {
        if ($backupId) {
            Storage::deleteDirectory($this->getBackupDirectory($backupId));
        }

        Log::error('Backup failed', [
            'backup_id' => $backupId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    protected function handleRestoreFailure(
        string $backupId,
        \Exception $e
    ): void {
        Log::error('Backup restoration failed', [
            'backup_id' => $backupId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
