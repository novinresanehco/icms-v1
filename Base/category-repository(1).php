<?php

namespace App\Repositories;

use App\Models\Category;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CategoryRepository extends BaseRepository implements CategoryRepositoryInterface
{
    protected array $searchableFields = ['name', 'slug', 'description'];
    protected array $filterableFields = ['status', 'parent_id'];

    /**
     * Get all active categories with their hierarchy
     *
     * @return Collection
     */
    public function getActiveHierarchy(): Collection
    {
        return Cache::tags(['categories'])->remember('categories.hierarchy', 3600, function() {
            return $this->model->newQuery()
                ->where('status', 'active')
                ->whereNull('parent_id')
                ->with(['children' => function($query) {
                    $query->where('status', 'active');
                }])
                ->orderBy('sort_order')
                ->get();
        });
    }

    /**
     * Get category by slug with active status
     *
     * @param string $slug
     * @return Category|null
     */
    public function getActiveBySlug(string $slug): ?Category
    {
        return Cache::tags(['categories'])->remember("category.{$slug}", 3600, function() use ($slug) {
            return $this->model->newQuery()
                ->where('slug', $slug)
                ->where('status', 'active')
                ->first();
        });
    }

    /**
     * Update category sort order
     *
     * @param array $sortData Array of category IDs and their positions
     * @return bool
     */
    public function updateSortOrder(array $sortData): bool
    {
        try {
            DB::beginTransaction();

            foreach ($sortData as $position => $categoryId) {
                $this->update($categoryId, ['sort_order' => $position]);
            }

            DB::commit();
            Cache::tags(['categories'])->flush();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error updating category sort order: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get categories with content count
     *
     * @return Collection
     */
    public function getWithContentCount(): Collection
    {
        return Cache::tags(['categories'])->remember('categories.content_count', 300, function() {
            return $this->model->newQuery()
                ->withCount(['content' => function($query) {
                    $query->where('status', 'published');
                }])
                ->where('status', 'active')
                ->orderBy('sort_order')
                ->get();
        });
    }

    /**
     * Move category to new parent
     *
     * @param int $categoryId
     * @param int|null $newParentId
     * @return bool
     */
    public function moveCategory(int $categoryId, ?int $newParentId): bool
    {
        try {
            if ($categoryId === $newParentId) {
                return false;
            }

            $category = $this->find($categoryId);
            if (!$category) {
                return false;
            }

            // Prevent moving to own child
            if ($newParentId && $this->isChildCategory($categoryId, $newParentId)) {
                return false;
            }

            $this->update($categoryId, ['parent_id' => $newParentId]);
            Cache::tags(['categories'])->flush();

            return true;
        } catch (\Exception $e) {
            \Log::error('Error moving category: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if target category is a child of source category
     *
     * @param int $sourceId
     * @param int $targetId
     * @return bool
     */
    protected function isChildCategory(int $sourceId, int $targetId): bool
    {
        $category = $this->find($targetId);
        
        while ($category && $category->parent_id) {
            if ($category->parent_id === $sourceId) {
                return true;
            }
            $category = $this->find($category->parent_id);
        }

        return false;
    }

    /**
     * Get category path from root
     *
     * @param int $categoryId
     * @return Collection
     */
    public function getCategoryPath(int $categoryId): Collection
    {
        $path = collect();
        $category = $this->find($categoryId);

        while ($category) {
            $path->prepend($category);
            $category = $category->parent_id ? $this->find($category->parent_id) : null;
        }

        return $path;
    }

    /**
     * Update category status and propagate to children
     *
     * @param int $categoryId
     * @param string $status
     * @return bool
     */
    public function updateCategoryStatus(int $categoryId, string $status): bool
    {
        try {
            DB::beginTransaction();

            $this->update($categoryId, ['status' => $status]);

            // Update all children recursively
            $this->model->newQuery()
                ->where('parent_id', $categoryId)
                ->update(['status' => $status]);

            DB::commit();
            Cache::tags(['categories'])->flush();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error updating category status: ' . $e->getMessage());
            return false;
        }
    }
}
