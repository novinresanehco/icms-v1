<?php

namespace App\Repositories;

use App\Models\Category;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class CategoryRepository extends BaseRepository implements CategoryRepositoryInterface
{
    protected array $searchableFields = ['name', 'description', 'slug'];
    protected array $filterableFields = ['parent_id', 'status'];

    /**
     * Get category hierarchy
     *
     * @return Collection
     */
    public function getHierarchy(): Collection
    {
        return Cache::tags(['categories'])->remember('category_hierarchy', 3600, function () {
            return $this->model
                ->whereNull('parent_id')
                ->with('children')
                ->orderBy('order')
                ->get();
        });
    }

    /**
     * Get category by slug with content count
     *
     * @param string $slug
     * @return Category|null
     */
    public function findBySlugWithContentCount(string $slug): ?Category
    {
        return $this->model
            ->where('slug', $slug)
            ->withCount('content')
            ->first();
    }

    /**
     * Update category order
     *
     * @param array $order
     * @return bool
     */
    public function updateOrder(array $order): bool
    {
        try {
            foreach ($order as $id => $position) {
                $this->model->where('id', $id)->update(['order' => $position]);
            }
            Cache::tags(['categories'])->flush();
            return true;
        } catch (\Exception $e) {
            \Log::error('Error updating category order: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get categories with content counts
     *
     * @return Collection
     */
    public function getAllWithContentCount(): Collection
    {
        return $this->model
            ->withCount('content')
            ->orderBy('name')
            ->get();
    }
}
