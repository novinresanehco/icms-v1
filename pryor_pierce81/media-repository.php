<?php

namespace App\Core\Repository;

use App\Models\Media;
use Illuminate\Support\Facades\Storage;
use App\Core\Events\MediaEvents;
use App\Core\Exceptions\MediaRepositoryException;

class MediaRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Media::class;
    }

    public function store(UploadedFile $file, array $metadata = []): Media
    {
        try {
            // Store file
            $path = Storage::disk('media')->put('uploads', $file);
            
            // Create media record
            $media = $this->create([
                'filename' => $file->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'metadata' => $metadata,
                'disk' => 'media'
            ]);

            event(new MediaEvents\MediaUploaded($media));

            return $media;
        } catch (\Exception $e) {
            throw new MediaRepositoryException(
                "Failed to store media: {$e->getMessage()}"
            );
        }
    }

    public function attachToContent(int $mediaId, int $contentId, array $data = []): void
    {
        try {
            $media = $this->find($mediaId);
            if (!$media) {
                throw new MediaRepositoryException("Media not found with ID: {$mediaId}");
            }

            $media->contents()->attach($contentId, $data);
            $this->clearCache();
            Cache::tags(['content'])->flush();

            event(new MediaEvents\MediaAttachedToContent($media, $contentId));
        } catch (\Exception $e) {
            throw new MediaRepositoryException(
                "Failed to attach media to content: {$e->getMessage()}"
            );
        }
    }

    public function delete(int $id): bool
    {
        try {
            $media = $this->find($id);
            if (!$media) {
                throw new MediaRepositoryException("Media not found with ID: {$id}");
            }

            // Delete file from storage
            Storage::disk($media->disk)->delete($media->path);

            // Delete database record
            $deleted = parent::delete($id);

            if ($deleted) {
                event(new MediaEvents\MediaDeleted($media));
            }

            return $deleted;
        } catch (\Exception $e) {
            throw new MediaRepositoryException(
                "Failed to delete media: {$e->getMessage()}"
            );
        }
    }

    public function getByMimeType(string $mimeType): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('getByMimeType', $mimeType),
            $this->cacheTime,
            fn() => $this->model->with($this->with)
                               ->where('mime_type', 'like', $mimeType . '%')
                               ->get()
        );
    }

    public function getUnused(): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('getUnused'),
            $this->cacheTime,
            fn() => $this->model->with($this->with)
                               ->whereDoesntHave('contents')
                               ->get()
        );
    }
}
