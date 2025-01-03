<?php

namespace App\Core\Infrastructure;

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
            $this->validatePath($path);
            $this->validateContent($content);
            $this->security->validateStorageAccess($path, $options);
            
            $driver = $this->getDriver($options['disk'] ?? 'default');
            $encryptedContent = $this->encryptContent($content, $options);
            
            $metadata = $this->extractMetadata($content, $options);
            $result = $driver->put($path, $encryptedContent, $options);
            
            $this->storeMetadata($path, $metadata);
            $this->createBackup($path, $encryptedContent);
            
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
            $this->validatePath($path);
            $this->security->validateStorageAccess($path, $options);
            
            $driver = $this->getDriver($options['disk'] ?? 'default');
            $encryptedContent = $driver->get($path);
            
            $content = $this->decryptContent($encryptedContent, $options);
            $metadata = $this->retrieveMetadata($path);
            
            $this->validateContent($content);
            $this->verifyIntegrity($path, $content);
            
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
            $this->validatePath($path);
            $this->security->validateStorageAccess($path, $options);
            
            $backup = $this->getBackupVersion($path, $version);
            $this->validateBackup($backup);
            
            $driver = $this->getDriver($options['disk'] ?? 'default');
            $result = $driver->put($path, $backup->getContent(), $options);
            
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

    private function getDriver(string $disk): StorageDriver
    {
        if (!isset($this->drivers[$disk])) {
            $this->drivers[$disk] = $this->createDriver($disk);
        }
        
        return $this->drivers[$disk];
    }

    private function createDriver(string $disk): StorageDriver
    {
        $config = $this->getDriverConfig($disk);
        return new StorageDriver($config, $this->security);
    }

    private function encryptContent($content, array $options): string
    {
        if ($this->shouldEncrypt($options)) {
            return $this->security->encrypt(serialize($content));
        }
        
        return serialize($content);
    }

    private function decryptContent(string $content, array $options): mixed
    {
        if ($this->shouldEncrypt($options)) {
            return unserialize($this->security->decrypt($content));
        }
        
        return unserialize($content);
    }

    private function validatePath(string $path): void
    {
        if (empty($path)) {
            throw new StorageException('Empty storage path');
        }
    }

    private function validateContent($content): void
    {
        if (!is_serializable($content)) {
            throw new StorageException('Content must be serializable');
        }
    }

    private function handleStorageFailure(\Exception $e, string $operation, string $path