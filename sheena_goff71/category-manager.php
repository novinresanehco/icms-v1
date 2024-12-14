<?php

namespace App\Core\Services;

use Illuminate\Support\Facades\{DB, Cache};
use App\Core\Models\{Category, Categorizable};
use App\Core\Services\{SecurityService, ValidationService};
use App\Core\Exceptions\{CategoryException, ValidationException};

class CategoryManager
{
    private SecurityService $security;
    private ValidationService $validator;
    private array $config;

    private const MAX_DEPTH = 5;
    private const CACHE_TTL = 3600;
    private const MAX_CHILDREN = 100;

    public function __construct(
        SecurityService $security,
        ValidationService $validator,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->config = $config;
    }

    public function createCategory(array $data): Category
    {
        return $this->security->executeSecure(function() use ($data) {
            DB::beginTransaction();
            try {
                $this->validateCategoryData($data);
                
                if (isset($data['parent_id'])) {
                    $this->validateParentCategory($data['parent_id']);
                }

                $category = Category::create([
                    'name' => $data['name'],
                    'slug' => $this->generateSlug($data['name']),
                    'description' => $data['description'] ?? null,
                    'parent_id' => $data['parent_id'] ?? null,
                    'order' => $data['order'] ?? 0,
                    'meta' => $data['meta'] ?? [],
                    'status' => $data['status'] ?? 'active'
                ]);

                $this->rebuildTree();
                $this->clearCategoryCache();
                
                DB::commit();
                return $category;

            } catch (\Exception $e) {
                DB::rollBack();
                throw new CategoryException('Failed to create category: ' . $e->getMessage());
            }
        }, 'category.create');
    }

    public function updateCategory(int $id, array $data): Category
    {
        return $this->security->executeSecure(function() use ($id, $data) {
            DB::beginTransaction();
            try {
                $category = $this->findCategory($id);
                $this->validateCategoryData($data, $category);
                
                if (isset($data['parent_id'])) {
                    $this->validateParentUpdate($category, $data['parent_id']);
                }

                $category->update([
                    'name' => $data['name'] ?? $category->name,
                    'slug' => isset($data['name']) ? $this->generateSlug($data['name']) : $category->slug,
                    'description' => $data['description'] ?? $category->description,
                    'parent_id' => $data['parent_id'] ?? $category->parent_id,
                    'order' => $data['order'] ?? $category->order,
                    'meta' => array_merge($category->meta, $data['meta'] ?? []),
                    'status' => $data['status'] ?? $category->status
                ]);

                $this->rebuildTree();
                $this->clearCategoryCache();
                
                DB::commit();
                return $category;

            } catch (\Exception $e) {
                DB::rollBack();
                throw new CategoryException('Failed to update category: ' . $e->getMessage());
            }
        }, 'category.update');
    }

    public function deleteCategory(int $id): bool
    {
        return $this->security->executeSecure(function() use ($id) {
            DB::beginTransaction();
            try {
                $category = $this->findCategory($id);
                
                if ($category->children()->exists()) {
                    throw new CategoryException('Cannot delete category with children');
                }

                if ($category->items()->exists()) {
                    throw new CategoryException('Cannot delete category with items');
                }

                $category->delete();
                
                $this->rebuildTree();
                $this->clearCategoryCache();
                
                DB::commit();
                return true;

            } catch (\Exception $e) {
                DB::rollBack();
                throw new CategoryException('Failed to delete category: ' . $e->getMessage());
            }
        }, 'category.delete');
    }

    public function moveCategory(int $id, ?int $parentId, int $order = 0): Category
    {
        return $this->security->executeSecure(function() use ($id, $parentId, $order) {
            DB::beginTransaction();
            try {
                $category = $this->findCategory($id);
                
                if ($parentId) {
                    $this->validateParentUpdate($category, $parentId);
                }

                $category->update([
                    'parent_id' => $parentId,
                    'order' => $order
                ]);

                $this->reorderSiblings($parentId);
                $this->rebuildTree();
                $this->clearCategoryCache();
                
                DB::commit();
                return $category;

            } catch (\Exception $e) {
                DB::rollBack();
                throw new CategoryException('Failed to move category: ' . $e->getMessage());
            }
        }, 'category.move');
    }

    public function getTree(?int $parentId = null): array
    {
        return Cache::remember(
            "category_tree.{$parentId}",
            self::CACHE_TTL,
            function() use ($parentId) {
                return Category::with('children')
                    ->where('parent_id', $parentId)
                    ->orderBy('order')
                    ->get()
                    ->map(fn($category) => $this->buildTreeNode($category))
                    ->toArray();
            }
        );
    }

    public function getBreadcrumb(int $categoryId): array
    {
        return Cache::remember(
            "category_breadcrumb.{$categoryId}",
            self::CACHE_TTL,
            function() use ($categoryId) {
                $category = $this->findCategory($categoryId);
                return $category->ancestors()
                    ->orderBy('depth')
                    ->get()
                    ->push($category)
                    ->map(fn($cat) => [
                        'id' => $cat->id,
                        'name' => $cat->name,
                        'slug' => $cat->slug
                    ])
                    ->toArray();
            }
        );
    }

    protected function validateCategoryData(array $data, ?Category $category = null): void
    {
        $rules = [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'parent_id' => 'nullable|integer|exists:categories,id',
            'order' => 'nullable|integer|min:0',
            'meta' => 'nullable|array',
            'status' => 'nullable|in:active,inactive'
        ];

        $this->validator->validate($data, $rules);

        if (isset($data['name'])) {
            $query = Category::where('name', $data['name']);
            
            if ($category) {
                $query->where('id', '!=', $category->id);
            }
            
            if ($query->exists()) {
                throw new ValidationException('Category name already exists');
            }
        }
    }

    protected function validateParentCategory(int $parentId): void
    {
        $parent = $this->findCategory($parentId);
        
        if ($parent->depth >= self::MAX_DEPTH - 1) {
            throw new ValidationException('Maximum category depth exceeded');
        }

        if ($parent->children()->count() >= self::MAX_CHILDREN) {
            throw new ValidationException('Maximum number of children exceeded');
        }
    }

    protected function validateParentUpdate(Category $category, int $parentId): void
    {
        if ($category->id === $parentId) {
            throw new ValidationException('Category cannot be its own parent');
        }

        $parent = $this->findCategory($parentId);
        
        if ($parent->isDescendantOf($category)) {
            throw new ValidationException('Cannot move category under its descendant');
        }

        $this->validateParentCategory($parentId);
    }

    protected function generateSlug(string $name): string
    {
        $slug = str_slug($name);
        $count = 1;

        while (Category::where('slug', $slug)->exists()) {
            $slug = str_slug($name) . '-' . $count++;
        }

        return $slug;
    }

    protected function findCategory(int $id): Category
    {
        $category = Category::find($id);
        
        if (!$category) {
            throw new CategoryException("Category not found: {$id}");
        }
        
        return $category;
    }

    protected function buildTreeNode(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'depth' => $category->depth,
            'order' => $category->order,
            'status' => $category->status,
            'children' => $category->children
                ->map(fn($child) => $this->buildTreeNode($child))
                ->toArray()
        ];
    }

    protected function reorderSiblings(?int $parentId): void
    {
        $categories = Category::where('parent_id', $parentId)
            ->orderBy('order')
            ->get();

        foreach ($categories->values() as $index => $category) {
            if ($category->order !== $index) {
                $category->update(['order' => $index]);
            }
        }
    }

    protected function rebuildTree(): void
    {
        Category::query()->update(['depth' => 0]);
        
        $rootCategories = Category::whereNull('parent_id')->get();
        
        foreach ($rootCategories as $root) {
            $this->updateDepth($root);
        }
    }

    protected function updateDepth(Category $category, int $depth = 0): void
    {
        $category->update(['depth' => $depth]);
        
        foreach ($category->children as $child) {
            $this->updateDepth($child, $depth + 1);
        }
    }

    protected function clearCategoryCache(): void
    {
        Cache::tags(['categories'])->flush();
    }
}
