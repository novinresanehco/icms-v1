<?php

namespace App\Repositories;

use App\Models\Category;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CategoryRepository implements CategoryRepositoryInterface
{
    protected Category $model;
    protected int $cacheTTL = 3600; // 1 hour

    public function __construct(Category $model)
    {
        $this->model = $model;
    }

    public function create(array $data): ?int
    {
        try {
            DB::beginTransaction();

            $category = $this->model->create([
                'name' => $data['name'],
                'slug' => $data['slug'] ?? str($data['name'])->slug(),
                'description' => $data['description'] ?? null,
                'parent_id' => $data['parent_id'] ?? null,
                'order' => $data['order'] ?? 0,
                'metadata' => $data['metadata'] ?? [],
                'status' => $data['status'] ?? 'active',
                'featured_image' => $data['featured_image'] ?? null,
            ]);

            $this->clearCategoryCache();
            DB::commit();

            return $category->id;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create category: ' . $e->getMessage());
            return null;
        }
    }

    public function update(int $id, array $data): bool
    {
        try {
            DB::beginTransaction();

            $category = $this->model->findOrFail($id);
            
            $updateData = [
                'name' => $data['name'] ?? $category->name,
                'slug' => $data['slug'] ?? ($data['name'] ? str($data['name'])->slug() : $category->slug),
                'description' => $data['description'] ?? $category->description,
                'parent_id' => $data['parent_id'] ?? $category->parent_id,
                'order' => $data['order'] ?? $category->order,
                'metadata' => array_merge($category->metadata ?? [], $data['metadata'] ?? []),
                'status' => $data['status'] ?? $category->status,
                'featured_image' => $data['featured_image'] ?? $category->featured_image,
            ];

            // Prevent circular parent reference
            if ($updateData['parent_id'] == $id) {
                throw new \Exception('Category cannot be its own parent');
            }

            // Check for circular reference in hierarchy
            if ($updateData['parent_id'] && $this->wouldCreateCycle($id, $updateData['parent_id'])) {
                throw new \Exception('This operation would create a circular reference in category hierarchy');
            }

            $category->update($updateData);

            $this->clearCategoryCache();
            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update category: ' . $e->getMessage());
            return false;
        }
    }

    public function delete(int $id): bool
    {
        try {
            DB::beginTransaction();

            $category = $this->model->findOrFail($id);
            
            // Handle child categories
            if ($category->children()->exists()) {
                // Option 1: Prevent deletion if has children
                // throw new \Exception('Cannot delete category with child categories');
                
                // Option 2: Move children to parent
                $category->children()->update(['parent_id' => $category->parent_id]);
            }

            // Handle associated content
            if ($category->contents()->exists()) {
                $category->contents()->detach();
            }

            $category->delete();

            $this->clearCategoryCache();
            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete category: ' . $e->getMessage());
            return false;
        }
    }

    public function get(int $id): ?array
    {
        try {
            return Cache::remember(
                "category.{$id}",
                $this->cacheTTL,
                fn() => $this->model->with(['parent', 'children'])
                    ->findOrFail($id)
                    ->toArray()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get category: ' . $e->getMessage());
            return null;
        }
    }

    public function getAllPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        try {
            $query = $this->model->query()
                ->with(['parent', 'children']);

            if (!empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (!empty($filters['parent_id'])) {
                $query->where('parent_id', $filters['parent_id']);
            }

            if (!empty($filters['search'])) {
                $query->where(function ($q) use ($filters) {
                    $q->where('name', 'LIKE', "%{$filters['search']}%")
                        ->orWhere('description', 'LIKE', "%{$filters['search']}%");
                });
            }

            $orderBy = $filters['order_by'] ?? 'order';
            $orderDir = $filters['order_dir'] ?? 'asc';
            $query->orderBy($orderBy, $orderDir);

            return $query->paginate($perPage);
        } catch (\Exception $e) {
            Log::error('Failed to get paginated categories: ' . $e->getMessage());
            return new LengthAwarePaginator([], 0, $perPage);
        }
    }

    public function getTree(): Collection
    {
        try {
            return Cache::remember(
                'category.tree',
                $this->cacheTTL,
                fn() => $this->model->whereNull('parent_id')
                    ->with('children.children')
                    ->orderBy('order')
                    ->get()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get category tree: ' . $e->getMessage());
            return new Collection();
        }
    }

    public function getBySlug(string $slug): ?array
    {
        try {
            return Cache::remember(
                "category.slug.{$slug}",
                $this->cacheTTL,
                fn() => $this->model->with(['parent', 'children'])
                    ->where('slug', $slug)
                    ->firstOrFail()
                    ->toArray()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get category by slug: ' . $e->getMessage());
            return null;
        }
    }

    public function reorder(array $order): bool
    {
        try {
            DB::beginTransaction();

            foreach ($order as $position => $categoryId) {
                $this->model->where('id', $categoryId)
                    ->update(['order' => $position]);
            }

            $this->clearCategoryCache();
            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to reorder categories: ' . $e->getMessage());
            return false;
        }
    }

    protected function wouldCreateCycle(int $categoryId, int $newParentId): bool
    {
        $parent = $this->model->find($newParentId);
        while ($parent) {
            if ($parent->id === $categoryId) {
                return true;
            }
            $parent = $parent->parent;
        }
        return false;
    }

    protected function clearCategoryCache(): void
    {
        Cache::tags(['categories'])->flush();
    }
}
