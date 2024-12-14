<?php

namespace App\Core\Tag\Contracts;

use Illuminate\Support\Collection;

interface TagRelationshipInterface
{
    /**
     * Get content relationships for a tag.
     *
     * @param int $tagId
     * @return Collection
     */
    public function getContentRelationships(int $tagId): Collection;

    /**
     * Get related tags based on content relationships.
     *
     * @param int $tagId
     * @param array $options
     * @return Collection
     */
    public function getRelatedTags(int $tagId, array $options = []): Collection;

    /**
     * Get tag hierarchy relationships.
     *
     * @param int $tagId
     * @return array
     */
    public function getHierarchyRelationships(int $tagId): array;

    /**
     * Sync tag relationships.
     *
     * @param int $tagId
     * @param array $relationships
     * @return void
     */
    public function syncRelationships(int $tagId, array $relationships): void;
}
