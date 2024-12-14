<?php

namespace App\Repositories\Contracts;

use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;

interface CategoryRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Get all active categories with their hierarchy
     *
     * @return Collection
     */
    public function getActiveHierarchy(): Collection;

    /**
     * Get category by slug with active status
     *
     * @param string $slug
     * @return Category|null
     */
    public function getActiveBySlug(string $slug): ?Category;

    /**
     * Update category sort order
     *
     * @param array $sortData
     * @return bool
     */
    public function updateSortOrder(array $sortData): bool;

    /**
     * Get categories with content count
     *
     * @return Collection
     */
    public function getWithContentCount(): Collection;

    /**
     * Move category to new parent
     *
     * @param int $categoryId
     * @param int|null $newParentId
     * @return bool
     */
    public function moveCategory(int $categoryId, ?int $newParentId): bool;

    /**
     * Get category path from root
     *
     * @param int $categoryId
     * @return Collection
     */
    public function getCategoryPath(int $categoryId): Collection;

    /**
     * Update category status and propagate to children
     *
     * @param int $categoryId
     * @param string $status
     * @return bool
     */
    public function updateCategoryStatus(int $categoryId, string $status): bool;
}
