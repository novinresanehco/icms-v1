<?php

namespace App\Core\Services;

use App\Core\Repository\MediaRepository;
use App\Core\Validation\MediaValidator;
use App\Core\Processing\MediaProcessor;
use App\Core\Storage\MediaStorage;
use App\Core\Events\MediaEvents;
use App\Core\Exceptions\MediaServiceException;
use App\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MediaService
{
    public function __construct(
        protected MediaRepository $repository,
        protected MediaValidator $validator,
        protected MediaProcessor $processor,
        protected MediaStorage $storage
    ) {}

    public function uploadMedia(UploadedFile $file, array $metadata = []): Media
    {
        $this->validator->validateUpload($file);

        try {
            DB::beginTransaction();

            // Process the file
            $processedFile = $this->processor->process($file);

            // Store the file
            $storagePath = $this->storage->store($processedFile);

            // Create media record
            $media = $this->repository->createMedia([
                'name' => $file->getClientOriginalName(),
                'file_name' => basename($storagePath),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'path' => $storagePath,
                'metadata' => array_merge($metadata, [
                    'dimensions' => $processedFile->getDimensions(),
                    'processed_at' => now(),
                ])
            ]);

            DB::commit();
            return $media;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->storage->cleanup($processedFile ?? null);
            throw new MediaServiceException("Failed to upload media: {$e->getMessage()}", 0, $e);
        }
    }

    public function attachToContent(int $mediaId, int $contentId, string $type = 'image'): void
    {
        $this->validator->validateAttachment($mediaId, $contentId, $type);

        try {
            $this->repository->attachToContent($mediaId, $contentId, $type);
        } catch (\Exception $e) {
            throw new MediaServiceException("Failed to attach media: {$e->getMessage()}", 0, $e);
        }
    }

    public function updateMediaMetadata(int $mediaId, array $metadata): Media
    {
        $this->validator->validateMetadata($metadata);

        try {
            return $this->repository->updateMediaMetadata($mediaId, $metadata);
        } catch (\Exception $e) {
            throw new MediaServiceException("Failed to update media metadata: {$e->getMessage()}", 0, $e);
        }
    }

    public function deleteMedia(int $mediaId): bool
    {
        try {
            DB::beginTransaction();

            $media = $this->repository->find($mediaId);
            if (!$media) {
                throw new MediaServiceException("Media not found with ID: {$mediaId}");
            }

            // Delete from storage
            $this->storage->delete($media->path);

            // Delete from database
            $result = $this->repository->deleteMedia($mediaId);

            DB::commit();
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new MediaServiceException("Failed to delete media: {$e->getMessage()}", 0, $e);
        }
    }

    public function getContentMedia(int $contentId, ?string $type = null): Collection
    {
        try {
            return $this->repository->getContentMedia($contentId, $type);
        } catch (\Exception $e) {
            throw new MediaServiceException("Failed to get content media: {$e->getMessage()}", 0, $e);
        }
    }

    public function regenerateThumbnails(int $mediaId): Media
    {
        try {
            DB::beginTransaction();

            $media = $this->repository->find($mediaId);
            if (!$media) {
                throw new MediaServiceException("Media not found with ID: {$mediaId}");
            }

            $file = $this->storage->retrieve($media->path);
            $processedFile = $this->processor->regenerateThumbnails($file);
            
            $media = $this->repository->updateMediaMetadata($mediaId, [
                'thumbnails' => $processedFile->getThumbnailPaths(),
                'regenerated_at' => now()
            ]);

            DB::commit();
            return $media;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new MediaServiceException("Failed to regenerate thumbnails: {$e->getMessage()}", 0, $e);
        }
    }

    public function optimizeMedia(int $mediaId): Media
    {
        try {
            DB::beginTransaction();

            $media = $this->repository->find($mediaId);
            if (!$media) {
                throw new MediaServiceException("Media not found with ID: {$mediaId}");
            }

            $file = $this->storage->retrieve($media->path);
            $optimizedFile = $this->processor->optimize($file);
            
            $newPath = $this->storage->store($optimizedFile);
            $this->storage->delete($media->path);

            $media = $this->repository->update($mediaId, [
                'path' => $newPath,
                'size' => $optimizedFile->getSize(),
                'metadata' => array_merge($media->metadata ?? [], [
                    'optimized_at' => now(),
                    'original_size' => $media->size
                ])
            ]);

            DB::commit();
            return $media;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new MediaServiceException("Failed to optimize media: {$e->getMessage()}", 0, $e);
        }
    }
}
