<?php

namespace App\Core\Content\Services;

use App\Core\Content\Models\Content;
use App\Core\Content\Repositories\ContentRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class ContentCacheService
{
    private const CACHE_TTL = 3600;
    private const CACHE_PREFIX = 'content:';

    public function __construct(private ContentRepository $repository)
    {
    }

    public function getContent(int $id): ?Content
    {
        return Cache::tags(['content'])->remember(
            self::CACHE_PREFIX . $id,
            self::CACHE_TTL,
            fn() => $this->repository->findWithRelations($id)
        );
    }

    public function getContentBySlug(string $slug): ?Content
    {
        return Cache::tags(['content'])->remember(
            self::CACHE_PREFIX . "slug:{$slug}",
            self::CACHE_TTL,
            fn() => $this->repository->findBySlug($slug)
        );
    }

    public function getContentsByStatus(string $status, array $filters = []): Collection
    {
        $cacheKey = self::CACHE_PREFIX . "status:{$status}" . md5(serialize($filters));

        return Cache::tags(['content'])->remember(
            $cacheKey,
            self::CACHE_TTL,
            fn() => $this->repository->getByStatus($status, $filters)
        );
    }

    public function getPaginatedContent(array $filters, int $perPage): LengthAwarePaginator
    {
        $cacheKey = self::CACHE_PREFIX . "paginated:" . md5(serialize($filters) . $perPage);

        return Cache::tags(['content'])->remember(
            $cacheKey,
            self::CACHE_TTL,
            fn() => $this->repository->paginateWithFilters($filters, $perPage)
        );
    }

    public function getRelatedContent(Content $content, int $limit = 5): Collection
    {
        return Cache::tags(['content'])->remember(
            self::CACHE_PREFIX . "related:{$content->id}:limit:{$limit}",
            self::CACHE_TTL,
            fn() => $this->repository->getRelatedContent($content, $limit)
        );
    }

    public function invalidateContent(Content $content): void
    {
        $tags = ['content'];
        
        if ($content->categories->isNotEmpty()) {
            $tags = array_merge($tags, $content->categories->pluck('id')->map(fn($id) => "category:{$id}")->toArray());
        }
        
        if ($content->tags->isNotEmpty()) {
            $tags = array_merge($tags, $content->tags->pluck('id')->map(fn($id) => "tag:{$id}")->toArray());
        }

        Cache::tags($tags)->flush();
    }

    public function invalidateAll(): void
    {
        Cache::tags(['content'])->flush();
    }

    public function warmCache(): void
    {
        // Cache published content
        $this->getContentsByStatus('published');

        // Cache recent content
        $this->getPaginatedContent(['status' => 'published'], 10);

        // Cache individual contents
        $this->repository->all()->each(function ($content) {
            $this->getContent($content->id);
            $this->getContentBySlug($content->slug);
            $this->getRelatedContent($content);
        });
    }
}
