<?php

namespace App\Core\Media;

use App\Core\Security\SecurityManager;
use App\Core\Storage\StorageManager;
use App\Core\Monitoring\SystemMonitor;
use App\Core\Exceptions\MediaException;

class MediaManager implements MediaInterface 
{
    private SecurityManager $security;
    private StorageManager $storage;
    private SystemMonitor $monitor;
    private array $config;

    public function __construct(
        SecurityManager $security,
        StorageManager $storage,
        SystemMonitor $monitor,
        array $config
    ) {
        $this->security = $security;
        $this->storage = $storage;
        $this->monitor = $monitor;
        $this->config = $config;
    }

    public function processUpload(UploadedFile $file): MediaFile
    {
        $monitoringId = $this->monitor->startOperation('media_upload');
        
        try {
            $this->validateUpload($file);
            
            DB::beginTransaction();
            
            $mediaFile = $this->createMediaFile($file);
            $this->processFile($file, $mediaFile);
            $this->generateVariants($mediaFile);
            
            DB::commit();
            
            $this->monitor->recordSuccess($monitoringId);
            return $mediaFile;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->monitor->recordFailure($monitoringId, $e);
            throw new MediaException('Media upload failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function deleteMedia(int $mediaId): bool
    {
        $monitoringId = $this->monitor->startOperation('media_delete');
        
        try {
            $mediaFile = $this->getMediaFile($mediaId);
            
            $this->validateMediaAccess($mediaFile, 'delete');
            
            DB::beginTransaction();
            
            $this->deleteMediaFiles($mediaFile);
            $this->deleteMediaRecord($mediaFile);
            
            DB::commit();
            
            $this->monitor->recordSuccess($monitoringId);
            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->monitor->recordFailure($monitoringId, $e);
            throw new MediaException('Media deletion failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function optimizeMedia(int $mediaId, array $options = []): MediaFile
    {
        $monitoringId = $this->monitor->startOperation('media_optimize');
        
        try {
            $mediaFile = $this->getMediaFile($mediaId);
            
            $this->validateMediaAccess($mediaFile, 'optimize');
            
            DB::beginTransaction();
            
            $this->createBackup($mediaFile);
            $optimized = $this->performOptimization($mediaFile, $options);
            $this->updateMediaRecord($mediaFile, $optimized);
            
            DB::commit();
            
            $this->monitor->recordSuccess($monitoringId);
            return $mediaFile;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->monitor->recordFailure($monitoringId, $e);
            throw new MediaException('Media optimization failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function validateUpload(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new MediaException('Invalid upload file');
        }

        if (!$this->isAllowedType($file)) {
            throw new MediaException('File type not allowed');
        }

        if (!$this->isAllowedSize($file)) {
            throw new MediaException('File size exceeds limit');
        }

        if (!$this->validateFileContent($file)) {
            throw new MediaException('File content validation failed');
        }
    }

    private function createMediaFile(UploadedFile $file): MediaFile
    {
        $mediaFile = new MediaFile([
            'filename' => $this->generateFilename($file),
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'hash' => hash_file('sha256', $file->getRealPath()),
            'user_id' => auth()->id(),
            'status' => 'processing'
        ]);
        
        $mediaFile->save();
        return $mediaFile;
    }

    private function processFile(UploadedFile $file, MediaFile $mediaFile): void
    {
        $path = $this->getStoragePath($mediaFile);
        
        $file = $this->sanitizeFile($file);
        $this->storage->store($path, $file, [
            'visibility' => 'private',
            'metadata' => $this->getFileMetadata($file)
        ]);
        
        $mediaFile->path = $path;
        $mediaFile->save();
    }

    private function generateVariants(MediaFile $mediaFile): void
    {
        foreach ($this->config['variants'] as $variant => $config) {
            if ($this->shouldGenerateVariant($mediaFile, $variant)) {
                $this->generateVariant($mediaFile, $variant, $config);
            }
        }
    }

    private function validateMediaAccess(MediaFile $mediaFile, string $action): void
    {
        if (!$this->security->validateMediaAccess($mediaFile, $action)) {
            throw new MediaException('Access denied');
        }
    }

    private function deleteMediaFiles(MediaFile $mediaFile): void
    {
        $this->storage->delete($mediaFile->path);
        
        foreach ($mediaFile->variants as $variant) {
            $this->storage->delete($variant->path);
        }
    }

    private function deleteMediaRecord(MediaFile $mediaFile): void
    {
        $mediaFile->variants()->delete();
        $mediaFile->delete();
    }

    private function createBackup(MediaFile $mediaFile): void
    {
        $backupPath = $this->getBackupPath($mediaFile);
        
        $this->storage->copy(
            $mediaFile->path,
            $backupPath
        );
    }

    private function performOptimization(MediaFile $mediaFile, array $options): array
    {
        $optimizer = $this->getOptimizer($mediaFile->mime_type);
        return $optimizer->optimize($mediaFile->path, $options);
    }

    private function updateMediaRecord(MediaFile $mediaFile, array $optimized): void
    {
        $mediaFile->update([
            'size' => $optimized['size'],
            'hash' => $optimized['hash'],
            'metadata' => array_merge(
                $mediaFile->metadata ?? [],
                ['optimization' => $optimized['metadata']]
            )
        ]);
    }

    private function isAllowedType(UploadedFile $file): bool
    {
        return in_array(
            $file->getMimeType(),
            $this->config['allowed_types']
        );
    }

    private function isAllowedSize(UploadedFile $file): bool
    {
        return $file->getSize() <= $this->config['max_size'];
    }

    private function validateFileContent(UploadedFile $file): bool
    {
        $validator = $this->getContentValidator($file->getMimeType());
        return $validator->validate($file);
    }

    private function sanitizeFile(UploadedFile $file): UploadedFile
    {
        $sanitizer = $this->getFileSanitizer($file->getMimeType());
        return $sanitizer->sanitize($file);
    }

    private function generateFilename(UploadedFile $file): string
    {
        return sprintf(
            '%s_%s.%s',
            uniqid('media_', true),
            hash('crc32', $file->getClientOriginalName()),
            $file->getClientOriginalExtension()
        );
    }

    private function getStoragePath(MediaFile $mediaFile): string
    {
        return sprintf(
            '%s/%s/%s',
            $this->config['storage_path'],
            date('Y/m/d'),
            $mediaFile->filename
        );
    }

    private function getFileMetadata(UploadedFile $file): array
    {
        return [
            'uploaded_at' => now(),
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize()
        ];
    }

    private function shouldGenerateVariant(MediaFile $mediaFile, string $variant): bool
    {
        return in_array(
            $mediaFile->mime_type,
            $this->config['variants'][$variant]['supported_types']
        );
    }

    private function generateVariant(MediaFile $mediaFile, string $variant, array $config): void
    {
        $generator = $this->getVariantGenerator($mediaFile->mime_type);
        
        $variantFile = $generator->generate(
            $mediaFile->path,
            $variant,
            $config
        );
        
        $this->storeVariant($mediaFile, $variant, $variantFile);
    }

    private function getContentValidator(string $mimeType): ContentValidatorInterface
    {
        $class = $this->config['validators'][$mimeType] ?? null;
        
        if (!$class || !class_exists($class)) {
            throw new MediaException("Validator not found for type: {$mimeType}");
        }
        
        return new $class();
    }

    private function getFileSanitizer(string $mimeType): FileSanitizerInterface
    {
        $class = $this->config['sanitizers'][$mimeType] ?? null;
        
        if (!$class || !class_exists($class)) {
            throw new MediaException("Sanitizer not found for type: {$mimeType}");
        }
        
        return new $class();
    }

    private function getOptimizer(string $mimeType): MediaOptimizerInterface
    {
        $class = $this->config['optimizers'][$mimeType] ?? null;
        
        if (!$class || !class_exists($class)) {
            throw new MediaException("Optimizer not found for type: {$mimeType}");
        }
        
        return new $class();
    }

    private function getVariantGenerator(string $mimeType): VariantGeneratorInterface
    {
        $class = $this->config['generators'][$mimeType] ?? null;
        
        if (!$class || !class_exists($class)) {
            throw new MediaException("Generator not found for type: {$mimeType}");
        }
        
        return new $class();
    }

    private function getBackupPath(MediaFile $mediaFile): string
    {
        return sprintf(
            '%s/backups/%s_%s',
            $this->config['storage_path'],
            date('YmdHis'),
            $mediaFile->filename
        );
    }

    private function storeVariant(MediaFile $mediaFile, string $variant, UploadedFile $file): void
    {
        $path = sprintf(
            '%s/variants/%s_%s',
            dirname($mediaFile->path),
            $variant,
            basename($mediaFile->path)
        );
        
        $this->storage->store($path, $file, [
            'visibility' => 'private',
            'metadata' => [
                'variant' => $variant,
                'original_id' => $mediaFile->id,
                'generated_at' => now()
            ]
        ]);
        
        MediaVariant::create([
            'media_id' => $mediaFile->id,
            'variant' => $variant,
            'path' => $path,
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'hash' => hash_file('sha256', $file->getRealPath())
        ]);
    }
}
