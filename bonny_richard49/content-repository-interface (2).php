<?php

namespace App\Core\Content\Repository;

use App\Core\Content\Models\Content;
use App\Core\Shared\Repository\RepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface ContentRepositoryInterface extends RepositoryInterface
{
    /**
     * Find content by slug.
     *
     * @param string $slug
     * @return Content|null
     */
    public function findBySlug(string $slug): ?Content;

    /**
     * Find published content.
     *
     * @param int $id
     * @return Content|null
     */
    public function findPublished(int $id): ?Content;

    /**
     * Get paginated published content.
     *
     * @param int $perPage
     * @param array $options
     * @return LengthAwarePaginator
     */
    public function paginatePublished(int $perPage = 15, array $options = []): LengthAwarePaginator;

    /**
     * Find content by category.
     *
     * @param int $categoryId
     * @param array $options
     * @return Collection
     */
    public function findByCategory(int $categoryId, array $options = []): Collection;

    /**
     * Find content with specific tags.
     *
     * @param array $tagIds
     * @param array $options
     * @return Collection
     */
    public function findByTags(array $tagIds, array $options = []): Collection;

    /**
     * Search content.
     *
     * @param string $query
     * @param array $options
     * @return Collection
     */
    public function search(string $query, array $options = []): Collection;

    /**
     * Get featured content.
     *
     * @param int $limit
     * @return Collection
     */
    public function getFeatured(int $limit = 5): Collection;

    /**
     * Get latest content.
     *
     * @param int $limit
     * @return Collection
     */
    public function getLatest(int $limit = 10): Collection;

    /**
     * Get popular content.
     *
     * @param int $limit
     * @return Collection
     */
    public function getPopular(int $limit = 10): Collection;

    /**
     * Get related content.
     *
     * @param Content $content
     * @param int $limit
     * @return Collection
     */
    public function getRelated(Content $content, int $limit = 5): Collection;
}
