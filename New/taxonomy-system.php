<?php
namespace App\Core\Taxonomy;

class CategoryManager implements CategoryManagerInterface
{
    private SecurityManager $security;
    private CategoryRepository $categories;
    private CacheManager $cache;
    private AuditLogger $audit;
    private ValidationService $validator;

    public function __construct(
        SecurityManager $security,
        CategoryRepository $categories,
        CacheManager $cache,
        AuditLogger $audit,
        ValidationService $validator
    ) {
        $this->security = $security;
        $this->categories = $categories;
        $this->cache = $cache;
        $this->audit = $audit;
        $this->validator = $validator;
    }

    public function create(array $data, SecurityContext $context): Category
    {
        return $this->security->executeCriticalOperation(
            new CreateCategoryOperation(
                $data,
                $this->categories,
                $this->cache,
                $this->audit,
                $this->validator
            ),
            $context
        );
    }

    public function update(int $id, array $data, SecurityContext $context): Category
    {
        return $this->security->executeCriticalOperation(
            new UpdateCategoryOperation(
                $id,
                $data,
                $this->categories,
                $this->cache,
                $this->audit,
                $this->validator
            ),
            $context
        );
    }

    public function delete(int $id, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(
            new DeleteCategoryOperation(
                $id,
                $this->categories,
                $this->cache,
                $this->audit
            ),
            $context
        );
    }
}

class CreateCategoryOperation extends CriticalOperation
{
    private array $data;
    private CategoryRepository $categories;
    private CacheManager $cache;
    private AuditLogger $audit;
    private ValidationService $validator;

    public function execute(): Category
    {
        // Validate data
        if (!$this->validator->validate($this->data, $this->getValidationRules())) {
            throw new ValidationException('Invalid category data');
        }

        // Create category
        $category = $this->categories->create([
            'name' => $this->data['name'],
            'slug' => Str::slug($this->data['name']),
            'description' => $this->data['description'] ?? null,
            'parent_id' => $this->data['parent_id'] ?? null
        ]);

        // Clear cache
        $this->cache->invalidatePattern("categories.*");

        // Log operation
        $this->audit->logCategoryCreate($category);

        return $category;
    }

    public function getValidationRules(): array
    {
        return [
            'name' => 'required|string|max:255|unique:categories',
            'description' => 'nullable|string',
            'parent_id' => 'nullable|exists:categories,id'
        ];
    }

    public function getRequiredPermissions(): array
    {
        return ['category.create'];
    }
}

class UpdateCategoryOperation extends CriticalOperation
{
    private int $id;
    private array $data;
    private CategoryRepository $categories;
    private CacheManager $cache;
    private AuditLogger $audit;
    private ValidationService $validator;

    public function execute(): Category
    {
        // Load category
        $category = $this->categories->find($this->id);
        if (!$category) {
            throw new CategoryNotFoundException("Category not found: {$this->id}");
        }

        // Validate data
        $this->validateUpdate($category);

        // Update category
        $updated = $this->categories->update($this->id, [
            'name' => $this->data['name'],
            'slug' => Str::slug($this->data['name']),
            'description' => $this->data['description'] ?? $category->description,
            'parent_id' => $this->data['parent_id'] ?? $category->parent_id
        ]);

        // Clear cache
        $this->cache->invalidatePattern("categories.*");

        // Log operation
        $this->audit->logCategoryUpdate($updated, $category);

        return $updated;
    }

    private function validateUpdate(Category $category): void
    {
        $rules = [
            'name' => 'required|string|max:255|unique:categories,name,' . $category->id,
            'description' => 'nullable|string',
            'parent_id' => 'nullable|exists:categories,id|not_in:' . $category->id
        ];

        if (!$this->validator->validate($this->data, $rules)) {
            throw new ValidationException('Invalid category data');
        }

        // Prevent circular references
        if (isset($this->data['parent_id'])) {
            $this->validateParentRelation($category, $this->data['parent_id']);
        }
    }

    private function validateParentRelation(Category $category, int $parentId): void
    {
        if ($this->categories->isDescendantOf($category->id, $parentId)) {
            throw new ValidationException('Cannot set descendant as parent');
        }
    }

    public function getRequiredPermissions(): array
    {
        return ['category.update'];
    }
}

class DeleteCategoryOperation extends CriticalOperation
{
    private int $id;
    private CategoryRepository $categories;
    private CacheManager $cache;
    private AuditLogger $audit;

    public function execute(): void
    {
        // Load category
        $category = $this->categories->find($this->id);
        if (!$category) {
            throw new CategoryNotFoundException("Category not found: {$this->id}");
        }

        // Check for children
        if ($this->categories->hasChildren($this->id)) {
            throw new ValidationException('Cannot delete category with children');
        }

        // Delete category
        $this->categories->delete($this->id);

        // Clear cache
        $this->cache->invalidatePattern("categories.*");

        // Log operation
        $this->audit->logCategoryDelete($category);
    }

    public function getRequiredPermissions(): array
    {
        return ['category.delete'];
    }
}

class TagManager implements TagManagerInterface
{
    private SecurityManager $security;
    private TagRepository $tags;
    private CacheManager $cache;
    private AuditLogger $audit;
    private ValidationService $validator;

    public function attachTags(int $contentId, array $tagIds, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(
            new AttachTagsOperation(
                $contentId,
                $tagIds,
                $this->tags,
                $this->cache,
                $this->audit
            ),
            $context
        );
    }

    public function detachTags(int $contentId, array $tagIds, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(
            new DetachTagsOperation(
                $contentId,
                $tagIds,
                $this->tags,
                $this->cache,
                $this->audit
            ),
            $context
        );
    }
}

class AttachTagsOperation extends CriticalOperation
{
    private int $contentId;
    private array $tagIds;
    private TagRepository $tags;
    private CacheManager $cache;
    private AuditLogger $audit;

    public function execute(): void
    {
        // Validate content exists
        if (!$this->validateContent($this->contentId)) {
            throw new ContentNotFoundException("Content not found: {$this->contentId}");
        }

        // Validate tags exist
        if (!$this->validateTags($this->tagIds)) {
            throw new ValidationException('Invalid tag IDs');
        }

        // Attach tags
        $this->tags->attach($this->contentId, $this->tagIds);

        // Clear cache
        $this->cache->invalidatePattern("content.{$this->contentId}.tags");
        $this->cache->invalidatePattern("tags.*");

        // Log operation
        $this->audit->logTagsAttached($this->contentId, $this->tagIds);
    }

    public function getRequiredPermissions(): array
    {
        return ['content.tag'];
    }
}

class DetachTagsOperation extends CriticalOperation
{
    private int $contentId;
    private array $tagIds;
    private TagRepository $tags;
    private CacheManager $cache;
    private AuditLogger $audit;

    public function execute(): void
    {
        // Detach tags
        $this->tags->detach($this->contentId, $this->tagIds);

        // Clear cache
        $this->cache->invalidatePattern("content.{$this->contentId}.tags");
        $this->cache->invalidatePattern("tags.*");

        // Log operation
        $this->audit->logTagsDetached($this->contentId, $this->tagIds);
    }

    public function getRequiredPermissions(): array
    {
        return ['content.tag'];
    }
}
