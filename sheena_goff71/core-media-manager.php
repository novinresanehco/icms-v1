<?php

namespace App\Core\Media;

use Illuminate\Support\Facades\{DB, Cache, Storage};
use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Interfaces\{
    MediaManagerInterface,
    StorageInterface
};
use App\Core\Exceptions\{
    MediaException,
    SecurityException,
    ValidationException
};

class MediaManager implements MediaManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private StorageInterface $storage;
    private array $config;

    private const CACHE_PREFIX = 'media:';
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf', 'text/plain', 'application/json'
    ];

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        StorageInterface $storage,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->storage = $storage;
        $this->config = $config;
    }

    public function upload(array $file, array $context): array
    {
        return $this->security->executeSecureOperation(function() use ($file, $context) {
            $this->validateUpload($file);
            
            $processedFile = $this->processUpload($file);
            $metadata = $this->createMetadata($processedFile, $context);
            
            $mediaRecord = $this->storage->store($metadata);
            $this->invalidateCache($mediaRecord['type']);
            
            return $mediaRecord;
        }, $context);
    }

    public function process(int $id, array $options, array $context): array
    {
        return $this->security->executeSecureOperation(function() use ($id, $options, $context) {
            $media = $this->storage->find($id);
            if (!$media) {
                throw new MediaException('Media not found');
            }
            
            $this->validateProcessing($media, $options);
            $processedMedia = $this->processMedia($media, $options);
            
            $updatedRecord = $this->storage->update($id, $processedMedia);
            $this->invalidateCache($media['type']);
            
            return $updatedRecord;
        }, $context);
    }

    public function delete(int $id, array $context): bool
    {
        return $this->security->executeSecureOperation(function() use ($id, $context) {
            $media = $this->storage->find($id);
            if (!$media) {
                throw new MediaException('Media not found');
            }
            
            $this->validateDeletion($media);
            Storage::delete($media['path']);
            
            $success = $this->storage->delete($id);
            if ($success) {
                $this->invalidateCache($media['type']);
            }
            
            return $success;
        }, $context);
    }

    protected function validateUpload(array $file): void
    {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new ValidationException('Invalid upload');
        }

        if (!$this->isAllowedMimeType($file)) {
            throw new SecurityException('Invalid file type');
        }

        if (!$this->isWithinSizeLimit($file)) {
            throw new ValidationException('File too large');
        }

        $this->scanForThreats($file);
    }

    protected function validateProcessing(array $media, array $options): void
    {
        $allowedOperations = $this->config['allowed_operations'][$media['type']] ?? [];
        foreach ($options as $operation => $params) {
            if (!in_array($operation, $allowedOperations)) {
                throw new ValidationException("Operation not allowed: {$operation}");
            }
        }
    }

    protected function validateDeletion(array $media): void
    {
        if ($media['in_use'] ?? false) {
            throw new MediaException('Media in use');
        }
    }

    protected function processUpload(array $file): array
    {
        $path = $this->generatePath($file);
        $moved = Storage::putFileAs(
            dirname($path),
            $file['tmp_name'],
            basename($path)
        );

        if (!$moved) {
            throw new MediaException('Failed to store file');
        }

        return [
            'path' => $path,
            'mime_type' => $file['type'],
            'size' => $file['size'],
            'original_name' => $file['name']
        ];
    }

    protected function processMedia(array $media, array $options): array
    {
        $result = $media;

        foreach ($options as $operation => $params) {
            $result = $this->applyOperation($result, $operation, $params);
        }

        return $result;
    }

    protected function createMetadata(array $file, array $context): array
    {
        return [
            'type' => $this->determineMediaType($file),
            'path' => $file['path'],
            'mime_type' => $file['mime_type'],
            'size' => $file['size'],
            'original_name' => $file['original_name'],
            'uploaded_by' => $context['user_id'] ?? null,
            'uploaded_at' => time(),
            'hash' => $this->generateFileHash($file['path'])
        ];
    }

    protected function isAllowedMimeType(array $file): bool
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        return in_array($mimeType, self::ALLOWED_MIME_TYPES);
    }

    protected function isWithinSizeLimit(array $file): bool
    {
        return $file['size'] <= ($this->config['max_file_size'] ?? 10485760);
    }

    protected function scanForThreats(array $file): void
    {
        if ($this->config['virus_scan_enabled'] ?? false) {
            // Integrate with virus scanning service
            if (!$this->performVirusScan($file['tmp_name'])) {
                throw new SecurityException('File failed security scan');
            }
        }
    }

    protected function generatePath(array $file): string
    {
        $hash = hash_file('sha256', $file['tmp_name']);
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        
        return sprintf(
            'media/%s/%s/%s.%s',
            date('Y/m'),
            substr($hash, 0, 2),
            $hash,
            $ext
        );
    }

    protected function generateFileHash(string $path): string
    {
        return hash_file('sha256', Storage::path($path));
    }

    protected function determineMediaType(array $file): string
    {
        return match (true) {
            str_starts_with($file['mime_type'], 'image/') => 'image',
            str_starts_with($file['mime_type'], 'video/') => 'video',
            $file['mime_type'] === 'application/pdf' => 'document',
            default => 'file'
        };
    }

    protected function applyOperation(array $media, string $operation, array $params): array
    {
        return match ($operation) {
            'resize' => $this->resizeImage($media, $params),
            'compress' => $this->compressFile($media, $params),
            'convert' => $this->convertFormat($media, $params),
            default => $media
        };
    }

    protected function invalidateCache(string $type): void
    {
        Cache::tags([self::CACHE_PREFIX . $type])->flush();
    }

    private function performVirusScan(string $path): bool
    {
        // Implementation depends on virus scanning service
        return true;
    }
}
