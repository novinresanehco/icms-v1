<?php

namespace App\Core\Storage;

use App\Core\Security\SecurityManager;
use App\Core\Metrics\MetricsCollector;
use App\Core\Encryption\EncryptionService;
use App\Core\Validation\ValidationService;
use App\Core\Logging\AuditLogger;

class StorageManager implements StorageInterface
{
    private SecurityManager $security;
    private MetricsCollector $metrics;
    private EncryptionService $encryption;
    private ValidationService $validator;
    private AuditLogger $logger;

    public function store(string $path, $content, array $options = []): string
    {
        $fileId = $this->generateFileId();
        
        try {
            $this->validateStorageRequest($path, $content, $options);
            $this->security->validateAccess('storage.write');

            $encrypted = $this->shouldEncrypt($options) ? 
                        $this->encryption->encrypt($content) : 
                        $content;

            $stored = $this->writeToStorage($fileId, $path, $encrypted, $options);
            $this->processPostStore($stored, $options);

            return $fileId;

        } catch (\Exception $e) {
            $this->handleStorageFailure($e, $path, $options);
            throw $e;
        }
    }

    public function retrieve(string $path, array $options = []): mixed
    {
        try {
            $this->validateRetrievalRequest($path, $options);
            $this->security->validateAccess('storage.read');

            $content = $this->readFromStorage($path);

            return $this->shouldEncrypt($options) ? 
                   $this->encryption->decrypt($content) : 
                   $content;

        } catch (\Exception $e) {
            $this->handleRetrievalFailure($e, $path, $options);
            throw $e;
        }
    }

    public function delete(string $path, array $options = []): bool
    {
        try {
            $this->validateDeletionRequest($path, $options);
            $this->security->validateAccess('storage.delete');

            if ($this->shouldBackup($options)) {
                $this->backupBeforeDelete($path);
            }

            $deleted = $this->removeFromStorage($path);
            $this->processPostDelete($path, $options);

            return $deleted;

        } catch (\Exception $e) {
            $this->handleDeletionFailure($e, $path, $options);
            throw $e;
        }
    }

    public function copy(string $source, string $destination, array $options = []): bool
    {
        try {
            $this->validateCopyRequest($source, $destination, $options);
            $this->security->validateAccess('storage.copy');

            $content = $this->retrieve($source);
            $this->store($destination, $content, $options);

            return true;

        } catch (\Exception $e) {
            $this->handleCopyFailure($e, $source, $destination, $options);
            throw $e;
        }
    }

    public function move(string $source, string $destination, array $options = []): bool
    {
        try {
            $this->validateMoveRequest($source, $destination, $options);
            $this->security->validateAccess('storage.move');

            DB::transaction(function() use ($source, $destination, $options) {
                $this->copy($source, $destination, $options);
                $this->delete($source, $options);
            });

            return true;

        } catch (\Exception $e) {
            $this->handleMoveFailure($e, $source, $destination, $options);
            throw $e;
        }
    }

    protected function writeToStorage(string $fileId, string $path, $content, array $options): bool
    {
        $metadata = $this->prepareMetadata($fileId, $path, $content, $options);
        
        DB::transaction(function() use ($path, $content, $metadata) {
            Storage::put($path, $content);
            $this->storeMetadata($metadata);
            $this->updateStorageMetrics('write', strlen($content));
        });

        $this->logger->info('File stored', [
            'file_id' => $fileId,
            'path' => $path,
            'size' => strlen($content)
        ]);

        return true;
    }

    protected function readFromStorage(string $path): mixed
    {
        if (!Storage::exists($path)) {
            throw new StorageException("File not found: {$path}");
        }

        $content = Storage::get($path);
        $this->updateStorageMetrics('read', strlen($content));

        return $content;
    }

    protected function removeFromStorage(string $path): bool
    {
        if (!Storage::exists($path)) {
            throw new StorageException("File not found: {$path}");
        }

        DB::transaction(function() use ($path) {
            Storage::delete($path);
            $this->deleteMetadata($path);
            $this->updateStorageMetrics('delete');
        });

        return true;
    }

    protected function backupBeforeDelete(string $path): void
    {
        $content = $this->retrieve($path);
        $backupPath = $this->generateBackupPath($path);
        
        $this->store($backupPath, $content, [
            'encrypt' => true,
            'metadata' => [
                'original_path' => $path,
                'backup_time' => time()
            ]
        ]);
    }

    protected function validateStorageRequest(string $path, $content, array $options): void
    {
        if (!$this->validator->validatePath($path)) {
            throw new StorageException('Invalid storage path');
        }

        if ($this->shouldValidateContent($options) && 
            !$this->validator->validateContent($content)) {
            throw new StorageException('Invalid content');
        }

        if (!$this->validator->validateStorageOptions($options)) {
            throw new StorageException('Invalid storage options');
        }
    }

    protected function processPostStore(bool $stored, array $options): void
    {
        if ($stored && $this->shouldProcess($options)) {
            if (isset($options['post_process'])) {
                foreach ($options['post_process'] as $process) {
                    $this->executePostProcess($process);
                }
            }
        }
    }

    protected function processPostDelete(string $path, array $options): void
    {
        if (isset($options['post_delete'])) {
            foreach ($options['post_delete'] as $process) {
                $this->executePostDelete($process, $path);
            }
        }
    }

    protected function shouldEncrypt(array $options): bool
    {
        return $options['encrypt'] ?? false;
    }

    protected function shouldBackup(array $options): bool
    {
        return $options['backup'] ?? true;
    }

    protected function shouldProcess(array $options): bool
    {
        return $options['process'] ?? false;
    }

    protected function shouldValidateContent(array $options): bool
    {
        return $options['validate_content'] ?? true;
    }

    protected function prepareMetadata(string $fileId, string $path, $content, array $options): array
    {
        return [
            'file_id' => $fileId,
            'path' => $path,
            'size' => strlen($content),
            'mime_type' => $this->getMimeType($content),
            'checksum' => hash('sha256', $content),
            'encrypted' => $this->shouldEncrypt($options),
            'created_at' => time(),
            'metadata' => $options['metadata'] ?? []
        ];
    }

    protected function updateStorageMetrics(string $operation, int $size = 0): void
    {
        $this->metrics->increment("storage.operations.{$operation}");
        
        if ($size > 0) {
            $this->metrics->increment('storage.bytes', $size);
        }
    }

    private function generateFileId(): string
    {
        return 'file_' . md5(uniqid(mt_rand(), true));
    }

    private function generateBackupPath(string $originalPath): string
    {
        return 'backups/' . date('Y/m/d/') . basename($originalPath);
    }
}
