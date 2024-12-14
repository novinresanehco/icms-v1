<?php

namespace App\Core\Content\Repository;

use App\Core\Content\Models\Content;
use App\Core\Content\DTO\ContentData;
use App\Core\Shared\Repository\RepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use DateTime;

interface ContentRepositoryInterface extends RepositoryInterface
{
    /**
     * Create new content
     *
     * @param ContentData $data
     * @return Content
     */
    public function create(ContentData $data): Content;

    /**
     * Update existing content
     *
     * @param int $id
     * @param ContentData $data
     * @return Content
     */
    public function update(int $id, ContentData $data): Content;

    /**
     * Find content by slug
     *
     * @param string $slug
     * @return Content|null
     */
    public function findBySlug(string $slug): ?Content;

    /**
     * Find published content
     *
     * @param int $id
     * @return Content|null
     */
    public function findPublished(int $id): ?Content;

    /**
     * Get paginated published content
     *
     * @param int $page
     * @param int $perPage
     * @param array $filters
     * @return LengthAwarePaginator
     */
    public function getPaginatedPublished(int $page = 1, int $perPage = 15, array $filters = []): LengthAwarePaginator;

    /**
     * Find content by category
     *
     * @param int $categoryId
     * @param array $options
     * @return Collection
     */
    public function findByCategory(int $categoryId, array $options = []): Collection;

    /**
     * Find content by multiple tags
     *
     * @param array $tagIds
     * @param array $options
     * @return Collection
     */
    public function findByTags(array $tagIds, array $options = []): Collection;

    /**
     * Search content
     *
     * @param string $query
     * @param array $options
     * @return Collection
     */
    public function search(string $query, array $options = []): Collection;

    /**
     * Get featured content
     *
     * @param int $limit
     * @return Collection
     */
    public function getFeatured(int $limit = 5): Collection;

    /**
     * Get latest content
     *
     * @param int $limit
     * @return Collection
     */
    public function getLatest(int $limit = 10): Collection;

    /**
     * Get related content
     *
     * @param Content $content
     * @param int $limit
     * @return Collection
     */
    public function getRelated(Content $content, int $limit = 5): Collection;

    /**
     * Update content status
     *
     * @param int $id
     * @param string $status
     * @param DateTime|null $publishedAt
     * @return Content
     */
    public function updateStatus(int $id, string $status, ?DateTime $publishedAt = null): Content;

    /**
     * Update content tags
     *
     * @param int $id
     * @param array $tagIds
     * @return Content
     */
    public function syncTags(int $id, array $tagIds): Content;

    /**
     * Update content media
     *
     * @param int $id
     * @param array $mediaIds
     * @return Content
     */
    public function syncMedia(int $id, array $mediaIds): Content;

    /**
     * Increment view count
     *
     * @param int $id
     * @return bool
     */
    public function incrementViews(int $id): bool;
}
