<?php

namespace App\Repositories\Contracts;

use App\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

interface MediaRepositoryInterface
{
    public function findByPath(string $path): ?Media;
    public function storeFile(UploadedFile $file, array $metadata = []): Media;
    public function updateMetadata(int $id, array $metadata): bool;
    public function getByMimeType(string $mimeType): Collection;
    public function getByType(string $type): Collection;
    public function getMediaVersions(int $id): Collection;
    public function duplicateMedia(int $id): Media;
    public function moveMedia(int $id, string $newPath): bool;
    public function addToCollection(int $mediaId, int $collectionId): bool;
    public function removeFromCollection(int $mediaId, int $collectionId): bool;
    public function optimizeMedia(int $id): bool;
}
