<?php

namespace App\Core\Storage;

use App\Core\Security\SecurityManager;
use App\Core\Monitoring\SystemMonitor;
use App\Core\Encryption\EncryptionService;
use App\Core\Exceptions\StorageException;

class StorageManager implements StorageInterface
{
    private SecurityManager $security;
    private SystemMonitor $monitor;
    private EncryptionService $encryption;
    private array $config;

    public function __construct(
        SecurityManager $security,
        SystemMonitor $monitor,
        EncryptionService $encryption,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->encryption = $encryption;
        $this->config = $config;
    }

    public function store(string $key, $data, array $options = []): bool
    {
        $monitoringId = $this->monitor->startOperation('storage_store');
        
        try {
            $this->validateStorageOperation($key, $data, $options);
            
            $secureData = $this->prepareForStorage($data, $options);
            
            $this->createBackup($key);
            
            $success = $this->performStore($key, $secureData, $options);
            
            if ($success) {
                $this->verifyStorage($key, $secureData);
                $this->monitor->recordSuccess($monitoringId);
            }
            
            return $success;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new StorageException('Storage operation failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function retrieve(string $key, array $options = []): mixed
    {
        $monitoringId = $this->monitor->startOperation('storage_retrieve');
        
        try {
            $this->validateRetrievalOperation($key, $options);
            
            $data = $this->performRetrieval($key, $options);
            
            $this->verifyDataIntegrity($key, $data);
            
            $decryptedData = $this->prepareForRetrieval($data, $options);
            
            $this->monitor->recordSuccess($monitoringId);
            
            return $decryptedData;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new StorageException('Retrieval operation failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function validateStorageOperation(string $key, $data, array $options): void
    {
        if (empty($key)) {
            throw new StorageException('Storage key cannot be empty');
        }

        if ($this->isKeyReserved($key)) {
            throw new StorageException('Storage key is reserved');
        }

        if (!$this->validateStorageQuota($data)) {
            throw new StorageException('Storage quota exceeded');
        }
    }

    private function prepareForStorage($data, array $options): mixed
    {
        if ($options['encrypt'] ?? $this->config['default_encryption']) {
            $data = $this->encryption->encrypt($data);
        }

        if ($options['compress'] ?? $this->config['default_compression']) {
            $data = $this->compressData($data);
        }

        return $data;
    }

    private function createBackup(string $key): void
    {
        if ($this->exists($key)) {
            $currentData = $this->performRetrieval($key, ['raw' => true]);
            $backupKey = $this->generateBackupKey($key);
            
            $this->performStore($backupKey, $currentData, [
                'temporary' => true,
                'expiration' => $this->config['backup_retention']
            ]);
        }
    }

    private function performStore(string $key, $data, array $options): bool
    {
        $path = $this->getStoragePath($key);
        
        if (!$this->ensureDirectoryExists(dirname($path))) {
            throw new StorageException('Failed to create storage directory');
        }

        $tempPath = $path . '.tmp';
        
        if (file_put_contents($tempPath, $data) === false) {
            throw new StorageException('Failed to write data to storage');
        }

        if (!rename($tempPath, $path)) {
            unlink($tempPath);
            throw new StorageException('Failed to finalize storage operation');
        }

        return true;
    }

    private function verifyStorage(string $key, $expectedData): void
    {
        $storedData = $this->performRetrieval($key, ['raw' => true]);
        
        if ($storedData !== $expectedData) {
            throw new StorageException('Storage verification failed');
        }
    }

    private function validateRetrievalOperation(string $key, array $options): void
    {
        if (!$this->exists($key)) {
            throw new StorageException('Storage key not found');
        }

        if (!$this->security->validateAccess($key, 'read')) {
            throw new StorageException('Access denied');
        }
    }

    private function performRetrieval(string $key, array $options): mixed
    {
        $path = $this->getStoragePath($key);
        
        if (!file_exists($path)) {
            throw new StorageException('Storage file not found');
        }

        $data = file_get_contents($path);
        
        if ($data === false) {
            throw new StorageException('Failed to read storage file');
        }

        return $data;
    }

    private function verifyDataIntegrity(string $key, $data): void
    {
        if (!$this->security->verifyDataIntegrity($key, $data)) {
            throw new StorageException('Data integrity check failed');
        }
    }

    private function prepareForRetrieval($data, array $options): mixed
    {
        if ($options['raw'] ?? false) {
            return $data;
        }

        if ($this->isCompressed($data)) {
            $data = $this->decompressData($data);
        }

        if ($this->isEncrypted($data)) {
            $data = $this->encryption->decrypt($data);
        }

        return $data;
    }

    private function isKeyReserved(string $key): bool
    {
        return in_array($key, $this->config['reserved_keys']);
    }

    private function validateStorageQuota($data): bool
    {
        $size = $this->calculateDataSize($data);
        $currentUsage = $this->getCurrentStorageUsage();
        
        return ($currentUsage + $size) <= $this->config['storage_quota'];
    }

    private function compressData($data): string
    {
        return gzencode($data, $this->config['compression_level']);
    }

    private function decompressData(string $data): string
    {
        return gzdecode($data);
    }

    private function generateBackupKey(string $key): string
    {
        return $key . '.backup.' . time();
    }

    private function getStoragePath(string $key): string
    {
        return $this->config['storage_path'] . '/' . $this->hashKey($key);
    }

    private function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }

    private function ensureDirectoryExists(string $path): bool
    {
        return is_dir($path) ||