<?php

namespace App\Repositories;

use App\Models\Media;
use App\Core\Repository\BaseRepository;
use App\Core\Contracts\MediaRepositoryInterface;
use App\Core\Exceptions\MediaException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;

class MediaRepository extends BaseRepository implements MediaRepositoryInterface
{
    protected const CACHE_TTL = 3600;
    protected const CACHE_KEY = 'media';

    /**
     * MediaRepository constructor.
     *
     * @param Media $model
     */
    public function __construct(Media $model)
    {
        parent::__construct($model);
    }

    /**
     * Store uploaded file and create media record
     *
     * @param UploadedFile $file
     * @param array $attributes
     * @return Media
     * @throws MediaException
     */
    public function storeMedia(UploadedFile $file, array $attributes = []): Media
    {
        try {
            // Store file
            $path = Storage::disk('public')->putFile('media', $file);
            
            // Create media record
            $media = $this->create([
                'filename' => $file->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'disk' => 'public',
                'meta' => array_merge([
                    'original_name' => $file->getClientOriginalName(),
                    'extension' => $file->getClientOriginalExtension(),
                ], $attributes['meta'] ?? [])
            ]);

            $this->clearCache();
            
            return $media;
        } catch (\Exception $e) {
            throw new MediaException("Error storing media: {$e->getMessage()}");
        }
    }

    /**
     * Delete media and associated file
     *
     * @param int $id
     * @return bool
     * @throws MediaException
     */
    public function deleteMedia(int $id): bool
    {
        try {
            $media = $this->find($id);
            
            // Delete file from storage
            if (Storage::disk($media->disk)->exists($media->path)) {
                Storage::disk($media->disk)->delete($media->path);
            }
            
            // Delete record
            $deleted = $this->delete($id);
            $this->clearCache();
            
            return $deleted;
        } catch (\Exception $e) {
            throw new MediaException("Error deleting media: {$e->getMessage()}");
        }
    }

    /**
     * Get media by mime type
     *
     * @param string $mimeType
     * @return Collection
     */
    public function getByMimeType(string $mimeType): Collection
    {
        return Cache::tags(['media'])->remember(
            "media:mime:{$mimeType}",
            self::CACHE_TTL,
            fn() => $this->model->where('mime_type', 'like', $mimeType . '%')->get()
        );
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
        try {
            $content = app('App\Repositories\ContentRepository')->find($contentId);
            $content->media()->syncWithPivotValues($mediaIds, $attributes);
            $this->clearCache();
        } catch (\Exception $e) {
            throw new MediaException("Error attaching media to content: {$e->getMessage()}");
        }
    }

    /**
     * Get unused media
     *
     * @param int $days
     * @return Collection
     */
    public function getUnusedMedia(int $days = 30): Collection
    {
        return Cache::tags(['media'])->remember(
            "media:unused:{$days}",
            self::CACHE_TTL,
            function() use ($days) {
                return $this->model
                    ->whereDoesntHave('contents')
                    ->where('created_at', '<', now()->subDays($days))
                    ->get();
            }
        );
    }

    /**
     * Clear media cache
     *
     * @return void
     */
    protected function clearCache(): void
    {
        Cache::tags(['media'])->flush();
    }
}
