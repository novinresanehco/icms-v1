<?php

namespace App\Repositories\Contracts;

use App\Models\Content;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ContentRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Get published content items with optional pagination
     *
     * @param int $perPage
     * @param array $relations
     * @return LengthAwarePaginator
     */
    public function getPublished(int $perPage = 15, array $relations = []): LengthAwarePaginator;

    /**
     * Get content by slug ensuring it's published
     *
     * @param string $slug
     * @param array $relations
     * @return Content|null
     */
    public function getPublishedBySlug(string $slug, array $relations = []): ?Content;

    /**
     * Get content items by category
     *
     * @param int $categoryId
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getByCategory(int $categoryId, int $perPage = 15): LengthAwarePaginator;

    /**
     * Schedule content for publishing
     *
     * @param int $id
     * @param string $publishAt
     * @return bool
     */
    public function schedulePublish(int $id, string $publishAt): bool;

    /**
     * Get content statistics
     *
     * @return array
     */
    public function getContentStats(): array;

    /**
     * Get related content items
     *
     * @param int $contentId
     * @param int $limit
     * @return Collection
     */
    public function getRelated(int $contentId, int $limit = 5): Collection;

    /**
     * Update content status
     *
     * @param int $id
     * @param string $status
     * @return bool
     */
    public function updateStatus(int $id, string $status): bool;

    /**
     * Search content with advanced filters
     *
     * @param array $filters
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function advancedSearch(array $filters, int $perPage = 15): LengthAwarePaginator;
}
