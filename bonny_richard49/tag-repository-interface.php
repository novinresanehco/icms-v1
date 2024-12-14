<?php

namespace App\Core\Tag\Repository;

use App\Core\Tag\Models\Tag;
use App\Core\Shared\Repository\RepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

interface TagRepositoryInterface extends RepositoryInterface
{
    /**
     * Find tag by slug.
     *
     * @param string $slug
     * @return Tag|null
     */
    public function findBySlug(string $slug): ?Tag;

    /**
     * Find or create tag by name.
     *
     * @param string $name
     * @return Tag
     */
    public function findOrCreateByName(string $name): Tag;

    /**
     * Get most used tags.
     *
     * @param int $limit
     * @return Collection
     */
    public function getMostUsed(int $limit = 10): Collection;

    /**
     * Get tags for content.
     *
     * @param int $contentId
     * @return Collection
     */
    public function getForContent(int $contentId): Collection;

    /**
     * Sync tags for content.
     *
     * @param int $contentId
     * @param array $tagIds
     * @return void
     */
    public function syncWithContent(int $contentId, array $tagIds): void;

    /**
     * Get related tags.
     *
     * @param int $tagId
     * @param int $limit
     * @return Collection
     */
    public function getRelated(int $tagId, int $limit = 5): Collection;

    /**
     * Search tags.
     *
     * @param string $query
     * @return Collection
     */
    public function search(string $query): Collection;

    /**
     * Get tag usage statistics.
     *
     * @param int $tagId
     * @return array
     */
    public function getUsageStats(int $tagId): array;

    /**
     * Merge tags.
     *
     * @param int $sourceTagId
     * @param int $targetTagId
     * @return Tag
     */
    public function mergeTags(int $sourceTagId, int $targetTagId): Tag;
}
