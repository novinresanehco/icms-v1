<?php

namespace App\Core\Media;

use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Monitoring\MonitoringService;
use Illuminate\Support\Facades\{Storage, DB};

class MediaManager
{
    private SecurityManager $security;
    private ValidationService $validator;
    private MonitoringService $monitor;
    private ImageProcessor $imageProcessor;
    
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'application/pdf',
        'text/plain'
    ];
    
    private const MAX_FILE_SIZE = 10485760; // 10MB

    public function storeMedia(array $fileData): MediaEntity
    {
        return $this->executeMediaOperation('media:store', function() use ($fileData) {
            // Validate file
            $this->validateFile($fileData);
            
            // Process file
            $processedFile = $this->processFile($fileData);
            
            // Store with security
            $path = $this->securelySaveFile($processedFile);
            
            // Create record
            $media = $this->createMediaRecord([
                'path' => $path,
                'type' => $processedFile['mime_type'],
                'size' => $processedFile['size'],
                'hash' => $processedFile['hash']
            ]);
            
            return $media;
        });
    }

    public function retrieveMedia(int $id): MediaEntity
    {
        return $this->executeMediaOperation('media:retrieve', function() use ($id) {
            // Get record
            $media = $this->findMedia($id);
            
            // Validate access
            $this->security->validateAccess('media:retrieve', $media);
            
            // Verify integrity
            $this->verifyFileIntegrity($media);
            
            return $media;
        });
    }

    public function deleteMedia(int $id): void
    {
        $this->executeMediaOperation('media:delete', function() use ($id) {
            // Get record with locking
            $media = $this->findMediaWithLock($id);
            
            // Validate deletion
            $this->security->validateAccess('media:delete', $media);
            
            // Delete file
            $this->securelyDeleteFile($media->path);
            
            // Delete record
            $this->deleteMediaRecord($media);
        });
    }

    private function executeMediaOperation(string $operation, callable $action): mixed
    {
        $operationId = $this->monitor->startOperation($operation);
        
        try {
            // Validate context
            $this->security->validateContext();
            
            DB::beginTransaction();
            
            // Execute with monitoring
            $result = $this->monitor->track($operationId, $action);
            
            DB::commit();
            
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleMediaFailure($e, $operation);
            throw $e;
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    private function validateFile(array $fileData): void
    {
        // Validate mime type
        if (!in_array($fileData['type'], self::ALLOWED_MIME_TYPES)) {
            throw new MediaValidationException('Invalid file type');
        }

        // Validate size
        if ($fileData['size'] > self::MAX_FILE_SIZE) {
            throw new MediaValidationException('File too large');
        }

        // Security scan
        $this->security->scanFile($fileData['tmp_name']);
    }

    private function processFile(array $fileData): array
    {
        $file = [
            'content' => file_get_contents($fileData['tmp_name']),
            'mime_type' => $fileData['type'],
            'size' => $fileData['size']
        ];

        // Process images
        if (strpos($file['mime_type'], 'image/') === 0) {
            $file = $this->imageProcessor->process($file);
        }

        // Generate hash
        $file['hash'] = hash_file('sha256', $fileData['tmp_name']);

        return $file;
    }

    private function securelySaveFile(array $file): string
    {
        // Generate secure path
        $path = $this->generateSecurePath($file);
        
        // Encrypt if needed
        if ($this->requiresEncryption($file['mime_type'])) {
            $file['content'] = $this->security->encryptFile($file['content']);
        }
        
        // Store file
        Storage::put($path, $file['content']);
        
        return $path;
    }

    private function generateSecurePath(array $file): string
    {
        return sprintf(
            '%s/%s/%s.%s',
            date('Y/m'),
            bin2hex(random_bytes(16)),
            $file['hash'],
            $this->getExtensionFromMime($file['mime_type'])
        );
    }

    private function requiresEncryption(string $mimeType): bool
    {
        return !in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif']);
    }

    private function getExtensionFromMime(string $mimeType): string
    {
        return [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'application/pdf' => 'pdf',
            'text/plain' => 'txt'
        ][$mimeType] ?? 'bin';
    }

    private function verifyFileIntegrity(MediaEntity $media): void
    {
        $path = Storage::path($media->path);
        $currentHash = hash_file('sha256', $path);
        
        if ($currentHash !== $media->hash) {
            throw new MediaIntegrityException('File integrity check failed');
        }
    }

    private function findMedia(int $id): MediaEntity
    {
        $media = MediaEntity::find($id);
        
        if (!$media) {
            throw new MediaNotFoundException("Media {$id} not found");
        }
        
        return $media;
    }

    private function findMediaWithLock(int $id): MediaEntity
    {
        $media = MediaEntity::lockForUpdate()->find($id);
        
        if (!$media) {
            throw new MediaNotFoundException("Media {$id} not found");
        }
        
        return $media;
    }

    private function createMediaRecord(array $data): MediaEntity
    {
        $media = new MediaEntity($data);
        $media->save();
        
        $this->monitor->logMediaOperation('create', $media);
        
        return $media;
    }

    private function deleteMediaRecord(MediaEntity $media): void
    {
        $media->delete();
        
        $this->monitor->logMediaOperation('delete', $media);
    }

    private function securelyDeleteFile(string $path): void
    {
        // Securely overwrite file before deletion
        $this->security->securelyDeleteFile($path);
        
        // Delete file
        Storage::delete($path);
    }

    private function handleMediaFailure(\Throwable $e, string $operation): void
    {
        $this->monitor->recordFailure('media_operation', [
            'operation' => $operation,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->security->notifyAdministrators('media_failure', [
            'operation' => $operation,
            'error' => $e->getMessage()
        ]);
    }
}
