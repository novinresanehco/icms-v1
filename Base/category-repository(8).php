<?php

namespace App\Repositories;

use App\Repositories\Contracts\CategoryRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class CategoryRepository implements CategoryRepositoryInterface
{
    protected string $table = 'categories';

    public function createCategory(array $data): ?int
    {
        try {
            DB::beginTransaction();

            $parentId = $data['parent_id'] ?? null;
            if ($parentId) {
                $parent = $this->getCategory($parentId);
                if (!$parent) {
                    throw new \InvalidArgumentException('Parent category not found');
                }
            }

            $categoryId = DB::table($this->table)->insertGetId([
                'name' => $data['name'],
                'slug' => \Str::slug($data['name']),
                'description' => $data['description'] ?? null,
                'parent_id' => $parentId,
                'order' => $data['order'] ?? 0,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            $this->clearCategoryCache();
            DB::commit();

            return $categoryId;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to create category: ' . $e->getMessage());
            return null;
        }
    }

    public function updateCategory(int $categoryId, array $data): bool
    {
        try {
            if (isset($data['parent_id']) && $data['parent_id'] == $categoryId) {
                throw new \InvalidArgumentException('Category cannot be its own parent');
            }

            $updated = DB::table($this->table)
                ->where('id', $categoryId)
                ->update([
                    'name' => $data['name'],
                    'slug' => \Str::slug($data['name']),
                    'description' => $data['description'] ?? null,
                    'parent_id' => $data['parent_id'] ?? null,
                    'order' => $data['order'] ?? 0,
                    'updated_at' => now()
                ]) > 0;

            if ($updated) {
                $this->clearCategoryCache();
            }

            return $updated;
        } catch (\Exception $e) {
            \Log::error('Failed to update category: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteCategory(int $categoryId): bool
    {
        try {
            DB::beginTransaction();

            // Update children to parent's parent
            $category = $this->getCategory($categoryId);
            if ($category) {
                DB::table($this->table)
                    ->where('parent_id', $categoryId)
                    ->update(['parent_id' => $category['parent_id']]);
            }

            // Delete category
            $deleted = DB::table($this->table)
                ->where('id', $categoryId)
                ->delete() > 0;

            if ($deleted) {
                $this->clearCategoryCache();
            }

            DB::commit();
            return $deleted;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to delete category: ' . $e->getMessage());
            return false;
        }
    }

    public function getCategory(int $categoryId): ?array
    {
        try {
            $category = DB::table($this->table)
                ->where('id', $categoryId)
                ->first();

            return $category ? (array) $category : null;
        } catch (\Exception $e) {
            \Log::error('Failed to get category: ' . $e->getMessage());
            return null;
        }
    }

    public function getCategoryBySlug(string $slug): ?array
    {
        try {
            $category = DB::table($this->table)
                ->where('slug', $slug)
                ->first();

            return $category ? (array) $category : null;
        } catch (\Exception $e) {
            \Log::error('Failed to get category by slug: ' . $e->getMessage());
            return null;
        }
    }

    public function getAllCategories(): Collection
    {
        return Cache::remember('all_categories', 3600, function() {
            return collect(DB::table($this->table)
                ->orderBy('parent_id')
                ->orderBy('order')
                ->orderBy('name')
                ->get());
        });
    }

    public function getRootCategories(): Collection
    {
        return $this->getAllCategories()
            ->whereNull('parent_id');
    }

    public function getChildCategories(int $parentId): Collection
    {
        return $this->getAllCategories()
            ->where('parent_id', $parentId);
    }

    public function getCategoryHierarchy(): array
    {
        $categories = $this->getAllCategories();
        return $this->buildHierarchy($categories);
    }

    protected function buildHierarchy(Collection $categories, $parentId = null): array
    {
        $hierarchy = [];
        
        foreach ($categories->where('parent_id', $parentId) as $category) {
            $children = $this->buildHierarchy($categories, $category['id']);
            $hierarchy[] = array_merge(
                (array) $category,
                ['children' => $children]
            );
        }

        return $hierarchy;
    }

    protected function clearCategoryCache(): void
    {
        Cache::forget('all_categories');
        Cache::tags(['categories'])->flush();
    }
}
