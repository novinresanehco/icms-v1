<?php

namespace App\Core\Tag\Contracts;

use App\Core\Tag\Models\Tag;
use App\Core\Repository\Contracts\RepositoryInterface;
use Illuminate\Support\Collection;

interface TagRepositoryInterface extends RepositoryInterface
{
    /**
     * Find tag by slug
     *
     * @param string $slug
     * @return Tag|null
     */
    public function findBySlug(string $slug): ?Tag;

    /**
     * Get popular tags
     *
     * @param int $limit
     * @return Collection
     */
    public function getPopular(int $limit = 10): Collection;

    /**
     * Get tags by content
     *
     * @param int $contentId
     * @return Collection
     */
    public function getByContent(int $contentId): Collection;

    /**
     * Attach tags to content
     *
     * @param int $contentId
     * @param array $tagIds
     * @return void
     */
    public function attachToContent(int $contentId, array $tagIds): void;

    /**
     * Detach tags from content
     *
     * @param int $contentId
     * @param array $tagIds
     * @return void
     */
    public function detachFromContent(int $contentId, array $tagIds): void;

    /**
     * Merge tags
     *
     * @param int $sourceTagId
     * @param int $targetTagId
     * @return Tag
     */
    public function mergeTags(int $sourceTagId, int $targetTagId): Tag;

    /**
     * Get related tags
     *
     * @param int $tagId
     * @param int $limit
     * @return Collection
     */
    public function getRelated(int $tagId, int $limit = 5): Collection;

    /**
     * Get tag suggestions
     *
     * @param string $query
     * @param int $limit
     * @return Collection
     */
    public function getSuggestions(string $query, int $limit = 5): Collection;

    /**
     * Clean unused tags
     *
     * @return int Number of deleted tags
     */
    public function cleanUnused(): int;
}
