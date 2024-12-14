<?php

namespace App\Core\Repositories\Contracts;

use App\Core\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface CategoryRepositoryInterface
{
    public function findById(int $id): ?Category;
    
    public function findBySlug(string $slug): ?Category;
    
    public function getAll(): Collection;
    
    public function getAllPaginated(int $perPage = 15): LengthAwarePaginator;
    
    public function getParentCategories(): Collection;
    
    public function getPopular(int $limit = 10): Collection;
    
    public function getWithChildren(): Collection;
    
    public function store(array $data): Category;
    
    public function update(int $id, array $data): Category;
    
    public function delete(int $id): bool;
    
    public function restore(int $id): bool;
    
    public function reorder(array $data): bool;
}
