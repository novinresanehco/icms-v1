<?php

namespace App\Core\Repositories\Contracts;

use App\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface CategoryRepositoryInterface
{
    public function find(int $id): ?Category;
    
    public function findBySlug(string $slug): ?Category;
    
    public function all(): Collection;
    
    public function paginate(int $perPage = 15): LengthAwarePaginator;
    
    public function create(array $data): Category;
    
    public function update(int $id, array $data): Category;
    
    public function delete(int $id): bool;
    
    public function getTree(): Collection;
    
    public function getChildren(int $parentId): Collection;
    
    public function getParents(int $categoryId): Collection;
    
    public function reorder(array $order): bool;
    
    public function moveToParent(int $categoryId, ?int $parentId): bool;
}
