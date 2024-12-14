<?php

namespace App\Repositories;

use App\Models\Category;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CategoryRepository implements CategoryRepositoryInterface
{
    protected Category $model;
    
    public function __construct(Category $model)
    {
        $this->model = $model;
    }

    public function create(array $data): ?int
    {
        try {
            DB::beginTransaction();
            
            $category = $this->model->create($data);
            
            if (isset($data['parent_id'])) {
                $parent = $this->model->findOrFail($data['parent_id']);
                $category->appendToNode($parent)->save();
            }
            
            DB::commit();
            $this->clearCategoryCache();
            
            return $category->id;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create category: ' . $e->getMessage());
            return null;
        }
    }

    public function update(int $categoryId, array $data): bool
    {
        try {
            DB::beginTransaction();
            
            $category = $this->model->findOrFail($categoryId);
            
            if (isset($data['parent_id']) && $data['parent_id'] !== $category->parent_id) {
                if ($data['parent_id'] === null) {
                    $category->makeRoot()->save();
                } else {
                    $parent = $this->model->findOrFail($data['parent_id']);
                    $category->appendToNode($parent)->save();
                }
                unset($data['parent_id']);
            }
            
            $category->update($data);
            
            DB::commit();
            $this->clearCategoryCache();
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update category: ' . $e->getMessage());
            return false;
        }
    }

    public function delete(int $categoryId): bool
    {
        try {
            DB::beginTransaction();
            
            $category = $this->model->findOrFail($categoryId);
            $category->delete();
            
            DB::commit();
            $this->clearCategoryCache();
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete category: ' . $e->getMessage());
            return false;
        }
    }

    public function get(int $categoryId): ?array
    {
        try {
            $category = $this->model->with('children')->find($categoryId);
            return $category ? $category->toArray() : null;
        } catch (\Exception $e) {
            Log::error('Failed to get category: ' . $e->getMessage());
            return null;
        }
    }

    public function getBySlug(string $slug): ?array
    {
        return Cache::remember("category.{$slug}", 3600, function() use ($slug) {
            try {
                $category = $this->model->where('slug', $slug)->first();
                return $category ? $category->toArray() : null;
            } catch (\Exception $e) {
                Log::error('Failed to get category by slug: ' . $e->getMessage());
                return null;
            }
        });
    }

    public function getAll(): Collection
    {
        try {
            return $this->model->all();
        } catch (\Exception $e) {
            Log::error('Failed to get all categories: ' . $e->getMessage());
            return collect();
        }
    }

    public function getAllNested(): Collection
    {
        return Cache::remember('categories.nested', 3600, function() {
            try {
                return $this->model->defaultOrder()->get()->toTree();
            } catch (\Exception $e) {
                Log::error('Failed to get nested categories: ' . $e->getMessage());
                return collect();
            }
        });
    }

    public function getChildren(int $categoryId): Collection
    {
        try {
            $category = $this->model->findOrFail($categoryId);
            return $category->children;
        } catch (\Exception $e) {
            Log::error('Failed to get category children: ' . $e->getMessage());
            return collect();
        }
    }

    public function moveNode(int $categoryId, ?int $parentId): bool
    {
        try {
            DB::beginTransaction();
            
            $category = $this->model->findOrFail($categoryId);
            
            if ($parentId === null) {
                $category->makeRoot()->save();
            } else {
                $parent = $this->model->findOrFail($parentId);
                $category->appendToNode($parent)->save();
            }
            
            DB::commit();
            $this->clearCategoryCache();
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to move category: ' . $e->getMessage());
            return false;
        }
    }

    public function reorder(int $categoryId, int $position): bool
    {
        try {
            DB::beginTransaction();
            
            $category = $this->model->findOrFail($categoryId);
            $category->siblings()->where('_lft', '>=', $position)->increment('_lft', 2);
            $category->_lft = $position;
            $category->_rgt = $position + 1;
            $category->save();
            
            DB::commit();
            $this->clearCategoryCache();
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to reorder category: ' . $e->getMessage());
            return false;
        }
    }

    protected function clearCategoryCache(): void
    {
        Cache::tags(['categories'])->flush();
    }
}
