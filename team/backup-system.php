<?php

namespace App\Core\Backup;

use Illuminate\Support\Facades\{Storage, Cache, DB};
use App\Core\Security\CoreSecurityManager;
use App\Core\Interfaces\{BackupInterface, RecoveryInterface};

class BackupManager implements BackupInterface
{
    private CoreSecurityManager $security;
    private string $backupPath = 'backups/system';
    private array $criticalTables = ['users', 'content', 'media', 'permissions'];
    
    public function createBackup(): BackupResult
    {
        return $this->security->executeCriticalOperation(
            new BackupOperation('create', [], function() {
                $backupId = $this->generateBackupId();
                
                DB::beginTransaction();
                try {
                    $manifest = $this->createManifest($backupId);
                    $this->backupDatabase($backupId);
                    $this->backupFiles($backupId);
                    $this->verifyBackup($backupId, $manifest);
                    
                    DB::commit();
                    $this->updateBackupRegistry($backupId, $manifest);
                    
                    return new BackupResult($backupId, $manifest);
                    
                } catch (\Exception $e) {
                    DB::rollBack();
                    $this->cleanupFailedBackup($backupId);
                    throw $e;
                }
            })
        );
    }

    private function backupDatabase(string $backupId): void
    {
        foreach ($this->criticalTables as $table) {
            $data = DB::table($table)->get();
            
            Storage::put(
                $this->getTableBackupPath($backupId, $table),
                encrypt(json_encode($data))
            );
        }

        $structure = [];
        foreach ($this->criticalTables as $table) {
            $structure[$table] = DB::getSchemaBuilder()->getTableBlueprint($table);
        }

        Storage::put(
            $this->getStructureBackupPath($backupId),
            encrypt(json_encode($structure))
        );
    }

    private function backupFiles(string $backupId): void
    {
        $criticalPaths = ['media', 'config', 'certificates'];
        
        foreach ($criticalPaths as $path) {
            Storage::copy(
                $path,
                $this->getFileBackupPath($backupId, $path)
            );
        }
    }

    private function verifyBackup(string $backupId, array $manifest): void
    {
        // Verify database backups
        foreach ($this->criticalTables as $table) {
            $path = $this->getTableBackupPath($backupId, $table);
            
            if (!Storage::exists($path)) {
                throw new BackupVerificationException("Missing backup for table: $table");
            }

            $data = json_decode(decrypt(Storage::get($path)), true);
            if (!$this->verifyTableBackup($table, $data)) {
                throw new BackupVerificationException("Invalid backup for table: $table");
            }
        }

        // Verify file backups
        foreach ($manifest['files'] as $file => $hash) {
            $path = $this->getFileBackupPath($backupId, $file);
            
            if (!Storage::exists($path)) {
                throw new BackupVerificationException("Missing file backup: $file");
            }

            if (hash_file('sha256', Storage::path($path)) !== $hash) {
                throw new BackupVerificationException("File backup corrupted: $file");
            }
        }
    }

    private function createManifest(string $backupId): array
    {
        return [
            'id' => $backupId,
            'timestamp' => time(),
            'database' => [
                'tables' => $this->criticalTables,
                'row_counts' => $this->getTableRowCounts()
            ],
            'files' => $this->getFileHashes(),
            'checksum' => null // Will be set after backup completion
        ];
    }

    private function getTableRowCounts(): array
    {
        $counts = [];
        foreach ($this->criticalTables as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        return $counts;
    }

    private function getFileHashes(): array
    {
        $hashes = [];
        $criticalPaths = ['media', 'config', 'certificates'];
        
        foreach ($criticalPaths as $path) {
            if (Storage::exists($path)) {
                $hashes[$path] = hash_file('sha256', Storage::path($path));
            }
        }
        
        return $hashes;
    }

    private function generateBackupId(): string
    {
        return date('Y-m-d_H-i-s') . '_' . substr(md5(uniqid()), 0, 8);
    }

    private function getTableBackupPath(string $backupId, string $table): string
    {
        return "{$this->backupPath}/{$backupId}/database/{$table}.json.enc";
    }

    private function getStructureBackupPath(string $backupId): string
    {
        return "{$this->backupPath}/{$backupId}/database/structure.json.enc";
    }

    private function getFileBackupPath(string $backupId, string $path): string
    {
        return "{$this->backupPath}/{$backupId}/files/{$path}";
    }

    private function updateBackupRegistry(string $backupId, array $manifest): void
    {
        $registry = Cache::get('backup_registry', []);
        $registry[$backupId] = [
            'timestamp' => $manifest['timestamp'],
            'status' => 'complete',
            'manifest' => $manifest
        ];
        Cache::put('backup_registry', $registry, 86400 * 30); // 30 days
    }

    private function cleanupFailedBackup(string $backupId): void
    {
        Storage::deleteDirectory("{$this->backupPath}/{$backupId}");
        
        $registry = Cache::get('backup_registry', []);
        unset($registry[$backupId]);
        Cache::put('backup_registry', $registry, 86400 * 30);
    }

    private function verifyTableBackup(string $table, array $data): bool
    {
        $currentCount = DB::table($table)->count();
        $backupCount = count($data);
        
        return abs($currentCount - $backupCount) <= ($currentCount * 0.01); // 1% tolerance
    }
}

class BackupOperation implements CriticalOperation
{
    private string $type;
    private array $data;
    private \Closure $operation;

    public function __construct(string $type, array $data, \Closure $operation)
    {
        $this->type = $type;
        $this->data = $data;
        $this->operation = $operation;
    }

    public function execute(): mixed
    {
        return ($this->operation)();
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getData(): array
    {
        return $this->data;
    }
}
