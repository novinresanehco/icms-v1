<?php

namespace App\Core\Media;

use Illuminate\Support\Facades\{Storage, DB, Cache};
use App\Core\Security\{SecurityManager, Encryption};
use App\Core\Services\{ValidationService, AuditService};

class MediaManager implements MediaManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private AuditService $auditor;
    private Encryption $encryption;
    private array $config;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        AuditService $auditor,
        Encryption $encryption,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->auditor = $auditor;
        $this->encryption = $encryption;
        $this->config = $config;
    }

    public function upload(UploadedFile $file, array $context): MediaResult
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeUpload($file),
            $this->buildMediaContext('upload', $context)
        );
    }

    public function process(int $mediaId, array $operations, array $context): MediaResult
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeProcessing($mediaId, $operations),
            $this->buildMediaContext('process', $context, $mediaId)
        );
    }

    public function retrieve(int $mediaId, array $context): MediaResult
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeRetrieval($mediaId),
            $this->buildMediaContext('retrieve', $context, $mediaId)
        );
    }

    public function delete(int $mediaId, array $context): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeDeletion($mediaId),
            $this->buildMediaContext('delete', $context, $mediaId)
        );
    }

    protected function executeUpload(UploadedFile $file): MediaResult
    {
        $this->validateUpload($file);
        
        DB::beginTransaction();
        try {
            $path = $this->storeFile($file);
            $metadata = $this->extractMetadata($file);
            $mediaId = $this->createMediaRecord($path, $metadata);
            
            if ($this->requiresProcessing($file)) {
                $this->queueProcessing($mediaId, $file);
            }
            
            DB::commit();
            $this->invalidateCache();
            
            return new MediaResult($mediaId, $path, $metadata, true);
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleUploadFailure($file, $e);
            throw new MediaOperationException('Upload failed', 0, $e);
        }
    }

    protected function executeProcessing(int $mediaId, array $operations): MediaResult
    {
        $media = $this->findMedia($mediaId);
        if (!$media) {
            throw new MediaNotFoundException("Media {$mediaId} not found");
        }

        $this->validateOperations($operations);
        
        DB::beginTransaction();
        try {
            $result = $this->processFile($media, $operations);
            $this->updateMediaRecord($mediaId, $result);
            
            DB::commit();
            $this->invalidateCache($mediaId);
            
            return new MediaResult($mediaId, $result['path'], $result['metadata'], true);
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleProcessingFailure($mediaId, $operations, $e);
            throw new MediaOperationException('Processing failed', 0, $e);
        }
    }

    protected function executeRetrieval(int $mediaId): MediaResult
    {
        $media = $this->findMedia($mediaId);
        if (!$media) {
            throw new MediaNotFoundException("Media {$mediaId} not found");
        }

        try {
            $file = $this->retrieveFile($media->path);
            $metadata = $this->getMediaMetadata($media);
            
            return new MediaResult($mediaId, $media->path, $metadata, true, $file);
        } catch (\Exception $e) {
            $this->handleRetrievalFailure($mediaId, $e);
            throw new MediaOperationException('Retrieval failed', 0, $e);
        }
    }

    protected function executeDeletion(int $mediaId): bool
    {
        $media = $this->findMedia($mediaId);
        if (!$media) {
            throw new MediaNotFoundException("Media {$mediaId} not found");
        }

        DB::beginTransaction();
        try {
            $this->deleteFile($media->path);
            $this->deleteMediaRecord($mediaId);
            
            DB::commit();
            $this->invalidateCache($mediaId);
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleDeletionFailure($mediaId, $e);
            throw new MediaOperationException('Deletion failed', 0, $e);
        }
    }

    protected function validateUpload(UploadedFile $file): void
    {
        if (!$this->validator->validateMediaFile($file, $this->config['allowed_types'])) {
            throw new MediaValidationException('Invalid file type or format');
        }

        if ($file->getSize() > $this->config['max_size']) {
            throw new MediaValidationException('File size exceeds limit');
        }

        if ($this->containsMalware($file)) {
            throw new MediaSecurityException('Security check failed');
        }
    }

    protected function validateOperations(array $operations): void
    {
        foreach ($operations as $operation) {
            if (!$this->isValidOperation($operation)) {
                throw new MediaValidationException('Invalid operation requested');
            }
        }
    }

    protected function storeFile(UploadedFile $file): string
    {
        $name = $this->generateSecureFilename($file);
        $path = Storage::disk($this->config['storage_disk'])
            ->putFileAs(
                $this->getStoragePath(),
                $file,
                $name
            );

        if (!$path) {
            throw new MediaOperationException('File storage failed');
        }

        return $path;
    }

    protected function processFile(object $media, array $operations): array
    {
        $processor = $this->getProcessor($media->mime_type);
        $result = $processor->process($media->path, $operations);
        
        if (!$result['success']) {
            throw new MediaOperationException('Processing failed: ' . $result['error']);
        }

        return [
            'path' => $result['path'],
            'metadata' => array_merge($media->metadata, $result['metadata'])
        ];
    }

    protected function retrieveFile(string $path): mixed
    {
        if (!Storage::disk($this->config['storage_disk'])->exists($path)) {
            throw new MediaNotFoundException('File not found in storage');
        }

        return Storage::disk($this->config['storage_disk'])->get($path);
    }

    protected function deleteFile(string $path): void
    {
        if (Storage::disk($this->config['storage_disk'])->exists($path)) {
            Storage::disk($this->config['storage_disk'])->delete($path);
        }
    }

    protected function findMedia(int $id): ?object
    {
        return Cache::remember(
            "media:{$id}",
            $this->config['cache_ttl'],
            fn() => DB::table('media')->find($id)
        );
    }

    protected function containsMalware(UploadedFile $file): bool
    {
        return $this->security->scanFile($file->getPathname());
    }

    protected function isValidOperation(array $operation): bool
    {
        return isset($operation['type']) && 
               in_array($operation['type'], $this->config['allowed_operations']);
    }

    protected function generateSecureFilename(UploadedFile $file): string
    {
        return sprintf(
            '%s_%s.%s',
            uniqid('media_', true),
            hash('sha256', $file->getClientOriginalName()),
            $file->getClientOriginalExtension()
        );
    }

    protected function extractMetadata(UploadedFile $file): array
    {
        return [
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'hash' => hash_file('sha256', $file->getPathname())
        ];
    }
}

class MediaOperationException extends \RuntimeException {}
class MediaNotFoundException extends \RuntimeException {}
class MediaValidationException extends \RuntimeException {}
class MediaSecurityException extends \RuntimeException {}
