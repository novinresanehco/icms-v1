<?php

namespace App\Services;

use App\Core\Contracts\MediaRepositoryInterface;
use App\Models\Media;
use App\Core\Exceptions\MediaException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use App\Http\Resources\MediaResource;
use Intervention\Image\Facades\Image;

class MediaService
{
    protected MediaRepositoryInterface $repository;
    protected array $allowedMimeTypes;

    /**
     * MediaService constructor.
     *
     * @param MediaRepositoryInterface $repository
     */
    public function __construct(MediaRepositoryInterface $repository)
    {
        $this->repository = $repository;
        $this->allowedMimeTypes = config('media.allowed_mime_types', []);
    }

    /**
     * Upload media file
     *
     * @param UploadedFile $file
     * @param array $attributes
     * @return MediaResource
     * @throws MediaException
     */
    public function uploadMedia(UploadedFile $file, array $attributes = []): MediaResource
    {
        $this->validateFile($file);

        try {
            // Process image if applicable
            if (str_starts_with($file->getMimeType(), 'image/')) {
                $file = $this->processImage($file);
            }

            $media = $this->repository->storeMedia($file, $attributes);
            return new MediaResource($media);
        } catch (\Exception $e) {
            throw new MediaException("Error uploading media: {$e->getMessage()}");
        }
    }

    /**
     * Process image file
     *
     * @param UploadedFile $file
     * @return UploadedFile
     */
    protected function processImage(UploadedFile $file): UploadedFile
    {
        $image = Image::make($file);
        
        // Apply optimizations
        $image->orientate();
        
        // Generate thumbnails if needed
        if (config('media.generate_thumbnails', true)) {
            $this->generateThumbnails($image);
        }
        
        return $file;
    }

    /**
     * Generate image thumbnails
     *
     * @param \Intervention\Image\Image $image
     * @return void
     */
    protected function generateThumbnails($image): void
    {
        $sizes = config('media.thumbnail_sizes', []);
        
        foreach ($sizes as $name => $dimensions) {
            $thumbnail = clone $image;
            $thumbnail->fit($dimensions[0], $dimensions[1]);
            // Save thumbnail
            // Implementation depends on storage configuration
        }
    }

    /**
     * Delete media
     *
     * @param int $id
     * @return bool
     * @throws MediaException
     */
    public function deleteMedia(int $id): bool
    {
        try {
            return $this->repository->deleteMedia($id);
        } catch (\Exception $e) {
            throw new MediaException("Error deleting media: {$e->getMessage()}");
        }
    }

    /**
     * Get media by type
     *
     * @param string $type
     * @return Collection
     */
    public function getMediaByType(string $type): Collection
    {
        return $this->repository->getByMimeType($type);
    }

    /**
     * Attach media to content
     *
     * @param int $contentId
     * @param array $mediaIds
     * @param array $attributes
     * @return void
     * @throws MediaException
     */
    public function attachToContent(int $contentId, array $mediaIds, array $attributes = []): void
    {
        $this->repository->attachToContent($contentId, $mediaIds, $attributes);
    }

    /**
     * Clean unused media
     *
     * @param int $days
     * @return int
     * @throws MediaException
     */
    public function cleanUnusedMedia(int $days = 30): int
    {
        try {
            $unusedMedia = $this->repository->getUnusedMedia($days);
            $count = 0;
            
            foreach ($unusedMedia as $media) {
                if ($this->repository->deleteMedia($media->id)) {
                    $count++;
                }
            }
            
            return $count;
        } catch (\Exception $e) {
            throw new MediaException("Error cleaning unused media: {$e->getMessage()}");
        }
    }

    /**
     * Validate uploaded file
     *
     * @param UploadedFile $file
     * @throws MediaException
     */
    protected function validateFile(UploadedFile $file): void
    {
        $validator = Validator::make(
            ['file' => $file],
            [
                'file' => [
                    'required',
                    'file',
                    'mimes:' . implode(',', $this->allowedMimeTypes),
                    'max:' . config('media.max_file_size', 10240)
                ]
            ]
        );

        if ($validator->fails()) {
            throw new MediaException('File validation failed: ' . $validator->errors()->first());
        }
    }
}
