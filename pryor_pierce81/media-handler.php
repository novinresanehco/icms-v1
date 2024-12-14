<?php

namespace App\Core\Media;

class CriticalMediaHandler
{
    private $security;
    private $storage;
    private $monitor;

    public function handleUpload(UploadedFile $file, string $userId): string
    {
        $operationId = $this->monitor->startOperation('media_upload');

        try {
            // Security validation
            $this->security->validateFile($file);
            $this->security->validateUserAccess($userId, 'media.upload');

            // Process file
            $fileId = $this->processSecureUpload($file);

            $this->monitor->uploadSuccess($operationId);
            return $fileId;

        } catch (\Exception $e) {
            $this->monitor->uploadFailure($operationId, $e);
            throw $e;
        }
    }

    private function processSecureUpload(UploadedFile $file): string
    {
        return DB::transaction(function() use ($file) {
            // Generate secure ID
            $fileId = $this->generateSecureId();

            // Store encrypted file
            $path = $this->storeEncrypted($file, $fileId);

            // Store metadata
            $this->storeMetadata($fileId, $file, $path);

            return $fileId;
        });
    }

    private function storeEncrypted(UploadedFile $file, string $id): string
    {
        // Encrypt file content
        $encrypted = $this->security->encryptFile(
            file_get_contents($file->getRealPath())
        );

        // Store with secure name
        $path = "media/$id/" . hash('sha256', $file->getClientOriginalName());
        $this->storage->put($path, $encrypted);

        return $path;
    }
}
