<?php

namespace App\Core\Repositories\Contracts;

use App\Core\Models\Media;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface MediaRepositoryInterface
{
    public function findById(int $id): ?Media;
    
    public function getForModel(string $modelType, int $modelId): Collection;
    
    public function getAllPaginated(int $perPage = 15): LengthAwarePaginator;
    
    public function getByType(string $type, int $perPage = 15): LengthAwarePaginator;
    
    public function store(array $data): Media;
    
    public function update(int $id, array $data): Media;
    
    public function delete(int $id): bool;
    
    public function attachToModel(int $mediaId, string $modelType, int $modelId, array $data = []): bool;
    
    public function detachFromModel(int $mediaId, string $modelType, int $modelId): bool;
}
