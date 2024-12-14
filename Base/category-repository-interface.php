<?php

declare(strict_types=1);

namespace App\Repositories\Interfaces;

use App\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

interface CategoryRepositoryInterface
{
    /**
     * Find category by ID
     *
     * @param int $id
     * @param array $with
     * @return Category|null
     */
    public function findById(int $id, array $with = []): ?Category;

    /**
     * Find category by slug
     *
     * @param string $slug
     * @param array $with
     * @return Category|null
     */
    public function findBySlug(string $slug, array $with = []): ?Category;

    /**
     * Get all categories
     *
     * @param array $with
     * @return EloquentCollection
     */
    public function getAll(array $with = []): EloquentCollection;

    /**
     * Get categories tree
     *
     * @return Collection
     */
    public function getTree(): Collection;

    /**
     * Create new category
     *
     * @param array $data
     * @return Category
     */
    public function create(array $data): Category;

    /**
     * Update category
     *
     * @param int $id
     * @param array $data
     * @return bool
     */
    public function update(int $id, array $data): bool;

    /**
     * Delete category
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool;

    /**
     * Get categories by parent ID
     *
     * @param int|null $parentId
     * @return Collection
     */
    public function getByParentId(?int $parentId = null): Collection;
}