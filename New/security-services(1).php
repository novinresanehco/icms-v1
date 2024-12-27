<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{DB, Log, Storage};

class BackupService
{
    protected string $backupPath;
    
    public function __construct()
    {
        $this->backupPath = storage_path('backups');
    }

    public function create(string $backupId): void
    {
        try {
            // Create backup directory
            if (!Storage::exists($this->backupPath)) {
                Storage::makeDirectory($this->backupPath);
            }

            // Dump database
            $filename = $this->backupPath . '/' . $backupId . '.sql';
            $command = sprintf(
                'mysqldump -u%s -p%s %s > %s',
                config('database.connections.mysql.username'),
                config('database.connections.mysql.password'),
                config('database.connections.mysql.database'),
                $filename
            );

            exec($command);

            // Verify backup
            if (!file_exists($filename)) {
                throw new BackupException('Backup file creation failed');
            }

        } catch (\Exception $e) {
            Log::critical('Backup creation failed', [
                'backup_id' => $backupId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function restore(string $backupId): void
    {
        try {
            $filename = $this->backupPath . '/' . $backupId . '.sql';

            if (!file_exists($filename)) {
                throw new BackupException('Backup file not found');
            }

            // Restore database
            $command = sprintf(
                'mysql -u%s -p%s %s < %s',
                config('database.connections.mysql.username'),
                config('database.connections.mysql.password'),
                config('database.connections.mysql.database'),
                $filename
            );

            exec($command);

        } catch (\Exception $e) {
            Log::critical('Backup restoration failed', [
                'backup_id' => $backupId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function remove(string $backupId): void
    {
        try {
            $filename = $this->backupPath . '/' . $backupId . '.sql';
            if (file_exists($filename)) {
                unlink($filename);
            }
        } catch (\Exception $e) {
            Log::error('Backup removal failed', [
                'backup_id' => $backupId,
                'error' => $e->getMessage()
            ]);
        }
    }
}

class AuditLogger
{
    protected string $logPath;
    
    public function __construct()
    {
        $this->logPath = storage_path('logs/audit');
    }

    public function log(string $event, array $data = []): void
    {
        try {
            if (!Storage::exists($this->logPath)) {
                Storage::makeDirectory($this->logPath);
            }

            $logEntry = [
                'timestamp' => now()->toIso8601String(),
                'event' => $event,
                'data' => $data,
                'user_id' => auth()->id(),
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent()
            ];

            $filename = $this->logPath . '/' . date('Y-m-d') . '.log';
            
            file_put_contents(
                $filename,
                json_encode($logEntry) . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );

        } catch (\Exception $e) {
            Log::error('Audit logging failed', [
                'event' => $event,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function getEntries(array $filters = []): array
    {
        $entries = [];
        $files = glob($this->logPath . '/*.log');

        foreach ($files as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $entry = json_decode($line, true);
                if ($this->matchesFilters($entry, $filters)) {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    protected function matchesFilters(array $entry, array $filters): bool
    {
        foreach ($filters as $key => $value) {
            if (!isset($entry[$key]) || $entry[$key] !== $value) {
                return false;
            }
        }
        return true;
    }
}

class BackupException extends \Exception
{
    // Custom backup exception
}