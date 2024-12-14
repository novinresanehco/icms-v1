<?php

namespace App\Core\Services\Contracts;

use App\Core\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

interface MediaServiceInterface
{
    public function upload(UploadedFile $file, ?string $type = null): Media;
    public function getById(int $id): ?Media;
    public function deleteById(int $id): bool;
    public function getAllByType(string $type): Collection;
    public function updateMetadata(int $id, array $metadata): ?Media;
}
