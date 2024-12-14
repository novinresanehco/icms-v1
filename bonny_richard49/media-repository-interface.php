<?php

namespace App\Core\Media\Repository;

use App\Core\Media\Models\Media;
use App\Core\Media\DTO\MediaData;
use App\Core\Shared\Repository\RepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;

interface MediaRepositoryInterface extends RepositoryInterface 
{
    /**
     * Upload new media file.
     *
     * @param UploadedFile $file
     * @param array $attributes
     * @return Media
     */
    public function upload(UploadedFile $file, array $attributes = []): Media;

    /**
     * Create media from external URL.
     *
     * @param string $url
     * @param array $attributes
     * @return Media
     */
    public function createFromUrl(string $url, array $attributes = []): Media;

    /**
     * Update media attributes.
     *
     * @param int $id
     * @param MediaData $data
     * @return Media
     */
    public function updateAttributes(int $id, MediaData $data): Media;

    /**
     * Get media by type.
     *
     * @param string $type
     * @param array $options
     * @return Collection
     */
    public function getByType(string $type, array $options = []): Collection;

    /**
     * Get paginated media files.
     *
     * @param int $page
     * @param int $perPage
     * @param array $filters
     * @return LengthAwarePaginator
     */
    public function paginate(int $page = 1, int $perPage = 15, array $filters = []): LengthAwarePaginator;

    /**
     * Get media files by folder.
     *
     * @param int $folderId
     * @param array $options
     * @return Collection
     */
    public function getByFolder(int $folderId, array $options = []): Collection;

    /**
     * Move media to folder.
     *
     * @param int $id
     * @param int|null $folderId
     * @return Media
     */
    public function moveToFolder(int $id, ?int $folderId): Media;

    /**
     * Generate thumbnails for media.
     *
     * @param int $id
     * @param array $sizes
     * @return Media
     */
    public function generateThumbnails(int $id, array $sizes = []): Media;

    /**
     * Get media usage statistics.
     *
     * @param int $id
     * @return array
     */
    public function getUsageStats(int $id): array;

    /**
     * Search media files.
     *
     * @param string $query
     * @param array $options
     * @return Collection
     */
    public function search(string $query, array $options = []): Collection;

    /**
     * Get media by mime type.
     *
     * @param string $mimeType
     * @param array $options
     * @return Collection
     */
    public function getByMimeType(string $mimeType, array $options = []): Collection;

    /**
     * Process media cleanup.
     *
     * @param int $days Older than X days
     * @return int Number of files cleaned
     */
    public function cleanup(int $days = 30): int;
}
