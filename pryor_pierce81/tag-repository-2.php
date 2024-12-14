<?php

namespace App\Repositories;

use App\Models\Tag;
use App\Core\Repository\BaseRepository;
use App\Core\Contracts\TagRepositoryInterface;
use App\Core\Exceptions\TagException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class TagRepository extends BaseRepository implements TagRepositoryInterface
{
    protected const CACHE_TTL = 3600; // 1 hour
    protected const CACHE_KEY = 'tags';

    /**
     * TagRepository constructor.
     *
     * @param Tag $model
     */
    public function __construct(Tag $model)
    {
        parent::__construct($model);
    }

    /**
     * Create a new tag
     *
     * @param array $attributes
     * @return Tag
     * @throws TagException
     */
    public function create(array $attributes): Tag
    {
        try {
            $attributes['slug'] = $attributes['slug'] ?? Str::slug($attributes['name']);
            
            $tag = parent::create($attributes);
            $this->clearCache();
            
            return $tag;
        } catch (\Exception $e) {
            throw new TagException("Error creating tag: {$e->getMessage()}");
        }
    }

    /**
     * Find tag by slug
     *
     * @param string $slug
     * @return Tag|null
     */
    public function findBySlug(string $slug): ?Tag
    {
        return Cache::tags(['tags'])->remember(
            "tag:slug:{$slug}", 
            self::CACHE_TTL,
            fn() => $this->model->where('slug', $slug)->first()
        );
    }

    /**
     * Get popular tags with usage count
     *
     * @param int $limit
     * @return Collection
     */
    public function getPopularTags(int $limit = 10): Collection
    {
        return Cache::tags(['tags'])->remember(
            "tags:popular:{$limit}",
            self::CACHE_TTL,
            fn() => $this->model
                ->withCount('contents')
                ->orderByDesc('contents_count')
                ->limit($limit)
                ->get()
        );
    }

    /**
     * Get related tags for a given tag
     *
     * @param Tag $tag
     * @param int $limit
     * @return Collection
     */
    public function getRelatedTags(Tag $tag, int $limit = 5): Collection
    {
        return Cache::tags(['tags'])->remember(
            "tags:related:{$tag->id}:{$limit}",
            self::CACHE_TTL,
            function() use ($tag, $limit) {
                return $this->model
                    ->whereHas('contents', function($query) use ($tag) {
                        $query->whereIn('content_id', $tag->contents->pluck('id'));
                    })
                    ->where('id', '!=', $tag->id)
                    ->withCount('contents')
                    ->orderByDesc('contents_count')
                    ->limit($limit)
                    ->get();
            }
        );
    }

    /**
     * Sync tags for content
     *
     * @param int $contentId
     * @param array $tagIds
     * @return void
     * @throws TagException
     */
    public function syncContentTags(int $contentId, array $tagIds): void
    {
        try {
            $content = app('App\Repositories\ContentRepository')->find($contentId);
            $content->tags()->sync($tagIds);
            $this->clearCache();
        } catch (\Exception $e) {
            throw new TagException("Error syncing content tags: {$e->getMessage()}");
        }
    }

    /**
     * Clear tags cache
     *
     * @return void
     */
    protected function clearCache(): void
    {
        Cache::tags(['tags'])->flush();
    }
}
