<?php

namespace App\Core\Services;

use App\Core\Models\Category;
use App\Core\Repositories\Contracts\CategoryRepositoryInterface;
use App\Core\Events\{CategoryCreated, CategoryUpdated, CategoryDeleted};
use App\Core\Exceptions\CategoryException;
use Illuminate\Support\Facades\{DB, Log, Cache};
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class CategoryService
{
    protected CategoryRepositoryInterface $repository;

    public function __construct(CategoryRepositoryInterface $repository) 
    {
        $this->repository = $repository;
    }

    public function create(array $data): Category
    {
        try {
            DB::beginTransaction();

            $category = $this->repository->create($this->prepareData($data));
            
            if (!empty($data['meta'])) {
                $category->meta()->createMany($data['meta']);
            }

            event(new CategoryCreated($category));
            
            DB::commit();
            
            return $category;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Category creation failed: ' . $e->getMessage());
            throw new CategoryException('Failed to create category: ' . $e->getMessage());
        }
    }

    public function update(int $id, array $data): Category
    {
        try {
            DB::beginTransaction();

            $category = $this->repository->findById($id);
            
            if (!$category) {
                throw new CategoryException("Category not found with ID: {$id}");
            }

            $this->repository->update($category, $this->prepareData($data));

            if (isset($data['meta'])) {
                $category->meta()->delete();
                $category->meta()->createMany($data['meta']);
            }

            event(new CategoryUpdated($category));
            
            DB::commit();
            
            return $category->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Category update failed: ' . $e->getMessage());
            throw new CategoryException('Failed to update category: ' . $e->getMessage());
        }
    }

    public function delete(int $id): bool
    {
        try {
            DB::beginTransaction();

            $category = $this->repository->findById($id);
            
            if (!$category) {
                throw new CategoryException("Category not found with ID: {$id}");
            }

            if ($this->repository->delete($id)) {
                event(new CategoryDeleted($category));
                DB::commit();
                return true;
            }

            throw new CategoryException('Failed to delete category');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Category deletion failed: ' . $e->getMessage());
            throw new CategoryException('Failed to delete category: ' . $e->getMessage());
        }
    }

    public function getTree(): Collection
    {
        return Cache::remember('category_tree', 3600, function() {
            return $this->repository->getTree();
        });
    }

    public function reorder(array $order): bool
    {
        try {
            DB::beginTransaction();
            
            $result = $this->repository->reorder($order);
            
            DB::commit();
            Cache::tags(['categories'])->flush();
            
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Category reordering failed: ' . $e->getMessage());
            throw new CategoryException('Failed to reorder categories: ' . $e->getMessage());
        }
    }

    public function findById(int $id): ?Category
    {
        return $this->repository->findById($id);
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->paginate($perPage);
    }

    protected function prepareData(array $data): array
    {
        if (!empty($data['slug'])) {
            $data['slug'] = $this->generateUniqueSlug($data['slug'], $data['id'] ?? null);
        }

        return array_filter($data, function ($value) {
            return $value !== null && $value !== '';
        });
    }

    protected function generateUniqueSlug(string $slug, ?int $excludeId = null): string
    {
        $originalSlug = $slug;
        $count = 1;

        while ($this->slugExists($slug, $excludeId)) {
            $slug = $originalSlug . '-' . $count++;
        }

        return $slug;
    }

    protected function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $query = Category::where('slug', $slug);
        
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }
}
