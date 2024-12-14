<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use App\Repositories\Interfaces\CategoryRepositoryInterface;

class CategoryRepository implements CategoryRepositoryInterface
{
    private const CACHE_PREFIX = 'category:';
    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly Category $model
    ) {}

    public function findById(int $id, array $with = []): ?Category
    {
        return Cache::remember(
            self::CACHE_PREFIX . $id,
            self::CACHE_TTL,
            fn () => $this->model->with($with)->find($id)
        );
    }

    public function findBySlug(string $slug, array $with = []): ?Category
    {
        return Cache::remember(
            self::CACHE_PREFIX . "slug:{$slug}",
            self::CACHE_TTL,
            fn () => $this->model->with($with)->where('slug', $slug)->first()
        );
    }

    public function getAll(array $with = []): EloquentCollection
    {
        return Cache::remember(
            self::CACHE_PREFIX . 'all',
            self::CACHE_TTL,
            fn () => $this->model->with($with)->orderBy('name')->get()
        );
    }

    public function getTree(): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . 'tree',
            self::CACHE_TTL,
            function () {
                $categories = $this->model->get();
                return $this->buildTree($categories);
            }
        );
    }

    public function create(array $data): Category
    {
        $category = $this->model->create([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'parent_id' => $data['parent_id'] ?? null,
            'order' => $data['order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);

        $this->clearCache();

        return $category;
    }

    public function update(int $id, array $data): bool
    {
        $category = $this->findById($id);
        
        if (!$category) {
            return false;
        }

        $updated = $category->update([
            'name' => $data['name'] ?? $category->name,
            'slug' => $data['slug'] ?? $category->slug,
            'description' => $data['description'] ?? $category->description,
            'parent_id' => $data['parent_id'] ?? $category->parent_id,
            'order' => $data['order'] ?? $category->order,
            'is_active' => $data['is_active'] ?? $category->is_active,
        ]);

        if ($updated) {
            $this->clearCache();
        }

        return $updated;
    }

    public function delete(int $id): bool
    {
        $category = $this->findById($id);
        
        if (!$category) {
            return false;
        }

        // Move children categories to parent
        if ($category->parent_id) {
            $this->model->where('parent_id', $id)
                ->update(['parent_id' => $category->parent_id]);
        } else {
            $this->model->where('parent_id', $id)
                ->update(['parent_id' => null]);
        }

        $deleted = $category->delete();

        if ($deleted) {
            $this->clearCache();
        }

        return $deleted;
    }

    public function getByParentId(?int $parentId = null): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . "parent:{$parentId}",
            self::CACHE_TTL,
            fn () => $this->model->where('parent_id', $parentId)
                ->orderBy('order')
                ->get()
        );
    }

    protected function buildTree(Collection $categories, ?int $parentId = null): Collection
    {
        $branch = new Collection();

        foreach ($categories as $category) {
            if ($category->parent_id === $parentId) {
                $children = $this->buildTree($categories, $category->id);
                
                if ($children->count() > 0) {
                    $category->children = $children;
                }
                
                $branch->push($category);
            }
        }

        return $branch;
    }

    protected function clearCache(): void
    {
        $keys = ['all', 'tree'];
        foreach ($keys as $key) {
            Cache::forget(self::CACHE_PREFIX . $key);
        }
    }
}