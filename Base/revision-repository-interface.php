<?php

declare(strict_types=1);

namespace App\Repositories\Interfaces;

use App\Models\Revision;
use Illuminate\Support\Collection;

interface RevisionRepositoryInterface
{
    /**
     * Find revision by ID
     *
     * @param int $id
     * @return Revision|null
     */
    public function findById(int $id): ?Revision;

    /**
     * Create new revision
     *
     * @param array $data
     * @return Revision
     */
    public function create(array $data): Revision;

    /**
     * Get revisions by content
     *
     * @param int $contentId
     * @return Collection
     */
    public function getByContent(int $contentId): Collection;

    /**
     * Get latest revision by content
     *
     * @param int $contentId
     * @return Revision|null
     */
    public function getLatestByContent(int $contentId): ?Revision;

    /**
     * Compare two revisions
     *
     * @param int $fromId
     * @param int $toId
     * @return array
     */
    public function compareVersions(int $fromId, int $toId): array;

    /**
     * Revert content to specific version
     *
     * @param int $contentId
     * @param int $versionNumber
     * @return bool
     */
    public function revertTo(int $contentId, int $versionNumber): bool;

    /**
     * Prune old revisions
     *
     * @param int $contentId
     * @param int $keepLast
     * @return bool
     */
    public function pruneRevisions(int $contentId, int $keepLast = 10): bool;
}