<?php

namespace App\Core\Backup;

use Illuminate\Support\Facades\{DB, Storage, Log};
use App\Core\Security\SecurityManager;
use App\Core\Exceptions\BackupException;

class BackupService
{
    protected SecurityManager $security;
    protected string $backupPath;
    protected array $criticalTables;

    public function __construct(
        SecurityManager $security,
        string $backupPath = 'backups',
        array $criticalTables = ['users', 'contents', 'security_events']
    ) {
        $this->security = $security;
        $this->backupPath = $backupPath;
        $this->criticalTables = $criticalTables;
    }

    public function createBackup(bool $critical = false): string
    {
        DB::beginTransaction();

        try {
            $this->security->validateAccess('backup.create');
            
            $backupId = $this->generateBackupId();
            $tables = $critical ? $this->criticalTables : $this->getAllTables();
            
            $backup = $this->backupTables($tables, $backupId);
            $this->verifyBackup($backup, $backupId);
            
            $this->storeBackupMetadata($backup, $backupId);
            
            DB::commit();
            return $backupId;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new BackupException('Backup failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function restore(string $backupId): void
    {
        DB::beginTransaction();

        try {
            $this->security->validateAccess('backup.restore');
            
            $metadata = $this->getBackupMetadata($backupId);
            $this->validateBackup($metadata);
            
            foreach ($metadata['tables'] as $table) {
                $this->restoreTable($table, $backupId);
            }
            
            $this->verifyRestoration($metadata);
            
            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            throw new BackupException('Restore failed: ' . $e->getMessage(), 0, $e);
        }
    }

    protected function backupTables(array $tables, string $backupId): array
    {
        $backup = [];

        foreach ($tables as $table) {
            $data = DB::table($table)->get();
            $schema = DB::getSchemaBuilder()->getTableDefinition($table);
            
            $backup[$table] = [
                'data' => $this->encryptData($data->toArray()),
                'schema' => $schema,
                'checksum' => $this->calculateChecksum($data)
            ];

            $this->storeTableBackup($table, $backup[$table], $backupId);
        }

        return $backup;
    }

    protected function storeTableBackup(string $table, array $data, string $backupId): void
    {
        $path = "{$this->backupPath}/{$backupId}/{$table}.backup";
        
        Storage::put($path, serialize($data));
        
        if (!Storage::exists($path)) {
            throw new BackupException("Failed to store backup for table: {$table}");
        }
    }

    protected function restoreTable(string $table, string $backupId): void
    {
        $data = $this->getTableBackup($table, $backupId);
        $decrypted = $this->decryptData($data['data']);
        
        DB::table($table)->truncate();
        DB::table($table)->insert($decrypted);
        
        if ($this->calculateChecksum(DB::table($table)->get()) !== $data['checksum']) {
            throw new BackupException("Checksum validation failed for table: {$table}");
        }
    }

    protected function getTableBackup(string $table, string $backupId): array
    {
        $path = "{$this->backupPath}/{$backupId}/{$table}.backup";
        
        if (!Storage::exists($path)) {
            throw new BackupException("Backup not found for table: {$table}");
        }

        return unserialize(Storage::get($path));
    }

    protected function verifyBackup(array $backup, string $backupId): void
    {
        foreach ($backup as $table => $data) {
            $stored = $this->getTableBackup($table, $backupId);
            
            if ($stored['checksum'] !== $data['checksum']) {
                throw new BackupException("Backup verification failed for table: {$table}");
            }
        }
    }

    protected function encryptData(array $data): string
    {
        return $this->security->encrypt(serialize($data));
    }

    protected function decryptData(string $data): array
    {
        return unserialize($this->security->decrypt($data));
    }

    protected function calculateChecksum($data): string
    {
        return hash('sha256', serialize($data));
    }

    protected function generateBackupId(): string
    {
        return date('Y-m-d-H-i-s') . '-' . bin2hex(random_bytes(8));
    }
}
