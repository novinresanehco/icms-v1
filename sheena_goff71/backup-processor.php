<?php

namespace App\Core\Backup\Services;

use App\Core\Backup\Models\Backup;
use Illuminate\Support\Facades\{Storage, DB};
use ZipArchive;

class BackupProcessor
{
    public function process(Backup $backup): void
    {
        try {
            $backup->markAsProcessing();
            
            $filePath = $this->generateBackup($backup);
            $fileSize = Storage::disk($backup->disk)->size($filePath);
            
            $backup->markAsCompleted($filePath, $fileSize);
        } catch (\Exception $e) {
            $backup->markAsFailed($e->getMessage());
            throw $e;
        }
    }

    public function restore(Backup $backup): bool
    {
        try {
            $this->validateBackupFile($backup);
            $this->restoreFromBackup($backup);
            return true;
        } catch (\Exception $e) {
            logger()->error('Backup restore failed', [
                'backup_id' => $backup->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function verify(Backup $backup): bool
    {
        try {
            $this->validateBackupFile($backup);
            $backup->markAsVerified();
            return true;
        } catch (\Exception $e) {
            logger()->error('Backup verification failed', [
                'backup_id' => $backup->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    protected function generateBackup(Backup $backup): string
    {
        $tempPath = storage_path("app/temp/{$backup->id}");
        mkdir($tempPath, 0755, true);

        try {
            match($backup->type) {
                'database' => $this->backupDatabase($tempPath),
                'files' => $this->backupFiles($tempPath, $backup->options['paths'] ?? []),
                'full' => $this->backupFull($tempPath),
                default => throw new BackupException("Unknown backup type: {$backup->type}")
            };

            $zipPath = $this->createZipArchive($tempPath, $backup);
            $this->cleanup($tempPath);

            return $zipPath;
        } catch (\Exception $e) {
            $this->cleanup($tempPath);
            throw $e;
        }
    }

    protected function backupDatabase(string $path): void
    {
        $command = sprintf(
            'mysqldump -u%s -p%s %s > %s/database.sql',
            config('database.connections.mysql.username'),
            config('database.connections.mysql.password'),
            config('database.connections.mysql.database'),
            $path
        );

        exec($command);
    }

    protected function backupFiles(string $path, array $paths): void
    {
        foreach ($paths as $sourcePath) {
            $relativePath = str_replace(base_path(), '', $sourcePath);
            $targetPath = $path . $relativePath;

            if (!file_exists(dirname($targetPath))) {
                mkdir(dirname($targetPath), 0755, true);
            }

            if (is_dir($sourcePath)) {
                $this->copyDirectory($sourcePath, $targetPath);
            } else {
                copy($sourcePath, $targetPath);
            }
        }
    }

    protected function backupFull(string $path): void
    {
        $this->backupDatabase($path);
        $this->backupFiles($path, [base_path()]);
    }

    protected function createZipArchive(string $path, Backup $backup): string
    {
        $zip = new ZipArchive();
        $zipPath = "backups/{$backup->id}_{$backup->type}_" . date('Y-m-d_H-i-s') . '.zip';
        $zipFullPath = storage_path("app/{$zipPath}");

        if ($zip->open($zipFullPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new BackupException("Could not create zip archive");
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (!$file->isDir()) {
                $relativePath = substr($file->getPathname(), strlen($path) + 1);
                $zip->addFile($file->getPathname(), $relativePath);
            }
        }

        $zip->close();
        return $zipPath;
    }

    protected function cleanup(string $path): void
    {
        if (is_dir($path)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }

            rmdir($path);
        }
    }

    protected function copyDirectory(string $source, string $destination): void
    {
        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                mkdir($destination . DIRECTORY_SEPARATOR . $iterator->getSubPathname());
            } else {
                copy($item, $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathname());
            }
        }
    }

    protected function validateBackupFile(Backup $backup): void
    {
        if (!Storage::disk($backup->disk)->exists($backup->file_path)) {
            throw new BackupException('Backup file does not exist');
        }

        $zip = new ZipArchive();
        if ($zip->open(Storage::disk($backup->disk)->path($backup->file_path)) !== true) {
            throw new BackupException('Invalid backup archive');
        }
        $zip->close();
    }

    protected function restoreFromBackup(Backup $backup): void
    {
        $tempPath = storage_path("app/temp/restore_{$backup->id}");
        mkdir($tempPath, 0755, true);

        try {
            $zip = new ZipArchive();
            $zip->open(Storage::disk($backup->disk)->path($backup->file_path));
            $zip->extractTo($tempPath);
            $zip->close();

            match($backup->type) {
                'database' => $this->restoreDatabase($tempPath),
                'files' => $this->restoreFiles($tempPath),
                'full' => $this->restoreFull($tempPath),
                default => throw new BackupException("Unknown backup type: {$backup->type}")
            };

            $this->cleanup($tempPath);
        } catch (\Exception $e) {
            $this->cleanup($tempPath);
            throw $e;
        }
    }

    protecte