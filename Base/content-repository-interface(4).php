<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface ContentRepositoryInterface
{
    /**
     * Create new content
     *
     * @param array $data
     * @return int|null
     */
    public function createContent(array $data): ?int;

    /**
     * Update existing content
     *
     * @param int $contentId
     * @param array $data
     * @return bool
     */
    public function updateContent(int $contentId, array $data): bool;

    /**
     * Get content by ID
     *
     * @param int $contentId
     * @param bool $withVersion
     * @return array|null
     */
    public function getContent(int $contentId, bool $withVersion = true): ?array;

    /**
     * Get content by slug
     *
     * @param string $slug
     * @return array|null
     */
    public function getContentBySlug(string $slug): ?array;

    /**
     * Get paginated content list
     *
     * @param int $perPage
     * @param array $filters
     * @return LengthAwarePaginator
     */
    public function getPaginatedContent(int $perPage = 20, array $filters = []): LengthAwarePaginator;

    /**
     * Delete content
     *
     * @param int $contentId
     * @return bool
     */
    public function deleteContent(int $contentId): bool;

    /**
     * Get content versions
     *
     * @param int $contentId
     * @return Collection
     */
    public function getContentVersions(int $contentId): Collection;

    /**
     * Get specific content version
     *
     * @param int $contentId
     * @param int $versionId
     * @return array|null
     */
    public function getContentVersion(int $contentId, int $versionId): ?array;
}
