<?php

namespace App\Core\Repositories;

use App\Core\Contracts\Repositories\CategoryRepositoryInterface;
use App\Core\Models\Category;
use App\Core\Exceptions\CategoryRepositoryException;
use Illuminate\Database\Eloquent\{Model, Collection, Builder};
use Illuminate\Support\Facades\{Cache, DB};
use Illuminate\Database\Eloquent\ModelNotFoundException;

class CategoryRepository implements CategoryRepositoryInterface
{
    protected Category $model;
    protected const CACHE_PREFIX = 'category:';
    protected const CACHE_TTL = 3600;

    public function __construct(Category $model)
    {
        $this->model = $model;
    }

    public function create(array $data): Model
    {
        try {
            DB::beginTransaction();

            $category = $this->model->create([
                'name' => $data['name'],
                'slug' => $data['slug'] ?? str($data['name'])->slug(),
                'description' => $data['description'] ?? null,
                'parent_id' => $data['parent_id'] ?? null,
                'order' => $data['order'] ?? 0,
                'is_active' => $data['is_active'] ?? true,
                'meta_title' => $data['meta_title'] ?? $data['name'],
                'meta_description' => $data['meta_description'] ?? null,
                'meta_keywords' => $data['meta_keywords'] ?? null,
            ]);

            DB::commit();
            $this->clearCache();

            return $category;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new CategoryRepositoryException("Failed to create category: {$e->getMessage()}", 0, $e);
        }
    }

    public function update(int $id, array $data): Model
    {
        try {
            DB::beginTransaction();

            $category = $this->findById($id);
            
            $category->update([
                'name' => $data['name'] ?? $category->name,
                'slug' => $data['slug'] ?? $category->slug,
                'description' => $data['description'] ?? $category->description,
                'parent_id' => $data['parent_id'] ?? $category->parent_id,
                'order' => $data['order'] ?? $category->order,
                'is_active' => $data['is_active'] ?? $category->is_active,
                'meta_title' => $data['meta_title'] ?? $category->meta_title,
                'meta_description' => $data['meta_description'] ?? $category->meta_description,
                'meta_keywords' => $data['meta_keywords'] ?? $category->meta_keywords,
            ]);

            DB::commit();
            $this->clearCache();

            return $category;
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new CategoryRepositoryException("Failed to update category: {$e->getMessage()}", 0, $e);
        }
    }

    public function findById(int $id): Model
    {
        return Cache::remember(
            self::CACHE_PREFIX . $id,
            self::CACHE_TTL,
            fn () => $this->model->with('parent', 'children')->findOrFail($id)
        );
    }

    public function findBySlug(string $slug): Model
    {
        return Cache::remember(
            self::CACHE_PREFIX . "slug:{$slug}",
            self::CACHE_TTL,
            fn () => $this->model->with('parent', 'children')->where('slug', $slug)->firstOrFail()
        );
    }

    public function getActive(bool $withChildren = true): Collection
    {
        $cacheKey = self::CACHE_PREFIX . 'active' . ($withChildren ? ':with-children' : '');

        return Cache::remember(
            $cacheKey,
            self::CACHE_TTL,
            function () use ($withChildren) {
                $query = $this->model->newQuery()
                    ->where('is_active', true)
                    ->orderBy('order');

                if ($withChildren) {
                    $query->with('children');
                }

                return $query->get();
            }
        );
    }

    public function getRootCategories(): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . 'root',
            self::CACHE_TTL,
            fn () => $this->model->whereNull('parent_id')
                ->with('children')
                ->orderBy('order')
                ->get()
        );
    }

    public function delete(int $id): bool
    {
        try {
            DB::beginTransaction();

            $category = $this->findById($id);
            
            if ($category->children()->exists()) {
                throw new CategoryRepositoryException('Cannot delete category with children');
            }

            $deleted = $category->delete();

            DB::commit();
            $this->clearCache();

            return $deleted;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new CategoryRepositoryException("Failed to delete category: {$e->getMessage()}", 0, $e);
        }
    }

    public function moveToParent(int $categoryId, ?int $parentId): Model
    {
        try {
            DB::beginTransaction();

            $category = $this->findById($categoryId);
            
            if ($parentId === $category->id) {
                throw new CategoryRepositoryException('Category cannot be its own parent');
            }

            $category->parent_id = $parentId;
            $category->save();

            DB::commit();
            $this->clearCache();

            return $category;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new CategoryRepositoryException("Failed to move category: {$e->getMessage()}", 0, $e);
        }
    }

    public function reorder(array $order): bool
    {
        try {
            DB::beginTransaction();

            foreach ($order as $position => $categoryId) {
                $this->model->where('id', $categoryId)->update(['order' => $position]);
            }

            DB::commit();
            $this->clearCache();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new CategoryRepositoryException("Failed to reorder categories: {$e->getMessage()}", 0, $e);
        }
    }

    protected function clearCache(): void
    {
        Cache::tags(['categories'])->flush();
    }
}
