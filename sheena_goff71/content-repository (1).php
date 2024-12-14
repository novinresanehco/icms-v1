<?php

namespace App\Core\Content\Repository;

use App\Core\Content\Models\Content;
use App\Core\Repository\BaseRepository;
use App\Core\Content\Contracts\ContentRepositoryInterface;
use App\Core\Content\Exceptions\ContentException;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;

class ContentRepository extends BaseRepository implements ContentRepositoryInterface
{
    /**
     * ContentRepository constructor.
     *
     * @param Content $model
     */
    public function __construct(Content $model)
    {
        parent::__construct($model);
        $this->setCacheTags(['content']);
    }

    /**
     * Find content by slug
     *
     * @param string $slug
     * @return Content|null
     */
    public function findBySlug(string $slug): ?Content
    {
        return $this->cacheResult(
            "content_slug_{$slug}",
            fn() => $this->model->where('slug', $slug)->first()
        );
    }

    /**
     * Get published content
     *
     * @param array $columns
     * @return Collection
     */
    public function getPublished(array $columns = ['*']): Collection
    {
        return $this->cacheResult(
            'content_published_' . implode('_', $columns),
            fn() => $this->model->published()
                ->orderBy('published_at', 'desc')
                ->get($columns)
        );
    }

    /**
     * Get content by category
     *
     * @param int $categoryId
     * @param array $columns
     * @return Collection
     */
    public function getByCategory(int $categoryId, array $columns = ['*']): Collection
    {
        return $this->cacheResult(
            "content_category_{$categoryId}_" . implode('_', $columns),
            fn() => $this->model->whereHas('categories', function (Builder $query) use ($categoryId) {
                $query->where('categories.id', $categoryId);
            })->get($columns)
        );
    }

    /**
     * Get content by tag
     *
     * @param int $tagId
     * @param array $columns
     * @return Collection
     */
    public function getByTag(int $tagId, array $columns = ['*']): Collection
    {
        return $this->cacheResult(
            "content_tag_{$tagId}_" . implode('_', $columns),
            fn() => $this->model->whereHas('tags', function (Builder $query) use ($tagId) {
                $query->where('tags.id', $tagId);
            })->get($columns)
        );
    }

    /**
     * Search content
     *
     * @param string $term
     * @param array $columns
     * @return Collection
     */
    public function search(string $term, array $columns = ['*']): Collection
    {
        return $this->model->where(function (Builder $query) use ($term) {
            $query->where('title', 'LIKE', "%{$term}%")
                ->orWhere('content', 'LIKE', "%{$term}%")
                ->orWhere('excerpt', 'LIKE', "%{$term}%");
        })->get($columns);
    }

    /**
     * Publish content
     *
     * @param int $id
     * @return Content
     */
    public function publish(int $id): Content
    {
        $content = $this->find($id);
        
        if (!$content) {
            throw new ContentException("Content not found with ID: {$id}");
        }

        $this->beginTransaction();

        try {
            $content->update([
                'status' => 'published',
                'published_at' => now()
            ]);

            $this->commit();
            $this->clearCache();

            return $content;
        } catch (\Exception $e) {
            $this->rollback();
            throw new ContentException("Error publishing content: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Unpublish content
     *
     * @param int $id
     * @return Content
     */
    public function unpublish(int $id): Content
    {
        $content = $this->find($id);
        
        if (!$content) {
            throw new ContentException("Content not found with ID: {$id}");
        }

        $this->beginTransaction();

        try {
            $content->update([
                'status' => 'draft',
                'published_at' => null
            ]);

            $this->commit();
            $this->clearCache();

            return $content;
        } catch (\Exception $e) {
            $this->rollback();
            throw new ContentException("Error unpublishing content: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Get related content
     *
     * @param int $contentId
     * @param int $limit
     * @return Collection
     */
    public function getRelated(int $contentId, int $limit = 5): Collection
    {
        $content = $this->find($contentId);
        
        if (!$content) {
            return new Collection();
        }

        return $this->cacheResult(
            "content_related_{$contentId}_{$limit}",
            function () use ($content, $limit) {
                $tagIds = $content->tags->pluck('id')->toArray();
                $categoryIds = $content->categories->pluck('id')->toArray();

                return $this->model->where('id', '!=', $content->id)
                    ->where('status', 'published')
                    ->where(function (Builder $query) use ($tagIds, $categoryIds) {
                        $query->whereHas('tags', function (Builder $q) use ($tagIds) {
                            $q->whereIn('tags.id', $tagIds);
                        })->orWhereHas('categories', function (Builder $q) use ($categoryIds) {
                            $q->whereIn('categories.id', $categoryIds);
                        });
                    })
                    ->orderBy('published_at', 'desc')
                    ->limit($limit)
                    ->get();
            }
        );
    }

    /**
     * Get content versions
     *
     * @param int $contentId
     * @return Collection
     */
    public function getVersions(int $contentId): Collection
    {
        return $this->cacheResult(
            "content_versions_{$contentId}",
            fn() => $this->model->findOrFail($contentId)
                ->versions()
                ->orderBy('created_at', 'desc')
                ->get()
        );
    }

    /**
     * Create content version
     *
     * @param int $contentId
     * @return Content
     */
    public function createVersion(int $contentId): Content
    {
        $content = $this->find($contentId);
        
        if (!$content) {
            throw new ContentException("Content not found with ID: {$contentId}");
        }

        $this->beginTransaction();

        try {
            $version = $content->versions()->create([
                'title' => $content->title,
                'content' => $content->content,
                'excerpt' => $content->excerpt,
                'meta_data' => $content->meta_data,
                'version_number' => $content->versions()->count() + 1,
                'created_by' => auth()->id()
            ]);

            $this->commit();
            $this->clearCache();

            return $content->fresh();
        } catch (\Exception $e) {
            $this->rollback();
            throw new ContentException("Error creating content version: {$e->getMessage()}", 0, $e);
        }
    }
}
