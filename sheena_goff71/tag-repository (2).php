<?php

namespace App\Core\Tag\Repository;

use App\Core\Tag\Models\Tag;
use App\Core\Repository\BaseRepository;
use App\Core\Tag\Contracts\TagRepositoryInterface;
use App\Core\Tag\Exceptions\TagException;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class TagRepository extends BaseRepository implements TagRepositoryInterface
{
    /**
     * @param Tag $model
     */
    public function __construct(Tag $model)
    {
        parent::__construct($model);
        $this->setCacheTags(['tags']);
    }

    /**
     * Create a new tag
     *
     * @param array $data
     * @return Tag
     * @throws TagException
     */
    public function create(array $data): Tag
    {
        $this->beginTransaction();

        try {
            // Generate slug if not provided
            $data['slug'] = $data['slug'] ?? Str::slug($data['name']);

            // Check for duplicate slug
            if ($this->findBySlug($data['slug'])) {
                throw new TagException("Tag with slug '{$data['slug']}' already exists");
            }

            $tag = parent::create($data);

            $this->commit();
            $this->clearCache();

            return $tag;
        } catch (\Exception $e) {
            $this->rollback();
            throw new TagException("Error creating tag: {$e->getMessage()}", 0, $e);
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
        return $this->cacheResult(
            "tag_slug_{$slug}",
            fn() => $this->model->where('slug', $slug)->first()
        );
    }

    /**
     * Get popular tags
     *
     * @param int $limit
     * @return Collection
     */
    public function getPopular(int $limit = 10): Collection
    {
        return $this->cacheResult(
            "popular_tags_{$limit}",
            fn() => $this->model->withCount('contents')
                ->orderBy('contents_count', 'desc')
                ->limit($limit)
                ->get()
        );
    }

    /**
     * Get tags by content
     *
     * @param int $contentId
     * @return Collection
     */
    public function getByContent(int $contentId): Collection
    {
        return $this->cacheResult(
            "content_tags_{$contentId}",
            fn() => $this->model->whereHas('contents', function (Builder $query) use ($contentId) {
                $query->where('content.id', $contentId);
            })->get()
        );
    }

    /**
     * Attach tags to content
     *
     * @param int $contentId
     * @param array $tagIds
     * @return void
     */
    public function attachToContent(int $contentId, array $tagIds): void
    {
        $this->beginTransaction();

        try {
            $content = app('App\Core\Content\Models\Content')->findOrFail($contentId);
            $content->tags()->syncWithoutDetaching($tagIds);

            $this->commit();
            $this->clearCache();
        } catch (\Exception $e) {
            $this->rollback();
            throw new TagException("Error attaching tags to content: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Detach tags from content
     *
     * @param int $contentId
     * @param array $tagIds
     * @return void
     */
    public function detachFromContent(int $contentId, array $tagIds): void
    {
        $this->beginTransaction();

        try {
            $content = app('App\Core\Content\Models\Content')->findOrFail($contentId);
            $content->tags()->detach($tagIds);

            $this->commit();
            $this->clearCache();
        } catch (\Exception $e) {
            $this->rollback();
            throw new TagException("Error detaching tags from content: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Merge tags
     *
     * @param int $sourceTagId
     * @param int $targetTagId
     * @return Tag
     */
    public function mergeTags(int $sourceTagId, int $targetTagId): Tag
    {
        $this->beginTransaction();

        try {
            $sourceTag = $this->find($sourceTagId);
            $targetTag = $this->find($targetTagId);

            if (!$sourceTag || !$targetTag) {
                throw new TagException("Source or target tag not found");
            }

            // Move all content relationships to target tag
            $sourceTag->contents()->each(function ($content) use ($targetTag) {
                if (!$content->tags()->where('tags.id', $targetTag->id)->exists()) {
                    $content->tags()->attach($targetTag->id);
                }
            });

            // Delete source tag
            $sourceTag->delete();

            $this->commit();
            $this->clearCache();

            return $targetTag->fresh();
        } catch (\Exception $e) {
            $this->rollback();
            throw new TagException("Error merging tags: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Get related tags
     *
     * @param int $tagId
     * @param int $limit
     * @return Collection
     */
    public function getRelated(int $tagId, int $limit = 5): Collection
    {
        return $this->cacheResult(
            "related_tags_{$tagId}_{$limit}",
            function () use ($tagId, $limit) {
                $tag = $this->find($tagId);
                
                if (!$tag) {
                    return new Collection();
                }

                $contentIds = $tag->contents->pluck('id');

                return $this->model->where('id', '!=', $tagId)
                    ->whereHas('contents', function (Builder $query) use ($contentIds) {
                        $query->whereIn('content.id', $contentIds);
                    })
                    ->withCount(['contents' => function (Builder $query) use ($contentIds) {
                        $query->whereIn('content.id', $contentIds);
                    }])
                    ->orderBy('contents_count', 'desc')
                    ->limit($limit)
                    ->get();
            }
        );
    }

    /**
     * Get tag suggestions
     *
     * @param string $query
     * @param int $limit
     * @return Collection
     */
    public function getSuggestions(string $query, int $limit = 5): Collection
    {
        return $this->model->where('name', 'LIKE', "{$query}%")
            ->orWhere('slug', 'LIKE', "{$query}%")
            ->limit($limit)
            ->get();
    }

    /**
     * Clean unused tags
     *
     * @return int Number of deleted tags
     */
    public function cleanUnused(): int
    {
        $this->beginTransaction();

        try {
            $count = $this->model->whereDoesntHave('contents')->delete();

            $this->commit();
            $this->clearCache();

            return $count;
        } catch (\Exception $e) {
            $this->rollback();
            throw new TagException("Error cleaning unused tags: {$e->getMessage()}", 0, $e);
        }
    }
}
