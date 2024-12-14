<?php

namespace App\Core\Services\Contracts;

use App\Core\Models\Media;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface MediaServiceInterface
{
    public function getMedia(int $id): ?Media;
    
    public function getModelMedia(string $modelType, int $modelId): Collection;
    
    public function getAllMedia(int $perPage = 15): LengthAwarePaginator;
    
    public function getMediaByType(string $type, int $perPage = 15): LengthAwarePaginator;
    
    public function uploadMedia(array $data): Media;
    
    public function updateMedia(int $id, array $data): Media;
    
    public function deleteMedia(int $id): bool;
    
    public function attachMediaToModel(int $mediaId, string $modelType, int $modelId, array $data = []): bool;
    
    public function detachMediaFromModel(int $mediaId, string $modelType, int $modelId): bool;
}
