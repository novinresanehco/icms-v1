<?php

namespace App\Core\Repository;

use App\Models\Media;
use App\Core\Events\MediaEvents;
use App\Core\Exceptions\MediaRepositoryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class MediaRepository extends BaseRepository
{
    protected const CACHE_TIME = 3600;
    
    protected function getModelClass(): string
    {
        return Media::class;
    }

    public function createMedia(array $data): Media
    {
        try {
            DB::beginTransaction();

            $media = $this->create([
                'name' => $data['name'],
                'file_name' => $data['file_name'],
                'mime_type' => $data['mime_type'],
                'size' => $data['size'],
                'path' => $data['path'],
                'disk' => $data['disk'] ?? config('filesystems.default'),
                'alt_text' => $data['alt_text'] ?? null,
                'title' => $data['title'] ?? null,
                'description' => $data['description'] ?? null,
                'created_by' => auth()->id(),
                'metadata' => $data['metadata'] ?? null
            ]);

            if (!empty($data['tags'])) {
                $media->tags()->sync($data['tags']);
            }

            DB::commit();
            $this->clearCache();
            event(new MediaEvents\MediaCreated($media));

            return $media;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new MediaRepositoryException("Failed to create media: {$e->getMessage()}");
        }
    }

    public function attachToContent(int $mediaId, int $contentId, string $type = 'image'): void
    {
        try {
            DB::beginTransaction();

            $media = $this->find($mediaId);
            if (!$media) {
                throw new MediaRepositoryException("Media not found with ID: {$mediaId}");
            }

            $media->contents()->attach($contentId, ['type' => $type]);
            
            DB::commit();
            Cache::tags(["content.{$contentId}.media"])->flush();
            
            event(new MediaEvents\MediaAttached($media, $contentId, $type));

        } catch (\Exception $e) {
            DB::rollBack();
            throw new MediaRepositoryException("Failed to attach media: {$e->getMessage()}");
        }
    }

    public function getContentMedia(int $contentId, ?string $type = null): Collection
    {
        return Cache::tags(['media', "content.{$contentId}.media"])->remember(
            "content.{$contentId}.media" . ($type ? ".{$type}" : ''),
            self::CACHE_TIME,
            function() use ($contentId, $type) {
                $query = $this->model->whereHas('contents', function($q) use ($contentId, $type) {
                    $q->where('content.id', $contentId);
                    if ($type) {
                        $q->where('type', $type);
                    }
                });
                
                return $query->with('tags')->get();
            }
        );
    }

    public function updateMediaMetadata(int $mediaId, array $metadata): Media
    {
        try {
            DB::beginTransaction();

            $media = $this->find($mediaId);
            if (!$media) {
                throw new MediaRepositoryException("Media not found with ID: {$mediaId}");
            }

            $media->update(['metadata' => array_merge($media->metadata ?? [], $metadata)]);
            
            DB::commit();
            $this->clearCache();
            
            event(new MediaEvents\MediaMetadataUpdated($media));
            
            return $media;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new MediaRepositoryException("Failed to update media metadata: {$e->getMessage()}");
        }
    }

    public function deleteMedia(int $mediaId): bool
    {
        try {
            DB::beginTransaction();

            $media = $this->find($mediaId);
            if (!$media) {
                throw new MediaRepositoryException("Media not found with ID: {$mediaId}");
            }

            // Store media info for event
            $mediaInfo = $media->toArray();

            // Delete the media
            $result = $this->delete($mediaId);
            
            DB::commit();
            $this->clearCache();
            
            event(new MediaEvents\MediaDeleted($mediaInfo));
            
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new MediaRepositoryException("Failed to delete media: {$e->getMessage()}");
        }
    }

    public function searchMedia(array $criteria): Collection
    {
        $query = $this->model->newQuery();

        if (!empty($criteria['type'])) {
            $query->where('mime_type', 'LIKE', $criteria['type'] . '%');
        }

        if (!empty($criteria['tag'])) {
            $query->whereHas('tags', function($q) use ($criteria) {
                $q->where('slug', $criteria['tag']);
            });
        }

        if (isset($criteria['created_by'])) {
            $query->where('created_by', $criteria['created_by']);
        }

        if (!empty($criteria['search'])) {
            $query->where(function($q) use ($criteria) {
                $q->where('name', 'LIKE', "%{$criteria['search']}%")
                  ->orWhere('description', 'LIKE', "%{$criteria['search']}%");
            });
        }

        return $query->get();
    }

    protected function getCacheTags(): array
    {
        return ['media'];
    }

    protected function clearCache(): void
    {
        Cache::tags($this->getCacheTags())->flush();
    }
}
