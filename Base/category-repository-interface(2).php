<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;

interface CategoryRepositoryInterface
{
    public function create(array $data): ?int;
    
    public function update(int $categoryId, array $data): bool;
    
    public function delete(int $categoryId): bool;
    
    public function get(int $categoryId): ?array;
    
    public function getBySlug(string $slug): ?array;
    
    public function getAll(): Collection;
    
    public function getAllNested(): Collection;
    
    public function getChildren(int $categoryId): Collection;
    
    public function moveNode(int $categoryId, ?int $parentId): bool;
    
    public function reorder(int $categoryId, int $position): bool;
}
