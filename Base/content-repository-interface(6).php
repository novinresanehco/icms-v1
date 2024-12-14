<?php

namespace App\Core\Repositories\Contracts;

use App\Models\Content;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface ContentRepositoryInterface
{
    /**
     * Get content by ID
     *
     * @param int $id
     * @return Content|null
     */
    public function find(int $id): ?Content;

    /**
     * Get published content by slug
     *
     * @param string $slug
     * @return Content|null
     */
    public function findBySlug(string $slug): ?Content;

    /**
     * Get all content with pagination
     *
     * @param int $perPage
     * @param array $filters
     * @return LengthAwarePaginator
     */
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;

    /**
     * Create new content
     *
     * @param array $data
     * @return Content
     */
    public function create(array $data): Content;

    /**
     * Update existing content
     *
     * @param int $id
     * @param array $data
     * @return Content
     */
    public function update(int $id, array $data): Content;

    /**
     * Delete content
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool;

    /**
     * Get content by type
     *
     * @param string $type
     * @param int $limit
     * @return Collection
     */
    public function getByType(string $type, int $limit = 10): Collection;

    /**
     * Get content by category
     *
     * @param int $categoryId
     * @param int $limit
     * @return Collection
     */
    public function getByCategory(int $categoryId, int $limit = 10): Collection;

    /**
     * Search content
     *
     * @param string $query
     * @param array $options
     * @return Collection
     */
    public function search(string $query, array $options = []): Collection;

    /**
     * Get related content
     *
     * @param int $contentId
     * @param int $limit
     * @return Collection
     */
    public function getRelated(int $contentId, int $limit = 5): Collection;
}
