<?php

namespace App\Repositories;

use App\Models\Content;
use App\Core\Repository\BaseRepository;
use App\Core\Contracts\ContentRepositoryInterface;
use App\Core\Exceptions\ContentException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Collection;

class ContentRepository extends BaseRepository implements ContentRepositoryInterface
{
    /**
     * Cache configuration
     */
    protected const CACHE_TTL = 3600; // 1 hour
    protected const CACHE_KEY_PREFIX = 'content:';

    /**
     * ContentRepository constructor.
     *
     * @param Content $model
     */
    public function __construct(Content $model)
    {
        parent::__construct($model);
    }

    /**
     * Get published content
     *
     * @param array $columns
     * @param array $relations
     * @return Collection
     */
    public function getPublished(array $columns = ['*'], array $relations = []): Collection
    {
        $cacheKey = $this->getCacheKey('published');

        return Cache::remember($cacheKey, self::CACHE_TTL, function() use ($columns, $relations) {
            return $this->model
                ->published()
                ->select($columns)
                ->with($relations)
                ->latest()
                ->get();
        });
    }

    /**
     * Get content by slug
     *
     * @param string $slug
     * @param array $relations
     * @return Content|null
     */
    public function findBySlug(string $slug, array $relations = []): ?Content
    {
        $cacheKey = $this->getCacheKey("slug:{$slug}");

        return Cache::remember($cacheKey, self::CACHE_TTL, function() use ($slug, $relations) {
            return $this->model
                ->where('slug', $slug)
                ->with($relations)
                ->first();
        });
    }

    /**
     * Create content with associated data
     *
     * @param array $attributes
     * @return Content
     */
    public function createWithRelations(array $attributes): Content
    {
        try {
            \DB::beginTransaction();

            $content = $this->create($attributes);

            if (isset($attributes['tags'])) {
                $content->tags()->sync($attributes['tags']);
            }

            if (isset($attributes['categories'])) {
                $content->categories()->sync($attributes['categories']);
            }

            \DB::commit();
            $this->clearCache();

            return $content;
        } catch (\Exception $e) {
            \DB::rollBack();
            throw new ContentException('Error creating content with relations: ' . $e->getMessage());
        }
    }

    /**
     * Update content and its relations
     *
     * @param int $id
     * @param array $attributes
     * @return Content
     */
    public function updateWithRelations(int $id, array $attributes): Content
    {
        try {
            \DB::beginTransaction();

            $content = $this->update($id, $attributes);

            if (isset($attributes['tags'])) {
                $content->tags()->sync($attributes['tags']);
            }

            if (isset($attributes['categories'])) {
                $content->categories()->sync($attributes['categories']);
            }

            \DB::commit();
            $this->clearCache();

            return $content;
        } catch (\Exception $e) {
            \DB::rollBack();
            throw new ContentException('Error updating content with relations: ' . $e->getMessage());
        }
    }

    /**
     * Get content with specific tag
     *
     * @param string $tag
     * @param array $columns
     * @return Collection
     */
    public function getByTag(string $tag, array $columns = ['*']): Collection
    {
        $cacheKey = $this->getCacheKey("tag:{$tag}");

        return Cache::remember($cacheKey, self::CACHE_TTL, function() use ($tag, $columns) {
            return $this->model
                ->select($columns)
                ->whereHas('tags', function($query) use ($tag) {
                    $query->where('name', $tag);
                })
                ->get();
        });
    }

    /**
     * Generate cache key
     *
     * @param string $key
     * @return string
     */
    protected function getCacheKey(string $key): string
    {
        return self::CACHE_KEY_PREFIX . $key;
    }

    /**
     * Clear content cache
     *
     * @return void
     */
    protected function clearCache(): void
    {
        Cache::tags(['content'])->flush();
    }
}
