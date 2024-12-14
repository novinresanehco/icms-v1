<?php

namespace App\Core\Repositories\Contracts;

use App\Models\Category;
use Illuminate\Support\Collection;

interface CategoryRepositoryInterface extends RepositoryInterface
{
    /**
     * Get category by slug
     *
     * @param string $slug
     * @return Category|null
     */
    public function findBySlug(string $slug): ?Category;

    /**
     * Get category tree structure
     *
     * @return Collection
     */
    public function getTree(): Collection;

    /**
     * Get categories with content count
     *
     * @return Collection
     */
    public function getWithContentCount(): Collection;

    /**
     * Get child categories
     *
     * @param int $parentId
     * @return Collection
     */
    public function getChildren(int $parentId): Collection;

    /**
     * Move category to new parent
     *
     * @param int $categoryId
     * @param int|null $newParentId
     * @return Category
     */
    public function moveCategory(int $categoryId, ?int $newParentId): Category;

    /**
     * Reorder categories
     *
     * @param array $order Category IDs in desired order
     * @return bool
     */
    public function reorder(array $order): bool;
}
