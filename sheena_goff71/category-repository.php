<?php

namespace App\Core\Category\Repositories;

use App\Core\Category\Models\Category;
use App\Core\Repository\BaseRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class CategoryRepository extends BaseRepository
{
    public function model(): string
    {
        return Category::class;
    }

    public function create(array $data): Category
    {
        return Category::create($data);
    }

    public function update(Category $category, array $data): Category
    {
        $category->update($data);
        return $category->fresh();
    }

    public function delete(Category $category): bool
    {
        return $category->delete();
    }

    public function forceDelete(Category $category): bool
    {
        return $category->forceDelete();
    }

    public function getTree(?int $parentId = null): Collection
    {
        return Category::where('parent_id', $parentId)
                      ->orderBy('sort_order')
                      ->with('children')
                      ->get();
    }

    public function getTreeWithFilters(array $filters = []): Collection
    {
        $query = Category::query();

        if (isset($filters['parent_id'])) {
            $query->where('parent_id', $filters['parent_id']);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (!empty($filters['search'])) {
            $query->where(function($q) use ($filters) {
                $q->where('name', 'like', "%{$filters['search']}%")
                  ->orWhere('description', 'like', "%{$filters['search']}%");
            });
        }

        return $query->orderBy('sort_order')
                    ->with('children')
                    ->get();
    }

    public function findWithRelations(int $id): Category
    {
        return Category::with(['parent', 'children', 'contents'])
                      ->findOrFail($id);
    }

    public function findByPath(string $path): ?Category
    {
        return Category::where('path', $path)->first();
    }

    public function getAncestors(Category $category): Collection
    {
        $ancestors = collect();
        $current = $category->parent;

        while ($current) {
            $ancestors->push($current);
            $current = $current->parent;
        }

        return $ancestors->reverse();
    }

    public function getDescendants(Category $category): Collection
    {
        return Category::where('path', 'like', $category->path . '/%')
                      ->orderBy('path')
                      ->get();
    }

    public function getSiblings(Category $category): Collection
    {
        return Category::where('parent_id', $category->parent_id)
                      ->where('id', '!=', $category->id)
                      ->orderBy('sort_order')
                      ->get();
    }

    public function moveNode(Category $category, ?int $parentId, int $position = 0): void
    {
        $category->parent_id = $parentId;
        $category->sort_order = $position;
        $category->save();

        $this->reorderSiblings($category);
    }

    protected function reorderSiblings(Category $category): void
    {
        $siblings = $this->getSiblings($category);
        $position = 0;

        foreach ($siblings as $sibling) {
            if ($position === $category->sort_order) {
                $position++;
            }
            
            if ($sibling->sort_order !== $position) {
                $sibling->sort_order = $position;
                $sibling->save();
            }
            
            $position++;
        }
    }

    public function getContentCount(Category $category, bool $includeChildren = true): int
    {
        if (!$includeChildren) {
            return $category->contents()->count();
        }

        $descendantIds = $this->getDescendants($category)->pluck('id');
        $descendantIds->push($category->id);

        return Content::whereHas('categories', function($query) use ($descendantIds) {
            $query->whereIn('categories.id', $descendantIds);
        })->count();
    }

    public function getCacheKey(string $key): string
    {
        return "categories:{$key}";
    }

    public function getCachedTree(?int $parentId = null): Collection
    {
        $cacheKey = $this->getCacheKey("tree:{$parentId}");

        return Cache::tags(['categories'])->remember(
            $cacheKey,
            now()->addHours(24),
            fn() => $this->getTree($parentId)
        );
    }

    public function clearCache(): void
    {
        Cache::tags(['categories'])->flush();
    }
}
