<?php

namespace App\Core\Tag\Repository;

use App\Core\Tag\Models\Tag;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;
use App\Core\Tag\Contracts\TagRelationshipInterface;
use App\Core\Tag\Exceptions\TagRelationshipException;

class TagRelationshipRepository implements TagRelationshipInterface
{
    /**
     * @var Tag
     */
    protected Tag $model;

    public function __construct(Tag $model)
    {
        $this->model = $model;
    }

    /**
     * Get content relationships for a tag.
     */
    public function getContentRelationships(int $tagId): Collection
    {
        return $this->model->findOrFail($tagId)
            ->contents()
            ->with(['author', 'category'])
            ->orderByDesc('taggables.created_at')
            ->get();
    }

    /**
     * Get related tags based on content relationships.
     */
    public function getRelatedTags(int $tagId, array $options = []): Collection
    {
        $limit = $options['limit'] ?? 10;
        $minScore = $options['min_score'] ?? 0.3;

        $tag = $this->model->findOrFail($tagId);
        $contentIds = $tag->contents()->pluck('id');

        return $this->model
            ->whereHas('contents', function (Builder $query) use ($contentIds) {
                $query->whereIn('id', $contentIds);
            })
            ->where('id', '!=', $tagId)
            ->withCount(['contents' => function (Builder $query) use ($contentIds) {
                $query->whereIn('id', $contentIds);
            }])
            ->having('contents_count', '>=', $minScore * $contentIds->count())
            ->orderByDesc('contents_count')
            ->limit($limit)
            ->get();
    }

    /**
     * Get tag hierarchy relationships.
     */
    public function getHierarchyRelationships(int $tagId): array
    {
        $tag = $this->model->findOrFail($tagId);

        return [
            'parents' => $this->getParentTags($tag),
            'children' => $this->getChildTags($tag),
            'siblings' => $this->getSiblingTags($tag)
        ];
    }

    /**
     * Sync tag relationships.
     */
    public function syncRelationships(int $tagId, array $relationships): void
    {
        try {
            $tag = $this->model->findOrFail($tagId);

            \DB::transaction(function () use ($tag, $relationships) {
                if (isset($relationships['parent_ids'])) {
                    $tag->parents()->sync($relationships['parent_ids']);
                }

                if (isset($relationships['child_ids'])) {
                    $tag->children()->sync($relationships['child_ids']);
                }

                // Update content relationships if provided
                if (isset($relationships['content_ids'])) {
                    $tag->contents()->sync($relationships['content_ids']);
                }
            });
        } catch (\Exception $e) {
            throw new TagRelationshipException(
                "Failed to sync relationships: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get parent tags.
     */
    protected function getParentTags(Tag $tag): Collection
    {
        return $tag->parents()
            ->with('metadata')
            ->orderBy('name')
            ->get();
    }

    /**
     * Get child tags.
     */
    protected function getChildTags(Tag $tag): Collection
    {
        return $tag->children()
            ->with('metadata')
            ->orderBy('name')
            ->get();
    }

    /**
     * Get sibling tags.
     */
    protected function getSiblingTags(Tag $tag): Collection
    {
        $parentIds = $tag->parents()->pluck('id');

        return $this->model
            ->whereHas('parents', function (Builder $query) use ($parentIds) {
                $query->whereIn('id', $parentIds);
            })
            ->where('id', '!=', $tag->id)
            ->orderBy('name')
            ->get();
    }

    /**
     * Validate relationship structure.
     */
    protected function validateRelationshipStructure(array $relationships): void
    {
        $validator = \Validator::make($relationships, [
            'parent_ids' => 'array',
            'parent_ids.*' => 'exists:tags,id',
            'child_ids' => 'array',
            'child_ids.*' => 'exists:tags,id',
            'content_ids' => 'array',
            'content_ids.*' => 'exists:contents,id'
        ]);

        if ($validator->fails()) {
            throw new TagRelationshipException(
                'Invalid relationship structure: ' . 
                implode(', ', $validator->errors()->all())
            );
        }
    }
}
