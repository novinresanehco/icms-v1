<?php

namespace App\Repositories;

use App\Models\Category;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class CategoryRepository extends BaseRepository implements CategoryRepositoryInterface
{
    protected array $searchableFields = ['name', 'description', 'slug'];
    protected array $filterableFields = ['status', 'parent_id'];

    /**
     * Get category tree structure with caching
     *
     * @param bool $activeOnly Get only active categories
     * @return Collection
     */
    public function getTree(bool $activeOnly = true): Collection
    {
        $cacheKey = 'categories.tree.' . ($activeOnly ? 'active' : 'all');

        return Cache::tags(['categories'])->remember($cacheKey, 3600, function() use ($activeOnly) {
            $query = $this->model->newQuery();

            if ($activeOnly) {
                $query->where('status', 'active');
            }

            return $query->whereNull('parent_id')
                ->with(['children' => function($q) use ($activeOnly) {
                    if ($activeOnly) {
                        $q->where('status', 'active');
                    }
                }])
                ->orderBy('sort_order')
                ->get();
        });
    }

    /**
     * Get category by slug with caching
     *
     * @param string $slug Category slug
     * @param array $relations Relations to eager load
     * @return Category|null
     */
    public function findBySlug(string $slug, array $relations = []): ?Category
    {
        $cacheKey = 'categories.slug.' . $slug . '.' . md5(serialize($relations));

        return Cache::tags(['categories'])->remember($cacheKey, 3600, function() use ($slug, $relations) {
            return $this->model
                ->where('slug', $slug)
                ->with($relations)
                ->first();
        });
    }

    /**
     * Get categories with content count
     *
     * @param bool $activeOnly Get only active categories
     * @return Collection
     */
    public function getWithContentCount(bool $activeOnly = true): Collection
    {
        $query = $this->model->newQuery();

        if ($activeOnly) {
            $query->where('status', 'active');
        }

        return $query->withCount('content')
            ->orderBy('content_count', 'desc')
            ->get();
    }

    /**
     * Update category sort order
     *
     * @param array $order Array of category IDs in desired order
     * @return bool
     */
    public function updateSortOrder(array $order): bool
    {
        try {
            foreach ($order as $index => $id) {
                $this->update($id, ['sort_order' => $index]);
            }

            // Clear category caches
            Cache::tags(['categories'])->flush();

            return true;
        } catch (\Exception $e) {
            \Log::error('Error updating category sort order: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get category breadcrumb trail
     *
     * @param Category $category Category model
     * @return Collection
     */
    public function getBreadcrumb(Category $category): Collection
    {
        $breadcrumb = collect([$category]);
        $current = $category;

        while ($current->parent_id) {
            $current = $this->find($current->parent_id);
            $breadcrumb->prepend($current);
        }

        return $breadcrumb;
    }

    /**
     * Get subcategories recursively
     *
     * @param int $categoryId Parent category ID
     * @param bool $activeOnly Get only active categories
     * @return Collection
     */
    public function getSubcategories(int $categoryId, bool $activeOnly = true): Collection
    {
        $query = $this->model->newQuery();

        if ($activeOnly) {
            $query->where('status', 'active');
        }

        return $query->where('parent_id', $categoryId)
            ->with(['children' => function($q) use ($activeOnly) {
                if ($activeOnly) {
                    $q->where('status', 'active');
                }
            }])
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Override create method to handle caching
     *
     * @param array $data Category data
     * @return Category
     */
    public function create(array $data): Category
    {
        $category = parent::create($data);
        Cache::tags(['categories'])->flush();
        return $category;
    }

    /**
     * Override update method to handle caching
     *
     * @param int $id Category ID
     * @param array $data Updated data
     * @return Category
     */
    public function update(int $id, array $data): Category
    {
        $category = parent::update($id, $data);
        Cache::tags(['categories'])->flush();
        return $category;
    }

    /**
     * Override delete method to handle caching
     *
     * @param int $id Category ID
     * @return bool
     */
    public function delete(int $id): bool
    {
        $result = parent::delete($id);
        if ($result) {
            Cache::tags(['categories'])->flush();
        }
        return $result;
    }
}
