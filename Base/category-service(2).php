<?php

namespace App\Core\Services;

use App\Core\Models\Category;
use App\Core\Contracts\Repositories\CategoryRepositoryInterface;
use App\Core\Events\Categories\CategoryCreated;
use App\Core\Events\Categories\CategoryUpdated;
use App\Core\Events\Categories\CategoryDeleted;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Cache;

class CategoryService
{
    protected CategoryRepositoryInterface $repository;

    public function __construct(CategoryRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    public function getAllCategories(array $filters = []): Collection
    {
        return Cache::tags(['categories'])->remember(
            'categories.all.' . md5(serialize($filters)),
            config('cache.categories.ttl'),
            fn() => $this->repository->getAllCategories()
        );
    }

    public function getCategoryHierarchy(): Collection
    {
        return Cache::tags(['categories'])->remember(
            'categories.hierarchy',
            config('cache.categories.ttl'),
            function () {
                return $this->repository->getAllCategories()
                    ->where('parent_id', null)
                    ->map(function ($category) {
                        return $this->buildHierarchyTree($category);
                    });
            }
        );
    }

    public function createCategory(array $data): Category
    {
        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);
        
        $category = $this->repository->create($data);
        
        Event::dispatch(new CategoryCreated($category));
        
        Cache::tags(['categories'])->flush();
        
        return $category;
    }

    public function updateCategory(int $id, array $data): Category
    {
        if (isset($data['name']) && !isset($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $category = $this->repository->update($id, $data);
        
        Event::dispatch(new CategoryUpdated($category));
        
        Cache::tags(['categories'])->flush();
        
        return $category;
    }

    public function deleteCategory(int $id): bool
    {
        $category = $this->repository->findById($id);
        
        $deleted = $this->repository->delete($id);
        
        if ($deleted) {
            Event::dispatch(new CategoryDeleted($category));
            Cache::tags(['categories'])->flush();
        }
        
        return $deleted;
    }

    protected function buildHierarchyTree(Category $category): array
    {
        $node = $category->toArray();
        
        if ($category->children->isNotEmpty()) {
            $node['children'] = $category->children
                ->map(fn($child) => $this->buildHierarchyTree($child))
                ->toArray();
        }
        
        return $node;
    }
}
