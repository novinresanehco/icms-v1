<?php

namespace App\Core\Contracts\Repositories;

use App\Core\Models\Media;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;

interface MediaRepositoryInterface
{
    public function getPaginated(array $filters = [], int $perPage = 20): LengthAwarePaginator;
    
    public function findById(int $id): Media;
    
    public function store(UploadedFile $file, array $data = []): Media;
    
    public function update(int $id, array $data): Media;
    
    public function delete(int $id): bool;
    
    public function getByFolder(string $folder): Collection;
    
    public function getByType(string $type): Collection;
}
