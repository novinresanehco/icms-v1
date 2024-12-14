<?php

namespace App\Core\Media;

final class StorageService
{
    private FileSystem $filesystem;
    private EncryptionService $encryption;
    private ValidationService $validator;
    private AuditService $audit;

    public function __construct(
        FileSystem $filesystem,
        EncryptionService $encryption,
        ValidationService $validator,
        AuditService $audit
    ) {
        $this->filesystem = $filesystem;
        $this->encryption = $encryption;
        $this->validator = $validator;
        $this->audit = $audit;
    }

    public function store(ProcessedFile $file, string $directory): string
    {
        // Generate secure filename
        $filename = $this->generateSecureFilename($file);
        
        // Get full path
        $path = $this->getStoragePath($directory, $filename);
        
        // Encrypt file if required
        if ($this->shouldEncrypt($file)) {
            $file = $this->encryption->encryptFile($file);
        }
        
        // Store with validation
        try {
            $this->filesystem->put(
                $path,
                $file->getContents(),
                ['visibility' => 'private']
            );
            
            // Verify stored file
            $this->verifyStoredFile($path, $file);
            
            // Audit trail
            $this->audit->logFileStorage($path, $file);
            
            return $path;
            
        } catch (\Throwable $e) {
            $this->audit->logStorageFailure($path, $file, $e);
            throw $e;
        }
    }

    public function delete(string $path): bool
    {
        // Verify path
        if (!$this->validator->validatePath($path)) {
            throw new InvalidPathException('Invalid file path');
        }
        
        try {
            // Delete file
            $deleted = $this->filesystem->delete($path);
            
            // Verify deletion
            if ($this->filesystem->exists($path)) {
                throw new StorageException('File deletion failed');
            }
            
            // Audit trail
            $this->audit->logFileDeletion($path);
            
            return $deleted;
            
        } catch (\Throwable $e) {
            $this->audit->logDeletionFailure($path, $e);
            throw $e;
        }
    }

    public function get(string $path): File
    {
        // Verify path
        if (!$this->validator->validatePath($path)) {
            throw new InvalidPathException('Invalid file path');
        }
        
        try {
            // Get file
            $file = $this->filesystem->get($path);
            
            // Decrypt if encrypted
            if ($this->isEncrypted($path)) {
                $file = $this->encryption->decryptFile($file);
            }
            
            // Verify file integrity
            $this->verifyFileIntegrity($file, $path);
            
            // Audit trail
            $this->audit->logFileAccess($path);
            
            return $file;
            
        } catch (\Throwable $e) {
            $this->audit->logAccessFailure($path, $e);
            throw $e;
        }
    }

    private function generateSecureFilename(ProcessedFile $file): string
    {
        return sprintf(
            '%s_%s.%s',
            uniqid('file_', true),
            hash('sha256', $file->getContents()),
            $file->getExtension()
        );
    }

    private function getStoragePath(string $directory, string $filename): string
    {
        return sprintf(
            '%s/%s/%s',
            trim($directory, '/'),
            date('Y/m/d'),
            $filename
        );
    }

    private function shouldEncrypt(ProcessedFile $file): bool
    {
        return in_array(
            $file->getMimeType(),
            config('media.encrypt_types', [])
        );
    }

    private function verifyStoredFile(string $path, ProcessedFile $original): void
    {
        $stored = $this->filesystem->get($path);
        
        if (hash('sha256', $stored) !== hash('sha256', $original->getContents())) {
            throw new StorageException('File verification failed');
        }
    }

    private function verifyFileIntegrity(File $file, string $path): void
    {
        if (!$this->validator->validateFileIntegrity($file, $path)) {
            throw new IntegrityException('File integrity check failed');
        }
    }
}
