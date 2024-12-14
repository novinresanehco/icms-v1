<?php

namespace App\Core\Services\Contracts;

use App\Core\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface CategoryServiceInterface
{
    public function getCategory(int $id): ?Category;
    
    public function getCategoryBySlug(string $slug): ?Category;
    
    public function getAllCategories(): Collection;
    
    public function getAllCategoriesPaginated(int $perPage = 15): LengthAwarePaginator;
    
    public function getParentCategories(): Collection;
    
    public function getPopularCategories(int $limit = 10): Collection;
    
    public function getCategoryTree(): Collection;
    
    public function createCategory(array $data): Category;
    
    public function updateCategory(int $id, array $data): Category;
    
    public function deleteCategory(int $id): bool;
    
    public function restoreCategory(int $id): bool;
    
    public function reorderCategories(array $data): bool;
}
