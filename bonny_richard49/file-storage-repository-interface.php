<?php

namespace App\Core\FileStorage\Repository;

use App\Core\FileStorage\Models\File;
use App\Core\FileStorage\DTO\FileData;
use App\Core\Shared\Repository\RepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;

interface FileStorageRepositoryInterface extends RepositoryInterface
{
    /**
     * Store uploaded file.
     *
     * @param UploadedFile $file
     * @param array $attributes
     * @return File
     */
    public function store(UploadedFile $file, array $attributes = []): File;

    /**
     * Store file from URL.
     *
     * @param string $url
     * @param array $attributes
     * @return File
     */
    public function storeFromUrl(string $url, array $attributes = []): File;

    /**
     * Get files by directory.
     *
     * @param string $directory
     * @return Collection
     */
    public function getByDirectory(string $directory): Collection;

    /**
     * Move file to directory.
     *
     * @param int $fileId
     * @param string $directory
     * @return File
     */
    public function moveToDirectory(int $fileId, string $directory): File;

    /**
     * Get files by mime type.
     *
     * @param string $mimeType
     * @return Collection
     */
    public function getByMimeType(string $mimeType): Collection;

    /**
     * Generate file URL.
     *
     * @param int $fileId
     * @param int $expiresIn Expiration time in seconds
     * @return string
     */
    public function getUrl(int $fileId, int $expiresIn = 3600): string;

    /**
     * Generate thumbnails for image.
     *
     * @param int $fileId
     * @param array $sizes
     * @return array
     */
    public function generateThumbnails(int $fileId, array $sizes): array;

    /**
     * Get file metadata.
     *
     * @param int $fileId
     * @return array
     */
    public function getMetadata(int $fileId): array;

    /**
     * Update file attributes.
     *
     * @param int $fileId
     * @param array $attributes
     * @return File
     */
    public function updateAttributes(int $fileId, array $attributes): File;

    /**
     * Get file usage information.
     *
     * @param int $fileId
     * @return array
     */
    public function getUsageInfo(int $fileId): array;

    /**
     * Search files.
     *
     * @param string $query
     * @param array $filters
     * @return Collection
     */
    public function search(string $query, array $filters = []): Collection;

    /**
     * Clean unused files.
     *
     * @param int $olderThanDays
     * @return int Number of files cleaned
     */
    public function cleanUnused(int $olderThanDays): int;

    /**
     * Duplicate file.
     *
     * @param int $fileId
     * @param array $attributes
     * @return File
     */
    public function duplicate(int $fileId, array $attributes = []): File;
}
