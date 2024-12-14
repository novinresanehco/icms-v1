<?php

namespace App\Repositories\Contracts;

use App\Models\Media;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface MediaRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Store media file with metadata
     *
     * @param array $fileData
     * @param array $metadata
     * @return Media|null
     */
    public function storeMedia(array $fileData, array $metadata): ?Media;

    /**
     * Get media by type
     *
     * @param string $type
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getByType(string $type, int $perPage = 15): LengthAwarePaginator;

    /**
     * Get media by folder
     *
     * @param int $folderId
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getByFolder(int $folderId, int $perPage = 15): LengthAwarePaginator;

    /**
     * Move media to folder
     *
     * @param array $mediaIds
     * @param int|null $folderId
     * @return bool
     */
    public function moveToFolder(array $mediaIds, ?int $folderId): bool;

    /**
     * Delete media with file
     *
     * @param int $id
     * @return bool
     */
    public function deleteWithFile(int $id): bool;

    /**
     * Get media usage statistics
     *
     * @return array
     */
    public function getMediaStats(): array;

    /**
     * Search media with advanced filters
     *
     * @param array $filters
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function advancedSearch(array $filters, int $perPage = 15): LengthAwarePaginator;
}
