<?php

namespace App\Core\Tag\Services;

use App\Core\Tag\Models\Tag;
use App\Core\Tag\Repositories\TagRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Collection;

class TagCacheService
{
    private const CACHE_TTL = 3600; // 1 hour
    private const CACHE_PREFIX = 'tags:';

    public function __construct(private TagRepository $repository)
    {
    }

    public function getAllTags(): Collection
    {
        return Cache::tags(['tags'])->remember(
            self::CACHE_PREFIX . 'all',
            self::CACHE_TTL,
            fn() => $this->repository->all()
        );
    }

    public function getTagById(int $id): ?Tag
    {
        return Cache::tags(['tags'])->remember(
            self::CACHE_PREFIX . "id:{$id}",
            self::CACHE_TTL,
            fn() => $this->repository->findOrFail($id)
        );
    }

    public function getTagBySlug(string $slug): ?Tag
    {
        return Cache::tags(['tags'])->remember(
            self::CACHE_PREFIX . "slug:{$slug}",
            self::CACHE_TTL,
            fn() => $this->repository->findBySlug($slug)
        );
    }

    public function getHierarchy(?int $parentId = null): Collection
    {
        $cacheKey = $parentId 
            ? self::CACHE_PREFIX . "hierarchy:{$parentId}"
            : self::CACHE_PREFIX . 'hierarchy:root';

        return Cache::tags(['tags'])->remember(
            $cacheKey,
            self::CACHE_TTL,
            fn() => $this->repository->getHierarchy($parentId)
        );
    }

    public function invalidateTag(Tag $tag): void
    {
        Cache::tags(['tags'])->flush();
    }

    public function invalidateHierarchy(): void
    {
        Cache::tags(['tags', 'hierarchy'])->flush();
    }

    public function warmCache(): void
    {
        $this->getAllTags();
        $this->getHierarchy();
        
        // Warm individual tag caches
        $this->repository->all()->each(function ($tag) {
            $this->getTagById($tag->id);
            $this->getTagBySlug($tag->slug);
        });
    }
}
