<?php

namespace App\Core\Infrastructure;

/**
 * Critical Storage Management System
 * Handles all file system operations with comprehensive security, backup and monitoring
 */
class StorageManager implements StorageManagerInterface
{
    private SecurityManager $security;
    private DatabaseManager $database;
    private MetricsCollector $metrics;
    private AuditService $audit;
    private array $config;
    private array $drivers = [];

    public function __construct(
        SecurityManager $security,
        DatabaseManager $database,
        MetricsCollector $metrics,
        AuditService $audit,
        array $config
    ) {
        $this->security = $security;
        $this->database = $database;
        $this->metrics = $metrics;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function store(string $path, $content, array $options = []): StorageResult
    {
        $startTime = microtime(true);
        
        try {
            // Pre-store validation
            $this->validatePath($path);
            $this->validateContent($content);
            $this->security->validateStorageAccess($path, $options);
            
            // Get storage driver
            $driver = $this->getDriver($options['disk'] ?? 'default');
            
            // Encrypt content if required
            $encryptedContent = $this->encryptContent($content, $options);
            
            // Extract and store metadata
            $metadata = $this->extractMetadata($content, $options);
            $result = $driver->put($path, $encryptedContent, $options);
            
            // Store metadata and create backup
            $this->storeMetadata($path, $metadata);
            $this->createBackup($path, $encryptedContent);
            
            // Audit and metrics
            $this->audit->logStorageOperation('store', $path);
            $this->metrics->recordStorageOperation('store', microtime(true) - $startTime);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->handleStorageFailure($e, 'store', $path);
            throw new StorageException('Store operation failed', 0, $e);
        }
    }

    public function retrieve(string $path, array $options = []): StorageResult
    {
        try {
            // Validate request
            $this->validatePath($path);
            $this->security->validateStorageAccess($path, $options);
            
            // Get content
            $driver = $this->getDriver($options['disk'] ?? 'default');
            $encryptedContent = $driver->get($path);
            
            // Decrypt and validate
            $content = $this->decryptContent($encryptedContent, $options);
            $metadata = $this->retrieveMetadata($path);
            
            $this->validateContent($content);
            $this->verifyIntegrity($path, $content);
            
            // Audit
            $this->audit->logStorageOperation('retrieve', $path);
            
            return new StorageResult($content, $metadata);
            
        } catch (\Exception $e) {
            $this->handleStorageFailure($e, 'retrieve', $path);
            throw new StorageException('Retrieve operation failed', 0, $e);
        }
    }

    public function delete(string $path, array $options = []): bool
    {
        try {
            $this->validatePath($path);
            $this->security->validateStorageAccess($path, $options);
            
            $driver = $this->getDriver($options['disk'] ?? 'default');
            
            // Create backup before deletion
            $this->createBackupBeforeDelete($path);
            $result = $driver->delete($path);
            $this->deleteMetadata($path);
            
            $this->audit->logStorageOperation('delete', $path);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->handleStorageFailure($e, 'delete', $path);
            throw new StorageException('Delete operation failed', 0, $e);
        }
    }

    public function backup(string $disk = 'default'): bool
    {
        try {
            $driver = $this->getDriver($disk);
            $files = $driver->listContents('', true);
            
            foreach ($files as $file) {
                $this->backupFile($file, $disk);
            }
            
            $this->audit->logStorageOperation('backup', $disk);
            
            return true;
            
        } catch (\Exception $e) {
            $this->handleStorageFailure($e, 'backup', $disk);
            return false;
        }
    }

    public function restore(string $path, string $version, array $options = []): bool
    {
        try {
            // Validate restore request
            $this->validatePath($path);
            $this->security->validateStorageAccess($path, $options);
            
            // Get and validate backup
            $backup = $this->getBackupVersion($path, $version);
            $this->validateBackup($backup);
            
            // Restore content
            $driver = $this->getDriver($options['disk'] ?? 'default');
            $result = $driver->put($path, $backup->getContent(), $options);
            
            // Restore metadata
            $this->restoreMetadata($path, $version);
            
            $this->audit->logStorageOperation('restore', $path);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->handleStorageFailure($e, 'restore', $path);
            throw new StorageException('Restore operation failed', 0, $e);
        }
    }

    public function optimize(string $disk = 'default'): bool
    {
        try {
            $driver = $this->getDriver($disk);
            
            // Perform optimization
            $this->optimizeStorage($driver);
            $this->optimizeMetadata($disk);
            $this->cleanupBackups($disk);
            
            $this->audit->logStorageOperation('optimize', $disk);
            
            return true;
            
        } catch (\Exception $e) {
            $this->handleStorageFailure($e, 'optimize', $disk);
            return false;
        }
    }

    private function handleStorageFailure(\Exception $e, string $operation, string $path): void
    {
        // Log error with full context
        Log::error('Storage operation failed', [
            'operation' => $operation,
            'path' => $path,
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        // Notify monitoring system
        $this->metrics->recordFailure('storage.' . $operation);
        
        // Create failure audit log
        $this->audit->logStorageFailure($operation, $path, $e);
    }

    private function validateContent($content): void
    {
        if (!is_serializable($content)) {
            throw new StorageException('Content must be serializable');
        }
    }

    private function verifyIntegrity(string $path, $content): void
    {
        $metadata = $this->retrieveMetadata($path);
        if (!hash_equals($metadata['hash'], hash('sha256', serialize($content)))) {
            throw new StorageException('Content integrity check failed');
        }
    }

    private function optimizeStorage(StorageDriver $driver): void
    {
        // Implement storage optimization logic
        $driver->cleanup();
        $driver->defragment();
        $driver->optimizeIndexes();
    }

    private function optimizeMetadata(string $disk): void
    {
        // Implement metadata optimization
        $this->database->optimizeTable('storage_metadata_' . $disk);
    }

    private function cleanupBackups(string $disk): void
    {
        // Implement backup cleanup based on retention policy
        $retention = $this->config['backup_retention'] ?? 30;
        $this->database->cleanupBackups($disk, $retention);
    }
}
