<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface MediaRepositoryInterface
{
    /**
     * Store new media file
     *
     * @param array $fileData
     * @return int|null
     */
    public function store(array $fileData): ?int;

    /**
     * Update media metadata
     *
     * @param int $mediaId
     * @param array $data
     * @return bool
     */
    public function update(int $mediaId, array $data): bool;

    /**
     * Get media by ID
     *
     * @param int $mediaId
     * @return array|null
     */
    public function get(int $mediaId): ?array;

    /**
     * Delete media
     *
     * @param int $mediaId
     * @return bool
     */
    public function delete(int $mediaId): bool;

    /**
     * Get paginated media list
     *
     * @param int $perPage
     * @param array $filters
     * @return LengthAwarePaginator
     */
    public function getPaginated(int $perPage = 20, array $filters = []): LengthAwarePaginator;

    /**
     * Get media by type
     *
     * @param string $type
     * @return Collection
     */
    public function getByType(string $type): Collection;

    /**
     * Get total storage usage
     *
     * @return int
     */
    public function getTotalStorageUsage(): int;

    /**
     * Get user storage usage
     *
     * @param int $userId
     * @return int
     */
    public function getUserStorageUsage(int $userId): int;

    /**
     * Get unused media files
     *
     * @param int $days
     * @return Collection
     */
    public function getUnusedMedia(int $days): Collection;

    /**
     * Update last used timestamp
     *
     * @param int $mediaId
     * @return bool
     */
    public function updateLastUsed(int $mediaId): bool;
}
