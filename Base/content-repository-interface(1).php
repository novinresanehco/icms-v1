<?php

declare(strict_types=1);

namespace App\Repositories\Interfaces;

use App\Models\Content;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface ContentRepositoryInterface
{
    /**
     * Find content by ID
     *
     * @param int $id
     * @param array $with
     * @return Content|null
     */
    public function findById(int $id, array $with = []): ?Content;

    /**
     * Find content by slug
     *
     * @param string $slug
     * @param array $with
     * @return Content|null
     */
    public function findBySlug(string $slug, array $with = []): ?Content;

    /**
     * Create new content
     *
     * @param array $data
     * @return Content
     */
    public function create(array $data): Content;

    /**
     * Update content
     *
     * @param int $id
     * @param array $data
     * @return bool
     */
    public function update(int $id, array $data): bool;

    /**
     * Delete content
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool;

    /**
     * Paginate content
     *
     * @param int $perPage
     * @param array $filters
     * @param array $with
     * @return LengthAwarePaginator
     */
    public function paginate(int $perPage = 15, array $filters = [], array $with = []): LengthAwarePaginator;

    /**
     * Get content by type
     *
     * @param string $type
     * @param array $with
     * @return Collection
     */
    public function getByType(string $type, array $with = []): Collection;

    /**
     * Update content metadata
     *
     * @param int $contentId
     * @param array $meta
     * @return void
     */
    public function updateMeta(int $contentId, array $meta): void;
}