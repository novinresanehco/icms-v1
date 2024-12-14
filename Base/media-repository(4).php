<?php

namespace App\Repositories;

use App\Models\Media;
use App\Repositories\Contracts\MediaRepositoryInterface;
use Illuminate\Support\Collection;

class MediaRepository extends BaseRepository implements MediaRepositoryInterface
{
    protected array $searchableFields = ['name', 'alt', 'description'];
    protected array $filterableFields = ['type', 'collection', 'status'];
    protected array $relationships = ['uploader'];

    public function __construct(Media $model)
    {
        parent::__construct($model);
    }

    public function getByType(string $type): Collection
    {
        return Cache::remember(
            $this->getCacheKey("type.{$type}"),
            $this->cacheTTL,
            fn() => $this->model->where('type', $type)->get()
        );
    }

    public function getByCollection(string $collection): Collection
    {
        return Cache::remember(
            $this->getCacheKey("collection.{$collection}"),
            $this->cacheTTL,
            fn() => $this->model->where('collection', $collection)->get()
        );
    }

    public function attachToContent(int $mediaId, int $contentId, array $metadata = []): void
    {
        $media = $this->findOrFail($mediaId);
        $media->contents()->syncWithoutDetaching([
            $contentId => $metadata
        ]);
        
        $this->clearModelCache();
    }

    public function detachFromContent(int $mediaId, int $contentId): void
    {
        $media = $this->findOrFail($mediaId);
        $media->contents()->detach($contentId);
        
        $this->clearModelCache();
    }
}
