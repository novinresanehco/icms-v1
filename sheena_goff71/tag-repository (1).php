<?php

namespace App\Core\Tag\Repositories;

use App\Core\Tag\Models\Tag;
use Illuminate\Support\Collection;
use App\Core\Tag\Events\{TagCreated, TagUpdated, TagsAttached, TagsMerged};
use App\Core\Tag\Exceptions\TagNotFoundException;
use Illuminate\Database\Eloquent\Builder;

class TagRepository implements TagRepositoryInterface 
{
    /**
     * @var Tag
     */
    protected Tag $model;

    /**
     * @param Tag $model
     */
    public function __construct(Tag $model)
    {
        $this->model = $model;
    }

    /**
     * Create a new tag.
     *
     * @param array $data
     * @return Tag
     */
    public function create(array $data): Tag
    {
        $tag = $this->model->create($data);
        
        event(new TagCreated($tag));
        
        return $tag;
    }

    /**
     * Update an existing tag.
     *
     * @param int $id
     * @param array $data
     * @return Tag
     * @throws TagNotFoundException
     */
    public function update(int $id, array $data): Tag
    {
        $tag = $this->findOrFail($id);
        
        $tag->update($data);
        
        event(new TagUpdated($tag));
        
        return $tag;
    }

    /**
     * Delete a tag.
     *
     * @param int $id
     * @return bool
     * @throws TagNotFoundException
     */
    public function delete(int $id): bool
    {
        $tag = $this->findOrFail($id);
        
        return $tag->delete();
    }

    /**
     * Find a tag by ID.
     *
     * @param int $id
     * @return Tag|null
     */
    public function find(int $id): ?Tag
    {
        return $this->model->find($id);
    }

    /**
     * Find a tag by ID or throw an exception.
     *
     * @param int $id
     * @return Tag
     * @throws TagNotFoundException
     */
    public function findOrFail(int $id): Tag
    {
        $tag = $this->find($id);
        
        if (!$tag) {
            throw new TagNotFoundException("Tag not found with ID: {$id}");
        }
        
        return $tag;
    }

    /**
     * Attach tags to content.
     *
     * @param int $contentId
     * @param array $tagIds
     * @return void
     */
    public function attachToContent(int $contentId, array $tagIds): void
    {
        $tags = $this->model->whereIn('id', $tagIds)->get();
        
        $content = Content::findOrFail($contentId);
        $content->tags()->sync($tagIds);
        
        event(new TagsAttached($contentId, $tags));
    }

    /**
     * Detach tags from content.
     *
     * @param int $contentId
     * @param array $tagIds
     * @return void
     */
    public function detachFromContent(int $contentId, array $tagIds): void
    {
        $content = Content::findOrFail($contentId);
        $content->tags()->detach($tagIds);
    }

    /**
     * Get content tags.
     *
     * @param int $contentId
     * @return Collection
     */
    public function getContentTags(int $contentId): Collection
    {
        return Content::findOrFail($contentId)->tags;
    }

    /**
     * Merge tags.
     *
     * @param int $sourceTagId
     * @param int $targetTagId
     * @return Tag
     */
    public function mergeTags(int $sourceTagId, int $targetTagId): Tag
    {
        $sourceTag = $this->findOrFail($sourceTagId);
        $targetTag = $this->findOrFail($targetTagId);

        // Move all content relationships from source to target
        DB::transaction(function () use ($sourceTag, $targetTag) {
            $sourceTag->contents()->each(function ($content) use ($targetTag) {
                if (!$content->tags()->where('id', $targetTag->id)->exists()) {
                    $content->tags()->attach($targetTag->id);
                }
            });

            $sourceTag->delete();
        });

        event(new TagsMerged($sourceTagId, $targetTag));

        return $targetTag;
    }

    /**
     * Search tags by name.
     * 
     * @param string $name
     * @return Collection
     */
    public function searchByName(string $name): Collection
    {
        return $this->model
            ->where('name', 'LIKE', "%{$name}%")
            ->orderBy('name')
            ->get();
    }

    /**
     * Get popular tags.
     *
     * @param int $limit
     * @return Collection
     */
    public function getPopularTags(int $limit = 10): Collection
    {
        return $this->model
            ->withCount('contents')
            ->orderByDesc('contents_count')
            ->limit($limit)
            ->get();
    }
}
