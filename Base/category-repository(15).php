<?php

namespace App\Core\Repositories;

use App\Core\Models\Category;
use App\Core\Repositories\Contracts\CategoryRepositoryInterface;
use App\Core\Exceptions\CategoryException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\{Cache, DB, Log};

class CategoryRepository implements CategoryRepositoryInterface
{
    protected Category $model;
    protected const CACHE_TTL = 3600;

    public function __construct(Category $model)
    {
        $this->model = $model;
    }

    public function find(int $id): ?Category
    {
        return Cache::remember("categories.{$id}", self::CACHE_TTL, function () use ($id) {
            return $this->model->with(['parent', 'children', 'meta'])->find($id);
        });
    }

    public function findBySlug(string $slug): ?Category
    {
        return Cache::remember("categories.slug.{$slug}", self::CACHE_TTL, function () use ($slug) {
            return $this->model->with(['parent', 'children', 'meta'])
                             ->where('slug', $slug)
                             ->first();
        });
    }

    public function all(array $filters = []): Collection
    {
        $query = $this->model->with(['parent', 'children', 'meta']);
        return $this->applyFilters($query, $filters)->get();
    }

    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = $this->model->with(['parent', 'children', 'meta']);
        return $this->applyFilters($query, $filters)->paginate($perPage);
    }

    public function create(array $data): Category
    {
        try {
            DB::beginTransaction();

            $category = $this->model->create($data);

            if (isset($data['meta'])) {
                $category->meta()->createMany($data['meta']);
            }

            if (isset($data['order'])) {
                $category->updateOrder($data['order']);
            }

            DB::commit();
            $this->clearCache();

            return $category->fresh(['parent', 'children', 'meta']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Category creation failed:', ['error' => $e->getMessage(), 'data' => $data]);
            throw new CategoryException('Failed to create category: ' . $e->getMessage());
        }
    }

    public function update(Category $category, array $data): bool
    {
        try {
            DB::beginTransaction();

            $category->update($data);

            if (isset($data['meta'])) {
                $category->meta()->delete();
                $category->meta()->createMany($data['meta']);
            }

            DB::commit();
            $this->clearCache($category->id);

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Category update failed:', ['id' => $category->id, 'error' => $e->getMessage()]);
            throw new CategoryException('Failed to update category: ' . $e->getMessage());
        }
    }

    public function delete(Category $category): bool
    {
        try {
            DB::beginTransaction();

            // Move children to parent if exists
            if ($category->parent_id) {
                $category->children()->update(['parent_id' => $category->parent_id]);
            } else {
                // Make children root categories
                $category->children()->update(['parent_id' => null]);
            }

            $category->meta()->delete();
            $category->delete();

            DB::commit();
            $this->clearCache($category->id);

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Category deletion failed:', ['id' => $category->id, 'error' => $e->getMessage()]);
            throw new CategoryException('Failed to delete category: ' . $e->getMessage());
        }
    }

    public function getTree(): Collection
    {
        return Cache::remember('categories.tree', self::CACHE_TTL, function () {
            return $this->model->whereNull('parent_id')
                             ->with(['children.children', 'meta'])
                             ->orderBy('order')
                             ->get();
        });
    }

    public function getChildren(int $parentId): Collection
    {
        return Cache::remember("categories.children.{$parentId}", self::CACHE_TTL, function () use ($parentId) {
            return $this->model->where('parent_id', $parentId)
                             ->with('meta')
                             ->orderBy('order')
                             ->get();
        });
    }

    public function reorder(array $order): void
    {
        try {
            DB::beginTransaction();

            foreach ($order as $position => $categoryId) {
                $this->model->where('id', $categoryId)->update(['order' => $position]);
            }

            DB::commit();
            $this->clearCache();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Category reorder failed:', ['error' => $e->getMessage(), 'order' => $order]);
            throw new CategoryException('Failed to reorder categories: ' . $e->getMessage());
        }
    }

    public function moveToParent(Category $category, ?int $parentId): bool
    {
        try {
            if ($parentId && $category->id === $parentId) {
                throw new CategoryException('Category cannot be its own parent');
            }

            DB::beginTransaction();

            $category->parent_id = $parentId;
            $category->save();

            DB::commit();
            $this->clearCache($category->id);

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Category move failed:', ['id' => $category->id, 'parent_id' => $parentId, 'error' => $e->getMessage()]);
            throw new CategoryException('Failed to move category: ' . $e->getMessage());
        }
    }

    protected function applyFilters($query, array $filters): object
    {
        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('name', 'like', "%{$filters['search']}%")
                  ->orWhere('description', 'like', "%{$filters['search']}%");
            });
        }

        if (isset($filters['parent_id'])) {
            $query->where('parent_id', $filters['parent_id']);
        }

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $sort = $filters['sort'] ?? 'order';
        $direction = $filters['direction'] ?? 'asc';
        $query->orderBy($sort, $direction);

        return $query;
    }

    protected function clearCache(?int $categoryId = null): void
    {
        if ($categoryId) {
            Cache::forget("categories.{$categoryId}");
            $category = $this->model->find($categoryId);
            if ($category) {
                Cache::forget("categories.slug.{$category->slug}");
                Cache::forget("categories.children.{$category->parent_id}");
            }
        }
        
        Cache::forget('categories.tree');
        Cache::tags(['categories'])->flush();
    }
}
