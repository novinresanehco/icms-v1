<?php

namespace App\Core\Category\Services;

use App\Core\Category\Models\Category;
use App\Core\Category\Repositories\CategoryRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Collection;

class CategoryCacheService
{
    private const CACHE_TTL = 86400; // 24 hours
    private const CACHE_PREFIX = 'categories:';

    public function __construct(private CategoryRepository $repository)
    {
    }

    public function getTree(?int $parentId = null): Collection
    {
        $cacheKey = $this->getCacheKey("tree:{$parentId}");

        return Cache::tags(['categories'])->remember(
            $cacheKey,
            self::CACHE_TTL,
            fn() => $this->repository->getTree($parentId)
        );
    }

    public function getCategory(int $id): ?Category
    {
        $cacheKey = $this->getCacheKey("id:{$id}");

        return Cache::tags(['categories'])->remember(
            $cacheKey,
            self::CACHE_TTL,
            fn() => $this->repository->findWithRelations($id)
        );
    }

    public function getCategoryByPath(string $path): ?Category
    {
        $cacheKey = $this->getCacheKey("path:{$path}");

        return Cache::tags(['categories'])->remember(
            $cacheKey,
            self::CACHE_TTL,
            fn() => $this->repository->findByPath($path)
        );
    }

    public function getAncestors(Category $category): Collection
    {
        $cacheKey = $this->getCacheKey("ancestors:{$category->id}");

        return Cache::tags(['categories'])->remember(
            $cacheKey,
            self::CACHE_TTL,
            fn() => $this->repository->getAncestors($category)
        );
    }

    public function getDescendants(Category $category): Collection
    {
        $cacheKey = $this->getCacheKey("descendants:{$category->id}");

        return Cache::tags(['categories'])->remember(
            $cacheKey,
            self::CACHE_TTL,
            fn() => $this->repository->getDescendants($category)
        );
    }

    public function getContentCount(Category $category, bool $includeChildren = true): int
    {
        $cacheKey = $this->getCacheKey("content_count:{$category->id}:{$includeChildren}");

        return Cache::tags(['categories', 'contents'])->remember(
            $cacheKey,
            self::CACHE_TTL,
            fn() => $this->repository->getContentCount($category, $includeChildren)
        );
    }

    public function invalidateCategory(Category $category): void
    {
        Cache::tags(['categories'])->flush();
    }

    public function warmCache(): void
    {
        // Cache entire tree
        $this->getTree();

        // Cache individual categories and their relations
        $this->repository->all()->each(function ($category) {
            $this->getCategory($category->id);
            $this->getCategoryByPath($category->path);
            $this->getAncestors($category);
            $this->getDescendants($category);
            $this->getContentCount($category);
        });
    }

    private function getCacheKey(string $key): string
    {
        return self::CACHE_PREFIX . $key;
    }
}
