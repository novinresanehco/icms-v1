<?php

namespace App\Core\Media;

use App\Core\Security\SecurityManager;
use App\Core\Storage\StorageManager;
use App\Core\Monitoring\SystemMonitor;
use Illuminate\Http\UploadedFile;

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

    public function uploadMedia(UploadedFile $file, array $options = []): Media
    {
        $monitoringId = $this->monitor->startOperation('media_upload');
        
        try {
            $this->validateFile($file);
            $this->validateUploadQuota();
            
            $media = new Media([
                'filename' => $this->generateSecureFilename($file),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'checksum' => hash_file('sha256', $file->getPathname())
            ]);

            $path = $this->storage->store(
                $file,
                $this->getStoragePath($media),
                $this->getStorageOptions($options)
            );

            $media->path = $path;
            $media->save();

            $this->processMedia($media, $options);
            
            $this->monitor->recordSuccess($monitoringId);
            
            return $media;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new MediaException('Media upload failed', 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function deleteMedia(int $id): bool
    {
        $monitoringId = $this->monitor->startOperation('media_delete');
        
        try {
            $media = Media::findOrFail($id);
            
            $this->storage->delete($media->path);
            $this->deleteVariants($media);
            
            $media->delete();
            
            $this->monitor->recordSuccess($monitoringId);
            
            return true;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new MediaException('Media deletion failed', 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function optimizeMedia(int $id, array $options = []): Media
    {
        $monitoringId = $this->monitor->startOperation('media_optimize');
        
        try {
            $media = Media::findOrFail($id);
            
            $optimizedPath = $this->storage->copy(
                $media->path,
                $this->getOptimizedPath($media)
            );

            $this->applyOptimizations($optimizedPath, $options);
            
            $media->optimized_path = $optimizedPath;
            $media->optimized_size = filesize($optimizedPath);
            $media->save();
            
            $this->monitor->recordSuccess($monitoringId);
            
            return $media;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new MediaException('Media optimization failed', 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function validateFile(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new MediaValidationException('Invalid file upload');
        }

        $mime = $file->getMimeType();
        if (!in_array($mime, $this->config['allowed_mimes'])) {
            throw new MediaValidationException('Invalid file type');
        }

        if ($file->getSize() > $this->config['max_file_size']) {
            throw new MediaValidationException('File size exceeds limit');
        }
    }

    private function validateUploadQuota(): void
    {
        $totalUsage = Media::sum('size');
        $quota = $this->config['storage_quota'];
        
        if ($totalUsage >= $quota) {
            throw new MediaQuotaException('Storage quota exceeded');
        }
    }

    private function generateSecureFilename(UploadedFile $file): string
    {
        return sprintf(
            '%s_%s.%s',
            uniqid(),
            hash('sha256', $file->getClientOriginalName()),
            $file->getClientOriginalExtension()
        );
    }

    private function processMedia(Media $media, array $options): void
    {
        if ($this->shouldGenerateVariants($media)) {
            $this->generateVariants($media, $options);
        }

        if ($this->shouldOptimize($media)) {
            $this->optimizeMedia($media->id, $options);
        }

        if ($this->config['scan_viruses']) {
            $this->scanForViruses($media);
        }
    }

    private function generateVariants(Media $media, array $options): void
    {
        foreach ($this->config['variants'] as $variant => $config) {
            $variantPath = $this->generateVariant(
                $media->path,
                $config,
                $options
            );

            $media->variants()->create([
                'type' => $variant,
                'path' => $variantPath,
                'size' => filesize($variantPath)
            ]);
        }
    }

    private function scanForViruses(Media $media): void
    {
        $scanner = new VirusScanner($this->config['virus_scan']);
        
        if (!$scanner->scan($media->path)) {
            $this->storage->delete($media->path);
            throw new MediaSecurityException('Virus detected in uploaded file');
        }
    }
}
