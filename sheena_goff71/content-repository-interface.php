<?php

namespace App\Core\Content\Contracts;

use App\Core\Content\Models\Content;
use App\Core\Repository\Contracts\RepositoryInterface;
use Illuminate\Support\Collection;

interface ContentRepositoryInterface extends RepositoryInterface
{
    /**
     * Find content by slug
     *
     * @param string $slug
     * @return Content|null
     */
    public function findBySlug(string $slug): ?Content;

    /**
     * Get published content
     *
     * @param array $columns
     * @return Collection
     */
    public function getPublished(array $columns = ['*']): Collection;

    /**
     * Get content by category
     *
     * @param int $categoryId
     * @param array $columns
     * @return Collection
     */
    public function getByCategory(int $categoryId, array $columns = ['*']): Collection;

    /**
     * Get content by tag
     *
     * @param int $tagId
     * @param array $columns
     * @return Collection
     */
    public function getByTag(int $tagId, array $columns = ['*']): Collection;

    /**
     * Search content
     *
     * @param string $term
     * @param array $columns
     * @return Collection
     */
    public function search(string $term, array $columns = ['*']): Collection;

    /**
     * Publish content
     *
     * @param int $id
     * @return Content
     */
    public function publish(int $id): Content;

    /**
     * Unpublish content
     *
     * @param int $id
     * @return Content
     */
    public function unpublish(int $id): Content;

    /**
     * Get related content
     *
     * @param int $contentId
     * @param int $limit
     * @return Collection
     */
    public function getRelated(int $contentId, int $limit = 5): Collection;

    /**
     * Get content versions
     *
     * @param int $contentId
     * @return Collection
     */
    public function getVersions(int $contentId): Collection;

    /**
     * Create content version
     *
     * @param int $contentId
     * @return Content
     */
    public function createVersion(int $contentId): Content;
}
