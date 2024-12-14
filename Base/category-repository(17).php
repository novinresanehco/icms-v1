<?php

namespace App\Core\Repositories;

use App\Models\Category;
use App\Core\Services\Cache\CacheService;
use Illuminate\Support\Collection;

class CategoryRepository extends AdvancedRepository
{
    protected $model = Category::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function getTree(): Collection
    {
        return $this->executeQuery(function() {
            return $this->cache->remember('categories.tree', function() {
                return $this->model
                    ->whereNull('parent_id')
                    ->with('children')
                    ->orderBy('order')
                    ->get();
            });
        });
    }

    public function findBySlug(string $slug): ?Category
    {
        return $this->executeQuery(function() use ($slug) {
            return $this->cache->remember("category.slug.{$slug}", function() use ($slug) {
                return $this->model
                    ->where('slug', $slug)
                    ->with(['parent', 'children'])
                    ->first();
            });
        });
    }

    public function reorder(array $order): void
    {
        $this->executeTransaction(function() use ($order) {
            foreach ($order as $id => $position) {
                $this->model->find($id)->update(['order' => $position]);
            }
            $this->cache->forget('categories.tree');
        });
    }

    public function moveToParent(Category $category, ?int $parentId): void
    {
        $this->executeTransaction(function() use ($category, $parentId) {
            $category->update(['parent_id' => $parentId]);
            $this->cache->forget('categories.tree');
        });
    }
}
