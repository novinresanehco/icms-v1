<?php

namespace App\Core\Repositories\Contracts;

use App\Models\Tag;
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
     * Get tags with content count
     *
     * @return Collection
     */
    public function getWithContentCount(): Collection;

    /**
     * Merge tags
     *
     * @param int $sourceTagId
     * @param int $targetTagId
     * @return Tag
     */
    public function mergeTags(int $sourceTagId, int $targetTagId): Tag;

    /**
     * Find or create multiple tags
     *
     * @param array $tagNames
     * @return Collection
     */
    public function findOrCreateMany(array $tagNames): Collection;
}
