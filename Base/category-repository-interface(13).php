<?php

namespace App\Core\Repositories\Contracts;

use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;

interface CategoryRepositoryInterface extends RepositoryInterface
{
    public function getTree(): Collection;
    
    public function findBySlug(string $slug): ?Category;
    
    public function updateOrder(array $order): bool;
    
    public function getChildren(int $parentId): Collection;
    
    public function getPath(int $categoryId): Collection;
}
