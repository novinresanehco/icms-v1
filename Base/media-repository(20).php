<?php

namespace App\Repositories;

use App\Core\Repositories\BaseRepository;
use App\Models\Media;
use App\Core\Contracts\MediaRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Core\Services\ImageProcessor;
use App\Core\Exceptions\{MediaException, RepositoryException};

class MediaRepository extends BaseRepository implements MediaRepositoryInterface
{
    protected array $searchable = ['filename', 'title', 'alt_text', 'description'];
    protected array $with = ['user'];
    protected ImageProcessor $imageProcessor;

    public function __construct(
        DatabasePerformanceManager $performanceManager,
        ImageProcessor $imageProcessor
    ) {
        parent::__construct($performanceManager);
        $this->imageProcessor = $imageProcessor;
    }

    public function model(): string
    {
        return Media::class;
    }

    public function upload(UploadedFile $file, array $attributes = []): Media
    {
        try {
            $this->beginTransaction();

            // Generate unique filename
            $filename = $this->generateUniqueFilename($file);
            
            // Store the file
            $path = $file->storeAs(
                $this->getStoragePath($attributes['type'] ?? 'general'),
                $filename,
                'public'
            );

            if (!$path) {
                throw new MediaException('Failed to store file');
            }

            // Process image if it's an image file
            $metadata = $this->processFileMetadata($file);
            if ($this->isImage($file)) {
                $metadata = array_merge(
                    $metadata,
                    $this->processImage($path, $attributes['process'] ?? [])
                );
            }

            // Create media record
            $media = $this->create(array_merge([
                'filename' => $filename,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'path' => $path,
                'user_id' => auth()->id(),
                'metadata' => $metadata
            ], $attributes));

            $this->commit();
            return $media;

        } catch (\Exception $e) {
            $this->rollBack();
            throw new MediaException("Failed to upload file: {$e->getMessage()}");
        }
    }

    public function getByType(string $type): Collection
    {
        $cacheKey = $this->getCacheKey(__FUNCTION__, compact('type'));
        
        return $this->remember($cacheKey, function () use ($type) {
            $query = $this->model->with($this->with)
                ->where('type', $type)
                ->orderBy('created_at', 'desc');
                
            $this->performanceManager->monitorQueryPerformance();
            return $query->get();
        });
    }

    public function getByMimeType(string $mimeType): Collection
    {
        $cacheKey = $this->getCacheKey(__FUNCTION__, compact('mimeType'));
        
        return $this->remember($cacheKey, function () use ($mimeType) {
            $query = $this->model->with($this->with)
                ->where('mime_type', 'LIKE', $mimeType . '%')
                ->orderBy('created_at', 'desc');
                
            $this->performanceManager->monitorQueryPerformance();
            return $query->get();
        });
    }

    public function deleteWithFile(int $id): bool
    {
        try {
            $this->beginTransaction();

            $media = $this->find($id);
            if (!$media) {
                throw new MediaException("Media not found");
            }

            // Delete physical file
            if (Storage::disk('public')->exists($media->path)) {
                Storage::disk('public')->delete($media->path);
                
                // Delete thumbnails if they exist
                $this->deleteThumbnails($media);
            }

            // Delete database record
            $deleted = $media->delete();

            $this->commit();
            $this->clearCache();
            
            return $deleted;

        } catch (\Exception $e) {
            $this->rollBack();
            throw new MediaException("Failed to delete media: {$e->getMessage()}");
        }
    }

    public function updateMetadata(int $id, array $metadata): Media
    {
        try {
            $media = $this->find($id);
            if (!$media) {
                throw new MediaException("Media not found");
            }

            $updatedMetadata = array_merge($media->metadata ?? [], $metadata);
            $media->metadata = $updatedMetadata;
            $media->save();

            $this->clearCache();
            return $media;

        } catch (\Exception $e) {
            throw new MediaException("Failed to update metadata: {$e->getMessage()}");
        }
    }

    public function generateThumbnail(int $id, array $dimensions): ?string
    {
        try {
            $media = $this->find($id);
            if (!$media || !$this->isImage($media)) {
                throw new MediaException("Invalid media for thumbnail generation");
            }

            return $this->imageProcessor->createThumbnail(
                Storage::disk('public')->path($media->path),
                $dimensions
            );

        } catch (\Exception $e) {
            throw new MediaException("Failed to generate thumbnail: {$e->getMessage()}");
        }
    }

    protected function processImage(string $path, array $processOptions): array
    {
        $fullPath = Storage::disk('public')->path($path);
        $metadata = $this->imageProcessor->getImageInfo($fullPath);

        if (!empty($processOptions)) {
            $this->imageProcessor->processImage($fullPath, $processOptions);
            $metadata = array_merge(
                $metadata,
                $this->imageProcessor->getImageInfo($fullPath)
            );
        }

        return $metadata;
    }

    protected function generateUniqueFilename(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();
        $basename = str_slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $filename = $basename . '_' . time() . '_' . uniqid();
        
        return $filename . '.' . $extension;
    }

    protected function getStoragePath(string $type): string
    {
        $paths = config('media.paths', [
            'general' => 'uploads',
            'images' => 'uploads/images',
            'documents' => 'uploads/documents',
            'videos' => 'uploads/videos'
        ]);

        return $paths[$type] ?? $paths['general'];
    }

    protected function isImage($file): bool
    {
        if ($file instanceof UploadedFile) {
            return strpos($file->getMimeType(), 'image/') === 0;
        }

        if ($file instanceof Media) {
            return strpos($file->mime_type, 'image/') === 0;
        }

        return false;
    }

    protected function processFileMetadata(UploadedFile $file): array
    {
        $metadata = [
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'extension' => $file->getClientOriginalExtension()
        ];

        if ($this->isImage($file)) {
            $imageInfo = getimagesize($file->getPathname());
            if ($imageInfo) {
                $metadata = array_merge($metadata, [
                    'width' => $imageInfo[0],
                    'height' => $imageInfo[1],
                    'type' => $imageInfo[2],
                    'bits' => $imageInfo['bits'] ?? null,
                    'channels' => $imageInfo['channels'] ?? null
                ]);
            }
        }

        return $metadata;
    }

    protected function deleteThumbnails(Media $media): void
    {
        if (!isset($media->metadata['thumbnails'])) {
            return;
        }

        foreach ($media->metadata['thumbnails'] as $thumbnail) {
            if (Storage::disk('public')->exists($thumbnail)) {
                Storage::disk('public')->delete($thumbnail);
            }
        }
    }
}
