<?php

namespace App\Core\Repositories\Contracts;

use App\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

interface MediaRepositoryInterface extends RepositoryInterface
{
    /**
     * Store uploaded file
     *
     * @param UploadedFile $file
     * @param array $metadata
     * @return Media
     */
    public function storeFile(UploadedFile $file, array $metadata = []): Media;

    /**
     * Store media from URL
     *
     * @param string $url
     * @param array $metadata
     * @return Media
     */
    public function storeFromUrl(string $url, array $metadata = []): Media;

    /**
     * Get media by type
     *
     * @param string $type
     * @return Collection
     */
    public function getByType(string $type): Collection;

    /**
     * Get media usage
     *
     * @param int $mediaId
     * @return Collection
     */
    public function getUsage(int $mediaId): Collection;

    /**
     * Update media metadata
     *
     * @param int $mediaId
     * @param array $metadata
     * @return Media
     */
    public function updateMetadata(int $mediaId, array $metadata): Media;

    /**
     * Move media to folder
     *
     * @param int $mediaId
     * @param string $folder
     * @return Media
     */
    public function moveToFolder(int $mediaId, string $folder): Media;

    /**
     * Generate thumbnails
     *
     * @param int $mediaId
     * @param array $sizes
     * @return Media
     */
    public function generateThumbnails(int $mediaId, array $sizes): Media;

    /**
     * Get media by hash
     *
     * @param string $hash
     * @return Media|null
     */
    public function findByHash(string $hash): ?Media;

    /**
     * Check if media exists
     *
     * @param string $hash
     * @return bool
     */
    public function exists(string $hash): bool;
}
