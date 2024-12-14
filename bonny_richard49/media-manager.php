<?php

namespace App\Core\Media;

use Illuminate\Support\Facades\{Storage, DB};
use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;

class MediaManager implements MediaInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private MediaRepository $repository;
    private MediaOptimizer $optimizer;
    private array $config;

    public function upload(UploadedFile $file, array $metadata = []): Media
    {
        return $this->security->executeCriticalOperation(
            new UploadMediaOperation($file, $metadata, $this->repository, $this->optimizer),
            new SecurityContext([
                'operation' => 'media.upload',
                'file' => $file->getClientOriginalName(),
                'user' => auth()->user()
            ])
        );
    }

    public function process(Media $media): void
    {
        $this->security->executeCriticalOperation(
            new ProcessMediaOperation($media, $this->optimizer),
            new SecurityContext([
                'operation' => 'media.process',
                'media_id' => $media->id
            ])
        );
    }

    public function delete(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            new DeleteMediaOperation($id, $this->repository),
            new SecurityContext([
                'operation' => 'media.delete',
                'media_id' => $id
            ])
        );
    }

    public function generateThumbnails(Media $media): array
    {
        return $this->security->executeCriticalOperation(
            new GenerateThumbnailsOperation($media, $this->optimizer),
            new SecurityContext([
                'operation' => 'media.thumbnails',
                'media_id' => $media->id
            ])
        );
    }

    public function validateFile(UploadedFile $file): bool
    {
        return $this->validator->validateFile(
            $file,
            $this->config['allowed_types'],
            $this->config['max_size']
        );
    }
}

class UploadMediaOperation extends CriticalOperation
{
    private UploadedFile $file;
    private array $metadata;
    private MediaRepository $repository;
    private MediaOptimizer $optimizer;

    public function execute(): Media
    {
        DB::beginTransaction();

        try {
            // Generate secure filename
            $filename = $this->generateSecureFilename($this->file);

            // Store file securely
            $path = Storage::putFileAs(
                'media/original',
                $this->file,
                $filename,
                'private'
            );

            // Create media record
            $media = $this->repository->create([
                'filename' => $filename,
                'path' => $path,
                'mime_type' => $this->file->getMimeType(),
                'size' => $this->file->getSize(),
                'metadata' => $this->metadata,
                'status' => Media::STATUS_PROCESSING
            ]);

            // Process media asynchronously
            ProcessMediaJob::dispatch($media);

            DB::commit();
            return $media;

        } catch (\Exception $e) {
            DB::rollBack();
            Storage::delete($path ?? '');
            throw $e;
        }
    }

    private function generateSecureFilename(UploadedFile $file): string
    {
        return sprintf(
            '%s_%s.%s',
            uniqid('media_', true),
            hash('sha256', $file->getClientOriginalName()),
            $file->getClientOriginalExtension()
        );
    }
}

class ProcessMediaOperation extends CriticalOperation
{
    private Media $media;
    private MediaOptimizer $optimizer;

    public function execute(): void
    {
        DB::beginTransaction();

        try {
            // Validate file integrity
            if (!$this->validateFileIntegrity($this->media)) {
                throw new MediaException('File integrity check failed');
            }

            // Scan for malware
            if (!$this->scanForMalware($this->media)) {
                throw new SecurityException('Security scan failed');
            }

            // Optimize media
            $this->optimizer->optimize($this->media);

            // Generate thumbnails
            $thumbnails = $this->optimizer->generateThumbnails($this->media);

            // Update media record
            $this->media->update([
                'status' => Media::STATUS_READY,
                'thumbnails' => $thumbnails,
                'processed_at' => now()
            ]);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleProcessingFailure($this->media, $e);
            throw $e;
        }
    }

    private function validateFileIntegrity(Media $media): bool
    {
        $path = Storage::path($media->path);
        $actualHash = hash_file('sha256', $path);
        return $actualHash === $media->metadata['original_hash'];
    }

    private function scanForMalware(Media $media): bool
    {
        // Implement malware scanning
        return true;
    }

    private function handleProcessingFailure(Media $media, \Exception $e): void
    {
        $media->update([
            'status' => Media::STATUS_FAILED,
            'error' => $e->getMessage()
        ]);

        // Cleanup any temporary files
        $this->optimizer->cleanup($media);
    }
}

class MediaOptimizer
{
    private array $config;

    public function optimize(Media $media): void
    {
        if ($this->isImage($media)) {
            $this->optimizeImage($media);
        } elseif ($this->isVideo($media)) {
            $this->optimizeVideo($media);
        }
    }

    public function generateThumbnails(Media $media): array
    {
        $thumbnails = [];

        if (!$this->isImage($media)) {
            return $thumbnails;
        }

        foreach ($this->config['thumbnail_sizes'] as $size => $dimensions) {
            $thumbnails[$size] = $this->generateThumbnail(
                $media,
                $dimensions['width'],
                $dimensions['height']
            );
        }

        return $thumbnails;
    }

    private function optimizeImage(Media $media): void
    {
        $image = Image::make(Storage::path($media->path));

        // Auto-orient based on EXIF
        $image->orientate();

        // Strip metadata
        $image->stripImage();

        // Optimize quality
        $image->save(null, $this->config['image_quality']);
    }

    private function optimizeVideo(Media $media): void
    {
        // Implement video optimization
    }

    private function generateThumbnail(Media $media, int $width, int $height): string
    {
        $image = Image::make(Storage::path($media->path));

        $image->fit($width, $height, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        });

        $thumbnailPath = sprintf(
            'media/thumbnails/%s_%dx%d.%s',
            $media->id,
            $width,
            $height,
            $media->getExtension()
        );

        Storage::put(
            $thumbnailPath,
            $image->encode(null, $this->config['thumbnail_quality'])
        );

        return $thumbnailPath;
    }

    private function isImage(Media $media): bool
    {
        return str_starts_with($media->mime_type, 'image/');
    }

    private function isVideo(Media $media): bool
    {
        return str_starts_with($media->mime_type, 'video/');
    }

    public function cleanup(Media $media): void
    {
        // Delete original
        Storage::delete($media->path);

        // Delete thumbnails
        foreach ($media->thumbnails as $thumbnail) {
            Storage::delete($thumbnail);
        }
    }
}
