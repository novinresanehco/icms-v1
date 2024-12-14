<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\CategoryRepositoryInterface;
use App\Models\Category;
use App\Exceptions\CategoryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class CategoryRepository extends BaseRepository implements CategoryRepositoryInterface
{
    /**
     * Cache TTL in seconds
     */
    protected const CACHE_TTL = 3600;

    /**
     * @param Category $model
     */
    public function __construct(Category $model)
    {
        parent::__construct($model);
    }

    /**
     * {@inheritDoc}
     */
    public function findBySlug(string $slug): ?Category
    {
        return Cache::remember("category:slug:{$slug}", self::CACHE_TTL, function () use ($slug) {
            return $this->model->with(['parent', 'children'])
                ->where('slug', $slug)
                ->first();
        });
    }

    /**
     * {@inheritDoc}
     */
    public function getTree(): Collection
    {
        return Cache::remember('categories:tree', self::CACHE_TTL, function () {
            return $this->model->whereNull('parent_id')
                ->with(['children' => function ($query) {
                    $query->orderBy('order');
                }])
                ->orderBy('order')
                ->get();
        });
    }

    /**
     * {@inheritDoc}
     */
    public function getWithContentCount(): Collection
    {
        return Cache::remember('categories:content_count', self::CACHE_TTL, function () {
            return $this->model->withCount('content')
                ->orderBy('order')
                ->get();
        });
    }

    /**
     * {@inheritDoc}
     */
    public function getChildren(int $parentId): Collection
    {
        return Cache::remember("category:children:{$parentId}", self::CACHE_TTL, function () use ($parentId) {
            return $this->model->where('parent_id', $parentId)
                ->orderBy('order')
                ->get();
        });
    }

    /**
     * {@inheritDoc}
     */
    public function moveCategory(int $categoryId, ?int $newParentId): Category
    {
        try {
            DB::beginTransaction();

            $category = $this->findById($categoryId);
            if (!$category) {
                throw new CategoryException("Category not found with ID: {$categoryId}");
            }

            // Validate new parent exists if provided
            if ($newParentId !== null) {
                $newParent = $this->findById($newParentId);
                if (!$newParent) {
                    throw new CategoryException("New parent category not found with ID: {$newParentId}");
                }

                // Prevent circular references
                if ($this->wouldCreateCircularReference($category, $newParentId)) {
                    throw new CategoryException("Moving category would create circular reference");
                }
            }

            $category->parent_id = $newParentId;
            $category->save();

            DB::commit();

            $this->clearCategoryCache();

            return $category->fresh(['parent', 'children']);
        } catch (QueryException $e) {
            DB::rollBack();
            throw new CategoryException("Error moving category: {$e->getMessage()}");
        }
    }

    /**
     * {@inheritDoc}
     */
    public function reorder(array $order): bool
    {
        try {
            DB::beginTransaction();

            foreach ($order as $index => $categoryId) {
                $this->model->where('id', $categoryId)
                    ->update(['order' => $index + 1]);
            }

            DB::commit();

            $this->clearCategoryCache();

            return true;
        } catch (QueryException $e) {
            DB::rollBack();
            throw new CategoryException("Error reordering categories: {$e->getMessage()}");
        }
    }

    /**
     * Check if moving category would create circular reference
     *
     * @param Category $category
     * @param int $newParentId
     * @return bool
     */
    protected function wouldCreateCircularReference(Category $category, int $newParentId): bool
    {
        if ($category->id === $newParentId) {
            return true;
        }

        $parent = $this->findById($newParentId);
        while ($parent) {
            if ($parent->parent_id === $category->id) {
                return true;
            }
            $parent = $parent->parent;
        }

        return false;
    }

    /**
     * Clear category cache
     *
     * @return void
     */
    protected function clearCategoryCache(): void
    {
        Cache::tags(['categories'])->flush();
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $payload): Category
    {
        try {
            DB::beginTransaction();

            if (!isset($payload['order'])) {
                $payload['order'] = $this->getNextOrder($payload['parent_id'] ?? null);
            }

            $category = parent::create($payload);

            DB::commit();

            $this->clearCategoryCache();

            return $category;
        } catch (QueryException $e) {
            DB::rollBack();
            throw new CategoryException("Error creating category: {$e->getMessage()}");
        }
    }

    /**
     * Get next order number for category
     *
     * @param int|null $parentId
     * @return int
     */
    protected function getNextOrder(?int $parentId): int
    {
        return $this->model->where('parent_id', $parentId)
            ->max('order') + 1;
    }
}
