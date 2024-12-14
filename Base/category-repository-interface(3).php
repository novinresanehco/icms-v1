<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;

interface CategoryRepositoryInterface
{
    public function createCategory(array $data): ?int;
    
    public function updateCategory(int $categoryId, array $data): bool;
    
    public function deleteCategory(int $categoryId): bool;
    
    public function getCategory(int $categoryId): ?array;
    
    public function getCategoryBySlug(string $slug): ?array;
    
    public function getAllCategories(): Collection;
    
    public function getRootCategories(): Collection;
    
    public function getChildCategories(int $parentId): Collection;
    
    public function getCategoryHierarchy(): array;
}
