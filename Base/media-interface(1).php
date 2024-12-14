<?php

namespace App\Core\Repositories\Contracts;

use App\Models\Media;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface MediaRepositoryInterface
{
    public function create(array $data): Media;
    
    public function findById(int $id): ?Media;
    
    public function findByIds(array $ids): Collection;
    
    public function paginate(int $perPage = 15): LengthAwarePaginator;
    
    public function delete(int $id): bool;
    
    public function findByType(string $mimeType, array $options = []): Collection;
    
    public function updateMeta(int $id, array $meta): bool;
}
