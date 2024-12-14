<?php

namespace App\Core\Media;

use Illuminate\Support\Facades\{Storage, DB};
use Illuminate\Http\UploadedFile;
use App\Core\Security\SecurityManager;
use App\Core\Services\{ValidationService, ImageProcessor};
use App\Core\Interfaces\MediaManagerInterface;
use App\Core\Exceptions\{MediaException, ValidationException};

class MediaManager implements MediaManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private ImageProcessor $imageProcessor;
    private array $config;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        ImageProcessor $imageProcessor,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->imageProcessor = $imageProcessor;
        $this->config = $config;
    }

    public function uploadMedia(UploadedFile $file, array $options = []): Media
    {
        return $this->security->executeSecureOperation(
            fn() => $this->processUpload($file, $options),
            new SecurityContext('media.upload', ['file' => $file->getClientOriginalName()])
        );
    }

    public function processMedia(int $mediaId, array $operations): Media
    {
        return $this->security->executeSecureOperation(
            fn() => $this->executeProcessing($mediaId, $operations),
            new SecurityContext('media.process', ['id' => $mediaId])
        );
    }

    public function deleteMedia(int $mediaId): bool
    {
        return $this->security->executeSecureOperation(
            fn() => $this->executeDelete($mediaId),
            new SecurityContext('media.delete', ['id' => $mediaId])
        );
    }

    protected function processUpload(UploadedFile $file, array $options): Media
    {
        DB::beginTransaction();
        try {
            $this->validateUpload($file);
            $secureFilename = $this->generateSecureFilename($file);
            
            $media = Media::create([
                'filename' => $secureFilename,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'hash' => $this->calculateFileHash($file),
                'metadata' => $this->extractMetadata($file),
                'user_id' => auth()->id()
            ]);

            $path = $this->storeFile($file, $secureFilename);
            $media->path = $path;

            if ($this->isImage($file)) {
                $this->processImage($media, $options);
            }

            if ($this->config['virus_scan_enabled']) {
                $this->scanFile($path);
            }

            $media->save();
            DB::commit();

            return $media;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->cleanupFailedUpload($secureFilename ?? null);
            throw new MediaException('Upload failed: ' . $e->getMessage());
        }
    }

    protected function executeProcessing(int $mediaId, array $operations): Media
    {
        DB::beginTransaction();
        try {
            $media = $this->getMediaForProcessing($mediaId);
            
            foreach ($operations as $operation) {
                $this->validateOperation($operation);
                $this->processOperation($media, $operation);
            }

            $this->updateMediaMetadata($media);
            DB::commit();

            return $media;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new MediaException('Processing failed: ' . $e->getMessage());
        }
    }

    protected function executeDelete(int $mediaId): bool
    {
        DB::beginTransaction();
        try {
            $media = $this->getMediaForDeletion($mediaId);
            
            $this->deleteFiles($media);
            $this->removeFromDatabase($media);
            
            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new MediaException('Deletion failed: ' . $e->getMessage());
        }
    }

    protected function validateUpload(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new ValidationException('Invalid file upload');
        }

        if (!$this->validator->validateFile($file, $this->config['allowed_mime_types'])) {
            throw new ValidationException('File type not allowed');
        }

        if ($file->getSize() > $this->config['max_file_size']) {
            throw new ValidationException('File size exceeds limit');
        }
    }

    protected function generateSecureFilename(UploadedFile $file): string
    {
        return sprintf(
            '%s_%s.%s',
            time(),
            hash('xxh3', $file->getClientOriginalName() . uniqid()),
            $file->getClientOriginalExtension()
        );
    }

    protected function storeFile(UploadedFile $file, string $filename): string
    {
        $path = $file->storeAs(
            $this->getStoragePath(),
            $filename,
            ['disk' => $this->config['storage_disk']]
        );

        if (!$path) {
            throw new MediaException('File storage failed');
        }

        return $path;
    }

    protected function processImage(Media $media, array $options): void
    {
        $this->imageProcessor->process($media->path, [
            'max_width' => $options['max_width'] ?? $this->config['default_max_width'],
            'max_height' => $options['max_height'] ?? $this->config['default_max_height'],
            'quality' => $options['quality'] ?? $this->config['default_quality'],
            'strip_metadata' => $options['strip_metadata'] ?? true
        ]);

        $this->generateThumbnails($media);
    }

    protected function generateThumbnails(Media $media): void
    {
        foreach ($this->config['thumbnail_sizes'] as $size) {
            $thumbnailPath = $this->getThumbnailPath($media, $size);
            $this->imageProcessor->createThumbnail(
                $media->path,
                $thumbnailPath,
                $size['width'],
                $size['height']
            );
            $media->thumbnails[$size['name']] = $thumbnailPath;
        }
    }

    protected function scanFile(string $path): void
    {
        $scanResult = $this->performVirusScan($path);
        if (!$scanResult['clean']) {
            Storage::delete($path);
            throw new MediaException('Security scan failed: potential threat detected');
        }
    }

    protected function calculateFileHash(UploadedFile $file): string
    {
        return hash_file('sha256', $file->getRealPath());
    }

    protected function getMediaForProcessing(int $mediaId): Media
    {
        $media = Media::lockForUpdate()->find($mediaId);
        if (!$media) {
            throw new MediaException('Media not found');
        }
        return $media;
    }

    protected function getMediaForDeletion(int $mediaId): Media
    {
        $media = Media::lockForUpdate()->find($mediaId);
        if (!$media) {
            throw new MediaException('Media not found');
        }
        return $media;
    }

    protected function deleteFiles(Media $media): void
    {
        Storage::delete($media->path);
        foreach ($media->thumbnails as $thumbnail) {
            Storage::delete($thumbnail);
        }
    }
}
