<?php

namespace App\Repositories\Contracts;

use App\Models\Category;
use Illuminate\Support\Collection;

interface CategoryRepositoryInterface
{
    public function findBySlug(string $slug): ?Category;
    public function getTree(): Collection;
    public function getChildren(int $parentId): Collection;
    public function getParents(int $categoryId): Collection;
    public function createWithParent(array $data, ?int $parentId = null): Category;
    public function updateWithParent(int $id, array $data, ?int $parentId = null): bool;
    public function moveCategory(int $categoryId, ?int $newParentId): bool;
    public function getDescendants(int $categoryId): Collection;
    public function getAncestors(int $categoryId): Collection;
    public function getSiblings(int $categoryId): Collection;
    public function reorder(array $order): bool;
}
