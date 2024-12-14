<?php

namespace App\Core\Repository;

use App\Models\Content;
use App\Core\Cache\CacheManager;
use App\Core\Events\ContentEvents;

class ContentRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Content::class;
    }

    public function getPublished(): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('getPublished'),
            $this->cacheTime,
            fn() => $this->model->with($this->with)
                               ->where('status', 'published')
                               ->orderBy('published_at', 'desc')
                               ->get()
        );
    }

    public function findBySlug(string $slug): ?Content
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('findBySlug', $slug),
            $this->cacheTime,
            fn() => $this->model->with($this->with)
                               ->where('slug', $slug)
                               ->first()
        );
    }

    public function updateStatus(int $id, string $status): Content
    {
        try {
            $content = $this->find($id);
            if (!$content) {
                throw new RepositoryException("Content not found with ID: {$id}");
            }

            $content->update(['status' => $status]);
            if ($status === 'published') {
                $content->published_at = now();
                $content->save();
            }

            $this->clearCache();
            event(new ContentEvents\ContentStatusUpdated($content));

            return $content->fresh();
        } catch (\Exception $e) {
            throw new RepositoryException("Failed to update content status: {$e->getMessage()}");
        }
    }
}

class CategoryRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Category::class;
    }

    public function findBySlug(string $slug): ?Category
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('findBySlug', $slug),
            $this->cacheTime,
            fn() => $this->model->with($this->with)
                               ->where('slug', $slug)
                               ->first()
        );
    }

    public function getTree(): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('getTree'),
            $this->cacheTime,
            fn() => $this->model->with($this->with)
                               ->whereNull('parent_id')
                               ->with('children')
                               ->get()
        );
    }
}

class TagRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Tag::class;
    }

    public function findBySlug(string $slug): ?Tag
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('findBySlug', $slug),
            $this->cacheTime,
            fn() => $this->model->with($this->with)
                               ->where('slug', $slug)
                               ->first()
        );
    }

    public function syncTags(int $contentId, array $tagIds): void
    {
        try {
            $content = app(ContentRepository::class)->find($contentId);
            if (!$content) {
                throw new RepositoryException("Content not found with ID: {$contentId}");
            }

            $content->tags()->sync($tagIds);
            $this->clearCache();
            Cache::tags(['content'])->flush();
        } catch (\Exception $e) {
            throw new RepositoryException("Failed to sync tags: {$e->getMessage()}");
        }
    }
}
