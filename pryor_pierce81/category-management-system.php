<?php

namespace App\Core\Category;

class CategoryManager implements CategoryManagerInterface 
{
    private SecurityManager $security;
    private Repository $repository;
    private CacheManager $cache;
    private ValidationService $validator;
    private EventDispatcher $events;
    
    public function create(array $data): CategoryResult 
    {
        DB::beginTransaction();
        
        try {
            $this->security->validateCriticalOperation([
                'action' => 'category.create',
                'data' => $data
            ]);

            $validated = $this->validator->validate($data, [
                'name' => 'required|string|max:255',
                'slug' => 'required|string|max:255|unique:categories',
                'description' => 'string',
                'parent_id' => 'nullable|integer|exists:categories,id',
                'meta' => 'array'
            ]);

            if (isset($validated['parent_id'])) {
                $this->validateHierarchy($validated['parent_id']);
            }

            $category = $this->repository->create([
                'name' => $validated['name'],
                'slug' => $validated['slug'],
                'description' => $validated['description'] ?? '',
                'parent_id' => $validated['parent_id'] ?? null,
                'meta' => $validated['meta'] ?? [],
                'path' => $this->generatePath($validated['parent_id'] ?? null),
                'level' => $this->calculateLevel($validated['parent_id'] ?? null),
                'created_at' => now()
            ]);

            $this->cache->tags(['categories'])->flush();
            $this->events->dispatch(new CategoryCreated($category));
            
            DB::commit();
            return new CategoryResult($category);

        } catch (\Exception $e) {
            DB::rollBack();
            throw new CategoryException('Failed to create category', 0, $e);
        }
    }

    public function update(int $id, array $data): CategoryResult 
    {
        DB::beginTransaction();
        
        try {
            $this->security->validateCriticalOperation([
                'action' => 'category.update',
                'category_id' => $id,
                'data' => $data
            ]);

            $category = $this->repository->findOrFail($id);
            
            $validated = $this->validator->validate($data, [
                'name' => 'string|max:255',
                'slug' => "string|max:255|unique:categories,slug,{$id}",
                'description' => 'string',
                'parent_id' => 'nullable|integer|exists:categories,id',
                'meta' => 'array'
            ]);

            if (isset($validated['parent_id'])) {
                $this->validateHierarchy($validated['parent_id'], $id);
            }

            if (isset($validated['parent_id']) && $validated['parent_id'] !== $category->parent_id) {
                $this->updateHierarchy($category, $validated['parent_id']);
            }

            $updated = $this->repository->update($id, [
                'name' => $validated['name'] ?? $category->name,
                'slug' => $validated['slug'] ?? $category->slug,
                'description' => $validated['description'] ?? $category->description,
                'parent_id' => $validated['parent_id'] ?? $category->parent_id,
                'meta' => array_merge($category->meta, $validated['meta'] ?? []),
                'updated_at' => now()
            ]);

            $this->cache->tags(['categories'])->flush();
            $this->events->dispatch(new CategoryUpdated($updated));
            
            DB::commit();
            return new CategoryResult($updated);

        } catch (\Exception $e) {
            DB::rollBack();
            throw new CategoryException('Failed to update category', 0, $e);
        }
    }

    public function delete(int $id): bool 
    {
        DB::beginTransaction();
        
        try {
            $this->security->validateCriticalOperation([
                'action' => 'category.delete',
                'category_id' => $id
            ]);

            $category = $this->repository->findOrFail($id);
            
            if ($this->hasChildren($id)) {
                throw new CategoryException('Cannot delete category with children');
            }

            if ($this->isInUse($id)) {
                throw new CategoryException('Cannot delete category in use');
            }

            $this->repository->delete($id);
            
            $this->cache->tags(['categories'])->flush();
            $this->events->dispatch(new CategoryDeleted($category));
            
            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getTree(): array 
    {
        return $this->cache->tags(['categories'])->remember(
            'category_tree',
            3600,
            fn() => $this->buildTree($this->repository->getAll())
        );
    }

    public function getBreadcrumb(int $id): array 
    {
        return $this->cache->tags(['categories'])->remember(
            "category_breadcrumb.{$id}",
            3600,
            fn() => $this->buildBreadcrumb($id)
        );
    }

    private function validateHierarchy(int $parentId, ?int $currentId = null): void 
    {
        if ($currentId && $parentId === $currentId) {
            throw new CategoryException('Category cannot be its own parent');
        }

        $parent = $this->repository->find($parentId);
        if (!$parent) {
            throw new CategoryException('Parent category not found');
        }

        if ($this->calculateLevel($parentId) >= config('cms.max_category_depth', 5)) {
            throw new CategoryException('Maximum category depth exceeded');
        }

        if ($currentId && $this->isDescendant($currentId, $parentId)) {
            throw new CategoryException('Cannot move category under its own descendant');
        }
    }

    private function updateHierarchy(Category $category, int $newParentId): void 
    {
        $oldPath = $category->path;
        $newPath = $this->generatePath($newParentId);
        
        // Update all descendants
        $descendants = $this->repository->findDescendants($category->id);
        foreach ($descendants as $descendant) {
            $descendant->path = str_replace($oldPath, $newPath, $descendant->path);
            $descendant->level = $this->calculateLevel($descendant->parent_id);
            $this->repository->update($descendant->id, [
                'path' => $descendant->path,
                'level' => $descendant->level
            ]);
        }
    }

    private function generatePath(?int $parentId): string 
    {
        if (!$parentId) {
            return '/';
        }

        $parent = $this->repository->find($parentId);
        return $parent->path . $parent->id . '/';
    }

    private function calculateLevel(?int $parentId): int 
    {
        if (!$parentId) {
            return 0;
        }

        $parent = $this->repository->find($parentId);
        return $parent->level + 1;
    }

    private function buildTree(array $categories, ?int $parentId = null): array 
    {
        $branch = [];
        
        foreach ($categories as $category) {
            if ($category->parent_id === $parentId) {
                $children = $this->buildTree($categories, $category->id);
                if ($children) {
                    $category->children = $children;
                }
                $branch[] = $category;
            }
        }
        
        return $branch;
    }

    private function buildBreadcrumb(int $id): array 
    {
        $breadcrumb = [];
        $category = $this->repository->find($id);
        
        while ($category) {
            array_unshift($breadcrumb, $category);
            $category = $category->parent_id ? $this->repository->find($category->parent_id) : null;
        }
        
        return $breadcrumb;
    }

    private function hasChildren(int $id): bool 
    {
        return $this->repository->countChildren($id) > 0;
    }

    private function isInUse(int $id): bool 
    {
        return $this->repository->countUsage($id) > 0;
    }

    private function isDescendant(int $categoryId, int $possibleDescendantId): bool 
    {
        $category = $this->repository->find($possibleDescendantId);
        return str_contains($category->path, "/{$categoryId}/");
    }
}
