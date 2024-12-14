<?php

namespace App\Core\Category\Repository;

use App\Core\Category\Models\Category;
use App\Core\Shared\Repository\RepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

interface CategoryRepositoryInterface extends RepositoryInterface
{
    /**
     * Find category by slug.
     *
     * @param string $slug
     * @return Category|null
     */
    public function findBySlug(string $slug): ?Category;

    /**
     * Get root categories.
     *
     * @return Collection
     */
    public function getRootCategories(): Collection;

    /**
     * Get category tree structure.
     *
     * @param int|null $parentId
     * @return Collection
     */
    public function getCategoryTree(?int $parentId = null): Collection;

    /**
     * Get category ancestors.
     *
     * @param int $categoryId
     * @return Collection
     */
    public function getAncestors(int $categoryId): Collection;

    /**
     * Get category descendants.
     *
     * @param int $categoryId
     * @return Collection
     */
    public function getDescendants(int $categoryId): Collection;

    /**
     * Get category siblings.
     *
     * @param int $categoryId
     * @return Collection
     */
    public function getSiblings(int $categoryId): Collection;

    /**
     * Move category under new parent.
     *
     * @param int $categoryId
     * @param int|null $newParentId
     * @return Category
     */
    public function moveCategory(int $categoryId, ?int $newParentId): Category;

    /**
     * Get categories by order.
     *
     * @return Collection
     */
    public function getByOrder(): Collection;

    /**
     * Update category order.
     *
     * @param array $order Category ID => Order pairs
     * @return bool
     */
    public function updateOrder(array $order): bool;

    /**
     * Get categories with content count.
     *
     * @return Collection
     */
    public function getWithContentCount(): Collection;

    /**
     * Get categories with active content only.
     *
     * @return Collection
     */
    public function getActiveWithContent(): Collection;
}
