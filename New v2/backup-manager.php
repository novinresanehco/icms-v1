<?php

namespace App\Core\Backup;

use App\Core\Security\SecurityContext;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class BackupManager implements BackupInterface
{
    private MetricsCollector $metrics;
    private ValidationService $validator;
    private string $backupPath;

    public function __construct(
        MetricsCollector $metrics,
        ValidationService $validator,
        string $backupPath = 'backups'
    ) {
        $this->metrics = $metrics;
        $this->validator = $validator;
        $this->backupPath = $backupPath;
    }

    public function createBackup(): string
    {
        $backupId = $this->generateBackupId();
        
        try {
            $data = $this->gatherBackupData();
            $this->validateBackupData($data);
            $this->storeBackup($backupId, $data);
            
            return $backupId;
            
        } catch (\Exception $e) {
            Log::error('Backup creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function restoreBackup(string $backupId): void
    {
        DB::beginTransaction();
        
        try {
            $data = $this->loadBackup($backupId);
            $this->validateBackupData($data);
            $this->performRestore($data);
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Backup restoration failed', [
                'backup_id' => $backupId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function verifyBackup(string $backupId): bool
    {
        try {
            $data = $this->loadBackup($backupId);
            return $this->validator->validateBackupData($data);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function backupState(string $stateId, string $encrypted): void
    {
        $path = $this->getBackupPath("state_$stateId");
        Storage::put($path, $encrypted);
    }

    public function loadState(string $stateId): ?string
    {
        $path = $this->getBackupPath("state_$stateId");
        return Storage::exists($path) ? Storage::get($path) : null;
    }

    public function getBackupPath(string $identifier): string 
    {
        return "$this->backupPath/$identifier";
    }

    protected function generateBackupId(): string
    {
        return uniqid('backup_', true);
    }

    protected function gatherBackupData(): array
    {
        return [
            'database' => $this->backupDatabase(),
            'files' => $this->backupFiles(),
            'cache' => $this->backupCache(),
            'timestamp' => microtime(true)
        ];
    }

    protected function backupDatabase(): array
    {
        $tables = DB::select('SHOW TABLES');
        $backup = [];
        
        foreach ($tables as $table) {
            $tableName = array_values(get_object_vars($table))[0];
            $backup[$tableName] = DB::table($tableName)->get()->toArray();
        }
        
        return $backup;
    }

    protected function backupFiles(): array
    {
        $files = [];
        $directories = config('backup.directories', []);
        
        foreach ($directories as $directory) {
            $files[$directory] = Storage::allFiles($directory);
        }
        
        return $files;
    }

    protected function backupCache(): array
    {
        return [
            'keys' => Cache::keys(),
            'values' => collect(Cache::keys())->mapWithKeys(function ($key) {
                return [$key => Cache::get($key)];
            })->toArray()
        ];
    }

    protected function validateBackupData(array $data): void
    {
        if (!$this->validator->validateBackupData($data)) {
            throw new InvalidBackupException('Invalid backup data');
        }
    }

    protected function storeBackup(string $backupId, array $data): void
    {
        $encrypted = encrypt($data);
        $path = $this->getBackupPath($backupId);
        Storage::put($path, $encrypted);
    }

    protected function loadBackup(string $backupId): array
    {
        $path = $this->getBackupPath($backupId);
        
        if (!Storage::exists($path)) {
            throw new BackupNotFoundException("Backup not found: $backupId");
        }
        
        return decrypt(Storage::get($path));
    }

    protected function performRestore(array $data): void
    {
        $this->restoreDatabase($data['database']);
        $this->restoreFiles($data['files']);
        $this->restoreCache($data['cache']);
    }

    protected function restoreDatabase(array $tables): void
    {
        foreach ($tables as $table => $rows) {
            DB::table($table)->truncate();
            foreach ($rows as $row) {
                DB::table($table)->insert((array) $row);
            }
        }
    }

    protected function restoreFiles(array $files): void
    {
        foreach ($files as $directory => $paths) {
            foreach ($paths as $path) {
                Storage::copy("backup/$path", $path);
            }
        }
    }

    protected function restoreCache(array $cache): void
    {
        Cache::flush();
        
        foreach ($cache['values'] as $key => $value) {
            Cache::forever($key, $value);
        }
    }
}

interface BackupInterface
{
    public function createBackup(): string;
    public function restoreBackup(string $backupId): void;
    public function verifyBackup(string $backupId): bool;
}

class InvalidBackupException extends \Exception {}
class BackupNotFoundException extends \Exception {}
