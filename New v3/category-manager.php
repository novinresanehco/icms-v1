<?php

namespace App\Core\Content;

class CategoryManager implements CategoryManagerInterface
{
    private SecurityManager $security;
    private DatabaseManager $database;
    private CacheManager $cache;
    private AuditService $audit;
    private ValidationService $validator;
    private MetricsCollector $metrics;

    public function __construct(
        SecurityManager $security,
        DatabaseManager $database,
        CacheManager $cache,
        AuditService $audit,
        ValidationService $validator,
        MetricsCollector $metrics
    ) {
        $this->security = $security;
        $this->database = $database;
        $this->cache = $cache;
        $this->audit = $audit;
        $this->validator = $validator;
        $this->metrics = $metrics;
    }

    public function create(CategoryRequest $request): Category
    {
        try {
            DB::beginTransaction();

            $this->validateRequest($request);
            $this->security->validateAccess($request->getUser(), 'category.create');

            $category = $this->processCategory($request);
            $this->processHierarchy($category, $request);
            $this->processAttributes($category, $request);

            $this->database->save($category);
            $this->cache->invalidateCategoryCache($category);
            
            $this->audit->logCategoryCreation($category, $request->getUser());
            
            DB::commit();
            
            return $category;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, 'create', $request);
            throw $e;
        }
    }

    public function update(string $id, CategoryRequest $request): Category
    {
        try {
            DB::beginTransaction();

            $category = $this->findCategory($id);
            $this->validateUpdateRequest($category, $request);
            $this->security->validateAccess($request->getUser(), 'category.update');

            $this->updateCategory($category, $request);
            $this->updateHierarchy($category, $request);
            $this->updateAttributes($category, $request);

            $this->database->save($category);
            $this->cache->invalidateCategoryCache($category);
            
            $this->audit->logCategoryUpdate($category, $request->getUser());
            
            DB::commit();
            
            return $category;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, 'update', $request);
            throw $e;
        }
    }

    public function delete(string $id, User $user): void
    {
        try {
            DB::beginTransaction();

            $category = $this->findCategory($id);
            $this->validateDeletion($category);
            $this->security->validateAccess($user, 'category.delete');

            $this->handleDependencies($category);
            $this->deleteCategory($category);
            
            $this->cache->invalidateCategoryCache($category);
            $this->audit->logCategoryDeletion($category, $user);
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, 'delete', $id);
            throw $e;
        }
    }

    public function getTree(): CategoryTree
    {
        return $this->cache->remember('category_tree', function() {
            return $this->buildCategoryTree();
        });
    }

    public function find(string $id): Category
    {
        return $this->cache->remember("category:{$id}", function() use ($id) {
            $category = $this->database->findCategory($id);
            
            if (!$category) {
                throw new CategoryNotFoundException("Category not found: {$id}");
            }
            
            return $category;
        });
    }

    public function getChildren(string $id): CategoryCollection
    {
        return $this->cache->remember("category:{$id}:children", function() use ($id) {
            return $this->database->getCategoryChildren($id);
        });
    }

    public function moveNode(string $id, string $targetId, User $user): void
    {
        try {
            DB::beginTransaction();

            $category = $this->findCategory($id);
            $target = $this->findCategory($targetId);
            
            $this->validateMove($category, $target);
            $this->security->validateAccess($user, 'category.move');

            $this->moveCategory($category, $target);
            $this->updateTreeStructure();
            
            $this->cache->invalidateCategoryTree();
            $this->audit->logCategoryMove($category, $target, $user);
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, 'move', $id);
            throw $e;
        }
    }

    public function reorder(string $id, int $position, User $user): void
    {
        try {
            DB::beginTransaction();

            $category = $this->findCategory($id);
            $this->validateReorder($category, $position);
            $this->security->validateAccess($user, 'category.reorder');

            $this->reorderCategory($category, $position);
            $this->updateTreeStructure();
            
            $this->cache->invalidateCategoryTree();
            $this->audit->logCategoryReorder($category, $position, $user);
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, 'reorder', $id);
            throw $e;
        }
    }

    private function processCategory(CategoryRequest $request): Category
    {
        $category = new Category();
        $category->fill($request->getCategoryData());
        $category->setCreatedBy($request->getUser());
        
        return $category;
    }

    private function processHierarchy(Category $category, CategoryRequest $request): void
    {
        if ($request->hasParent()) {
            $parent = $this->findCategory($request->getParentId());
            $this->validateHierarchy($parent, $category);
            $category->setParent($parent);
        }
    }

    private function processAttributes(Category $category, CategoryRequest $request): void
    {
        foreach ($request->getAttributes() as $attribute) {
            $this->validateAttribute($attribute);
            $category->addAttribute($attribute);
        }
    }

    private function validateRequest(CategoryRequest $request): void
    {
        if (!$this->validator->validate($request)) {
            throw new InvalidRequestException('Invalid category request');
        }
    }

    private function validateHierarchy(Category $parent, Category $child): void
    {
        if ($parent->isDescendantOf($child)) {
            throw new InvalidHierarchyException('Invalid category hierarchy');
        }
    }

    private function validateDeletion(Category $category): void
    {
        if ($category->hasChildren()) {
            throw new CategoryDeletionException('Cannot delete category with children');
        }

        if ($category->hasContent()) {
            throw new CategoryDeletionException('Cannot delete category with content');
        }
    }

    private function handleOperationFailure(\Exception $e, string $operation, $context): void
    {
        $this->audit->logCategoryOperationFailure($operation, $context, $e);
        $this->metrics->recordCategoryOperationFailure($operation);
    }
}
