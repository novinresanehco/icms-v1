<?php

namespace App\Repositories;

use App\Models\Content;
use App\Core\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

class ContentRepository extends BaseRepository
{
    protected array $searchable = ['title', 'content', 'slug', 'meta_description'];
    protected array $with = ['author', 'categories', 'tags'];

    public function __construct(Content $model)
    {
        $this->model = $model;
        parent::__construct();
    }

    public function findBySlug(string $slug): ?Content
    {
        return $this->cache->remember(
            $this->getCacheKey(__FUNCTION__, $slug),
            $this->cacheTtl,
            fn() => $this->model->where('slug', $slug)->with($this->with)->first()
        );
    }

    public function getPublished(): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey(__FUNCTION__),
            $this->cacheTtl,
            fn() => $this->model->published()->with($this->with)->latest()->get()
        );
    }

    public function findByCategory(int $categoryId): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey(__FUNCTION__, $categoryId),
            $this->cacheTtl,
            fn() => $this->model->whereHas('categories', function($query) use ($categoryId) {
                $query->where('id', $categoryId);
            })->with($this->with)->get()
        );
    }

    public function findByTag(string $tag): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey(__FUNCTION__, $tag),
            $this->cacheTtl,
            fn() => $this->model->whereHas('tags', function($query) use ($tag) {
                $query->where('name', $tag);
            })->with($this->with)->get()
        );
    }

    public function getRecent(int $limit = 5): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey(__FUNCTION__, $limit),
            $this->cacheTtl,
            fn() => $this->model->published()
                ->with($this->with)
                ->latest()
                ->limit($limit)
                ->get()
        );
    }

    public function getPopular(int $limit = 5): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey(__FUNCTION__, $limit),
            $this->cacheTtl,
            fn() => $this->model->published()
                ->with($this->with)
                ->orderByDesc('view_count')
                ->limit($limit)
                ->get()
        );
    }

    public function incrementViews(int $id): void
    {
        $this->model->where('id', $id)->increment('view_count');
        $this->clearCache();
    }

    public function getRelated(Content $content, int $limit = 5): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey(__FUNCTION__, [$content->id, $limit]),
            $this->cacheTtl,
            function() use ($content, $limit) {
                return $this->model->published()
                    ->where('id', '!=', $content->id)
                    ->whereHas('categories', function($query) use ($content) {
                        $query->whereIn('id', $content->categories->pluck('id'));
                    })
                    ->with($this->with)
                    ->limit($limit)
                    ->get();
            }
        );
    }
}
