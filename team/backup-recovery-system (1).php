<?php

namespace App\Core\Recovery;

use Illuminate\Support\Facades\{DB, Storage, Log, Cache};
use App\Core\Security\SecurityManager;
use App\Core\Services\{ValidationService, EncryptionService, NotificationService};
use App\Core\Exceptions\{BackupException, RecoveryException};

class BackupManager implements BackupInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private EncryptionService $encryption;
    private NotificationService $notifier;

    private const CHUNK_SIZE = 1000;
    private const BACKUP_RETENTION = 30;
    private const CRITICAL_TABLES = [
        'users',
        'roles',
        'permissions',
        'content',
        'audit_logs'
    ];

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        EncryptionService $encryption,
        NotificationService $notifier
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->notifier = $notifier;
    }

    public function createBackup(string $type = 'full'): string
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeBackup($type),
            ['action' => 'backup.create', 'type' => $type]
        );
    }

    protected function executeBackup(string $type): string
    {
        $backupId = $this->generateBackupId();
        $timestamp = now();
        
        DB::beginTransaction();
        try {
            // Create backup record
            $backup = DB::table('backups')->insertGetId([
                'backup_id' => $backupId,
                'type' => $type,
                'status' => 'in_progress',
                'started_at' => $timestamp
            ]);

            // Backup database
            $tables = $type === 'full' ? 
                $this->getAllTables() : 
                self::CRITICAL_TABLES;

            foreach ($tables as $table) {
                $this->backupTable($backupId, $table);
            }

            // Backup files if full backup
            if ($type === 'full') {
                $this->backupFiles($backupId);
            }

            // Update backup status
            DB::table('backups')
                ->where('id', $backup)
                ->update([
                    'status' => 'completed',
                    'completed_at' => now()
                ]);

            DB::commit();

            // Cleanup old backups
            $this->cleanupOldBackups();

            return $backupId;

        } catch (\Exception $e) {
            DB::rollBack();
            
            $this->handleBackupFailure($backupId, $e);
            
            throw new BackupException(
                'Backup failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    public function restore(string $backupId): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeRestore($backupId),
            ['action' => 'backup.restore', 'backup_id' => $backupId]
        );
    }

    protected function executeRestore(string $backupId): bool
    {
        $backup = DB::table('backups')
            ->where('backup_id', $backupId)
            ->where('status', 'completed')
            ->first();

        if (!$backup) {
            throw new RecoveryException('Invalid or incomplete backup');
        }

        DB::beginTransaction();
        try {
            // Create restore point
            $restorePoint = $this->createRestorePoint();

            // Restore database
            $backupPath = $this->getBackupPath($backupId);
            $tables = $this->getBackupTables($backupId);

            foreach ($tables as $table) {
                $this->restoreTable($backupId, $table);
            }

            // Restore files for full backups
            if ($backup->type === 'full') {
                $this->restoreFiles($backupId);
            }

            // Verify integrity
            $this->verifyRestore($backupId);

            DB::commit();

            // Clear all cache
            Cache::flush();

            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            
            // Attempt to restore from restore point
            $this->restoreFromPoint($restorePoint);
            
            throw new RecoveryException(
                'Restore failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    protected function backupTable(string $backupId, string $table): void
    {
        $path = $this->getBackupPath($backupId) . "/{$table}.sql";
        
        DB::table($table)
            ->orderBy('id')
            ->chunk(self::CHUNK_SIZE, function($records) use ($path) {
                $data = $this->encryption->encrypt(
                    json_encode($records)
                );
                
                Storage::append($path, $data . PHP_EOL);
            });

        // Create checksum
        $checksum = hash_file('sha256', storage_path($path));
        
        DB::table('backup_metadata')->insert([
            'backup_id' => $backupId,
            'table_name' => $table,
            'checksum' => $checksum
        ]);
    }

    protected function restoreTable(string $backupId, string $table): void
    {
        $path = $this->getBackupPath($backupId) . "/{$table}.sql";
        
        // Verify checksum
        $checksum = hash_file('sha256', storage_path($path));
        $metadata = DB::table('backup_metadata')
            ->where('backup_id', $backupId)
            ->where('table_name', $table)
            ->first();

        if ($checksum !== $metadata->checksum) {
            throw new RecoveryException("Backup integrity check failed for table: {$table}");
        }

        // Clear table
        DB::table($table)->truncate();

        // Restore data
        $handle = fopen(storage_path($path), 'r');
        while (($line = fgets($handle)) !== false) {
            $data = json_decode(
                $this->encryption->decrypt(trim($line)),
                true
            );
            
            DB::table($table)->insert($data);
        }
        fclose($handle);
    }

    protected function createRestorePoint(): string
    {
        $pointId = uniqid('restore_', true);
        
        // Backup current state
        $this->createBackup('restore_point_' . $pointId);
        
        return $pointId;
    }

    protected function restoreFromPoint(string $pointId): void
    {
        try {
            $this->restore('restore_point_' . $pointId);
        } catch (\Exception $e) {
            Log::critical('Failed to restore from restore point', [
                'point_id' => $pointId,
                'error' => $e->getMessage()
            ]);
        }
    }

    protected function verifyRestore(string $backupId): void
    {
        $tables = $this->getBackupTables($backupId);
        
        foreach ($tables as $table) {
            $originalCount = DB::table('backup_metadata')
                ->where('backup_id', $backupId)
                ->where('table_name', $table)
                ->value('record_count');

            $restoredCount = DB::table($table)->count();

            if ($originalCount !== $restoredCount) {
                throw new RecoveryException(
                    "Restore verification failed for table: {$table}"
                );
            }
        }
    }

    protected function cleanupOldBackups(): void
    {
        $oldBackups = DB::table('backups')
            ->where('created_at', '<', now()->subDays(self::BACKUP_RETENTION))
            ->where('type', 'not like', 'restore_point_%')
            ->get();

        foreach ($oldBackups as $backup) {
            Storage::deleteDirectory(
                $this->getBackupPath($backup->backup_id)
            );
            
            DB::table('backups')
                ->where('id', $backup->id)
                ->delete();
        }
    }

    protected function generateBackupId(): string
    {
        return uniqid('backup_', true);
    }

    protected function getBackupPath(string $backupId): string
    {
        return "backups/{$backupId}";
    }

    protected function getAllTables(): array
    {
        return DB::connection()
            ->getDoctrineSchemaManager()
            ->listTableNames();
    }

    protected function getBackupTables(string $backupId): array
    {
        return DB::table('backup_metadata')
            ->where('backup_id', $backupId)
            ->pluck('table_name')
            ->toArray();
    }

    protected function handleBackupFailure(string $backupId, \Exception $e): void
    {
        Log::error('Backup failed', [
            'backup_id' => $backupId,
            'error' => $e->getMessage()
        ]);

        DB::table('backups')
            ->where('backup_id', $backupId)
            ->update([
                'status' => 'failed',
                'error' => $e->getMessage()
            ]);

        $this->notifier->sendAlert('Backup failed', [
            'backup_id' => $backupId,
            'error' => $e->getMessage()
        ]);
    }
}
