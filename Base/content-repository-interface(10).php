<?php

namespace App\Core\Repositories\Contracts;

use App\Models\Content;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

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
     * @param int $perPage
     * @param array $filters
     * @return LengthAwarePaginator
     */
    public function getPublished(int $perPage = 15, array $filters = []): LengthAwarePaginator;

    /**
     * Get content by type
     *
     * @param string $type
     * @param array $filters
     * @return Collection
     */
    public function getByType(string $type, array $filters = []): Collection;

    /**
     * Publish content
     *
     * @param int $contentId
     * @param array $publishConfig
     * @return Content
     */
    public function publish(int $contentId, array $publishConfig = []): Content;

    /**
     * Unpublish content
     *
     * @param int $contentId
     * @return Content
     */
    public function unpublish(int $contentId): Content;

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
     * @param array $data
     * @return Content
     */
    public function createVersion(int $contentId, array $data): Content;

    /**
     * Restore content version
     *
     * @param int $contentId
     * @param int $versionId
     * @return Content
     */
    public function restoreVersion(int $contentId, int $versionId): Content;

    /**
     * Get related content
     *
     * @param int $contentId
     * @param int $limit
     * @return Collection
     */
    public function getRelated(int $contentId, int $limit = 5): Collection;
}
