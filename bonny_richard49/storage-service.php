<?php

namespace App\Core\System;

use App\Core\Interfaces\StorageServiceInterface;
use Illuminate\Support\Facades\Storage;
use Psr\Log\LoggerInterface;

/**
 * Critical Storage Service Implementation
 * Handles all file storage operations with comprehensive safety measures
 */
class StorageService implements StorageServiceInterface 
{
    private LoggerInterface $logger;
    private array $config;
    private ?string $disk;

    // Critical operation constants
    private const RETRY_ATTEMPTS = 3;
    private const RETRY_DELAY = 100; // milliseconds
    private const CHUNK_SIZE = 1048576; // 1MB
    private const READ_TIMEOUT = 30;
    private const WRITE_TIMEOUT = 60;

    public function __construct(
        LoggerInterface $logger,
        ?string $disk = null
    ) {
        $this->logger = $logger;
        $this->config = config('filesystems');
        $this->disk = $disk;
    }

    /**
     * Retrieves a file with comprehensive error checking
     *
     * @throws StorageException
     */
    public function get(string $path): string 
    {
        return $this->retryOperation(function() use ($path) {
            if (!$this->exists($path)) {
                throw new StorageException("File not found: {$path}");
            }

            $content = Storage::disk($this->disk)->get($path);
            
            // Validate retrieved content
            if ($content === false || $content === null) {
                throw new StorageException("Failed to read file: {$path}"); 
            }

            return $content;
        });
    }

    /**
     * Stores file content with verification
     * 
     * @throws StorageException
     */
    public function put(string $path, string $contents): bool 
    {
        return $this->retryOperation(function() use ($path, $contents) {
            $result = Storage::disk($this->disk)->put($path, $contents);
            
            if ($result && !$this->verifyFile($path, $contents)) {
                throw new StorageException("File verification failed: {$path}");
            }
            
            return $result;
        });
    }

    /**
     * Stores an uploaded file with comprehensive validation
     *
     * @throws StorageException 
     */
    public function putFile(string $path, $file): string 
    {
        return $this->retryOperation(function() use ($path, $file) {
            // Validate file before storage
            if (!$this->validateUploadedFile($file)) {
                throw new StorageException('Invalid file upload');
            }
            
            $path = Storage::disk($this->disk)->putFile($path, $file);
            
            if (!$path || !$this->verifyFileUpload($path, $file)) {
                throw new StorageException('File upload failed');
            }
            
            return $path;
        });
    }

    /**
     * Deletes a file with verification
     *
     * @throws StorageException
     */
    public function delete(string $path): bool 
    {
        return $this->retryOperation(function() use ($path) {
            if (!$this->exists($path)) {
                return true;
            }

            $result = Storage::disk($this->disk)->delete($path);
            
            // Verify deletion
            if ($result && $this->exists($path)) {
                throw new StorageException("Failed to delete file: {$path}");
            }

            return $result;
        });
    }

    /**
     * Checks if file exists with retry logic
     */
    public function exists(string $path): bool 
    {
        return $this->retryOperation(function() use ($path) {
            return Storage::disk($this->disk)->exists($path);
        });
    }

    /**
     * Gets file size with validation
     *
     * @throws StorageException
     */
    public function size(string $path): int 
    {
        return $this->retryOperation(function() use ($path) {
            if (!$this->exists($path)) {
                throw new StorageException("File not found: {$path}");
            }

            $size = Storage::disk($this->disk)->size($path);
            
            if ($size === false || $size < 0) {
                throw new StorageException("Failed to get file size: {$path}");
            }

            return $size;
        });
    }

    /**
     * Gets last modified time with validation
     *
     * @throws StorageException
     */
    public function lastModified(string $path): int 
    {
        return $this->retryOperation(function() use ($path) {
            if (!$this->exists($path)) {
                throw new StorageException("File not found: {$path}");
            }

            $time = Storage::disk($this->disk)->lastModified($path);
            
            if ($time === false || $time <= 0) {
                throw new StorageException("Failed to get modification time: {$path}");
            }

            return $time;
        });
    }

    /**
     * Validates uploaded file
     */
    private function validateUploadedFile($file): bool 
    {
        if (!$file || !$file->isValid()) {
            return false;
        }

        // Check file size
        if ($file->getSize() <= 0 || $file->getSize() > $this->config['max_size']) {
            return false;
        }

        // Validate mime type
        if (!in_array($file->getMimeType(), $this->config['allowed_mimes'])) {
            return false;
        }

        return true;
    }

    /**
     * Verifies stored file matches original content
     */
    private function verifyFile(string $path, string $originalContent): bool 
    {
        try {
            $storedContent = $this->get($path);
            return hash_equals(
                hash('sha256', $originalContent),
                hash('sha256', $storedContent)
            );
        } catch (\Exception $e) {
            $this->logger->error('File verification failed', [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Verifies uploaded file was stored correctly
     */
    private function verifyFileUpload(string $path, $originalFile): bool 
    {
        try {
            // Verify file exists
            if (!$this->exists($path)) {
                return false;
            }

            // Verify size matches
            if ($this->size($path) !== $originalFile->getSize()) {
                return false;
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Upload verification failed', [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Retries operations with exponential backoff
     *
     * @throws StorageException
     */
    private function retryOperation(callable $operation) 
    {
        $attempts = 0;
        $lastException = null;

        while ($attempts < self::RETRY_ATTEMPTS) {
            try {
                return $operation();
            } catch (\Exception $e) {
                $lastException = $e;
                $attempts++;
                
                if ($attempts < self::RETRY_ATTEMPTS) {
                    usleep($this->getBackoffDelay($attempts));
                }
            }
        }

        throw new StorageException(
            'Operation failed after ' . self::RETRY_ATTEMPTS . ' attempts: ' . 
            $lastException->getMessage(),
            0,
            $lastException
        );
    }

    /**
     * Calculates exponential backoff delay
     */
    private function getBackoffDelay(int $attempt): int 
    {
        return (int) (self::RETRY_DELAY * pow(2, $attempt - 1));
    }
}
