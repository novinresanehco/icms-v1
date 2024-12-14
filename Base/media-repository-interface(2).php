<?php

namespace App\Repositories\Contracts;

use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface MediaRepositoryInterface
{
    public function store(UploadedFile $file, array $metadata = []): ?int;
    
    public function update(int $mediaId, array $data): bool;
    
    public function delete(int $mediaId): bool;
    
    public function get(int $mediaId): ?array;
    
    public function getAllPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator;
    
    public function getByType(string $type): Collection;
    
    public function getByCollection(string $collection): Collection;
    
    public function attachToModel(int $mediaId, string $modelType, int $modelId): bool;
    
    public function detachFromModel(int $mediaId, string $modelType, int $modelId): bool;
    
    public function optimize(int $mediaId, array $options = []): bool;
    
    public function generateThumbnail(int $mediaId, array $dimensions): ?string;
    
    public function updateMetadata(int $mediaId, array $metadata): bool;
}
