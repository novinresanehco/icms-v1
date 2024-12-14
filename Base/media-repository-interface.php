<?php

declare(strict_types=1);

namespace App\Repositories\Interfaces;

use App\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

interface MediaRepositoryInterface
{
    /**
     * Find media by ID
     *
     * @param int $id
     * @return Media|null
     */
    public function findById(int $id): ?Media;

    /**
     * Create new media record
     *
     * @param UploadedFile $file
     * @param array $data
     * @return Media
     */
    public function create(UploadedFile $file, array $data = []): Media;

    /**
     * Update media record
     *
     * @param int $id
     * @param array $data
     * @return bool
     */
    public function update(int $id, array $data): bool;

    /**
     * Delete media record and associated files
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool;

    /**
     * Get media by folder
     *
     * @param int|null $folderId
     * @return Collection
     */
    public function getByFolder(?int $folderId = null): Collection;

    /**
     * Find media by type (image, video, document, etc)
     *
     * @param string $type
     * @return Collection
     */
    public function findByType(string $type): Collection;
}