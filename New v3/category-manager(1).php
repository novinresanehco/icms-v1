<?php

namespace App\Core\Content;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Monitoring\MonitoringService;
use App\Core\Database\DatabaseManager;
use App\Core\Exceptions\CategoryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

/**
 * Core Category Management System
 * CRITICAL COMPONENT - Handles all category operations with hierarchy management
 */
class CategoryManager
{
    private SecurityManager $security;
    private CacheManager $cache;
    private MonitoringService $monitor;
    private DatabaseManager $database;
    
    // Cache keys
    private const CACHE_KEY_TREE = 'category_tree';
    private const CACHE_KEY_CATEGORY = 'category.';
    private const CACHE_TTL = 3600; // 1 hour

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        MonitoringService $monitor,
        DatabaseManager $database
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->monitor = $monitor;
        $this->database = $database;
    }

    /**
     * Creates a new category with full validation and security
     *
     * @param array $data Category data
     * @param int|null $parentId Parent category ID
     * @return Category
     * @throws CategoryException
     */
    public function create(array $data, ?int $parentId = null): Category
    {
        $operationId = $this->monitor->startOperation('category.create');
        
        try {
            // Validate creation permissions
            $this->security->validateCategoryCreation($data);
            
            // Validate hierarchy if parent specified
            if ($parentId) {
                $this->validateHierarchy($parentId);
            }
            
            DB::beginTransaction();
            
            // Create category
            $categoryData = array_merge($data, [
                'parent_id' => $parentId,
                'created_at' => now(),
                'created_by' => auth()->id()
            ]);
            
            $category = $this->database->store('categories', $categoryData);
            
            // Update hierarchy paths
            $this->updateHierarchyPaths($category);
            
            // Clear relevant caches
            $this->clearCategoryCache();
            
            DB::commit();
            
            Log::info('Category created successfully', [
                'id' => $category->id,
                'parent_id' => $parentId
            ]);
            
            return $category;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            
            Log::error('Category creation failed', [
                'error' => $e->getMessage(),
                'data' => $data,
                'parent_id' => $parentId
            ]);
            
            throw new CategoryException(
                'Failed to create category: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    /**
     * Updates category with validation and cache management
     */
    public function update(int $id, array $data): Category
    {
        $operationId = $this->monitor->startOperation('category.update');
        
        try {
            // Validate update permissions
            $this->security->validateCategoryUpdate($id, $data);
            
            DB::beginTransaction();
            
            // Get current category
            $category = $this->database->find('categories', $id);
            if (!$category) {
                throw new CategoryException('Category not found');
            }
            
            // Check if parent is changing
            if (isset($data['parent_id']) && $data['parent_id'] !== $category->parent_id) {
                $this->validateHierarchy($data['parent_id'], $id);
            }
            
            // Update category
            $updated = $this->database->update('categories', $id, $data);
            
            // Update hierarchy paths if parent changed
            if (isset($data['parent_id'])) {
                $this->updateHierarchyPaths($updated);
            }
            
            // Clear relevant caches
            $this->clearCategoryCache();
            
            DB::commit();
            
            Log::info('Category updated successfully', ['id' => $id]);
            
            return $updated;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            
            Log::error('Category update failed', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            
            throw new CategoryException(
                'Failed to update category: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    /**
     * Deletes category with all necessary validations
     */
    public function delete(int $id): bool
    {
        $operationId = $this->monitor->startOperation('category.delete');
        
        try {
            // Validate deletion permissions
            $this->security->validateCategoryDeletion($id);
            
            DB::beginTransaction();
            
            // Check if category exists
            $category = $this->database->find('categories', $id);
            if (!$category) {
                throw new CategoryException('Category not found');
            }
            
            // Check for subcategories
            if ($this->hasSubcategories($id)) {
                throw new CategoryException('Cannot delete category with subcategories');
            }
            
            // Check for associated content
            if ($this->hasAssociatedContent($id)) {
                throw new CategoryException('Cannot delete category with associated content');
            }
            
            // Delete category
            $this->database->delete('categories', $id);
            
            // Clear relevant caches
            $this->clearCategoryCache();
            
            DB::commit();
            
            Log::info('Category deleted successfully', ['id' => $id]);
            
            return true;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            
            Log::error('Category deletion failed', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            
            throw new CategoryException(
                'Failed to delete category: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    /**
     * Gets category tree with efficient caching
     */
    public function getTree(): Collection
    {
        $operationId = $this->monitor->startOperation('category.getTree');
        
        try {
            // Try cache first
            $cached = $this->cache->get(self::CACHE_KEY_TREE);
            if ($cached) {
                return $cached;
            }
            
            // Build tree from database
            $categories = $this->database->get('categories');
            $tree = $this->buildTree($categories);
            
            // Cache the tree
            $this->cache->set(self::CACHE_KEY_TREE, $tree, self::CACHE_TTL);
            
            return $tree;
            
        } catch (\Throwable $e) {
            Log::error('Failed to get category tree', [
                'error' => $e->getMessage()
            ]);
            
            throw new CategoryException(
                'Failed to get category tree: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    /**
     * Gets category with its ancestors and descendants
     */
    public function getCategoryWithHierarchy(int $id): ?Category
    {
        $operationId = $this->monitor->startOperation('category.getWithHierarchy');
        
        try {
            // Check cache first
            $cacheKey = self::CACHE_KEY_CATEGORY . $id . '_hierarchy';
            $cached = $this->cache->get($cacheKey);
            if ($cached) {
                return $cached;
            }
            
            // Get category with relations
            $category = $this->database->find('categories', $id);
            if (!$category) {
                return null;
            }
            
            // Load ancestors and descendants
            $category->ancestors = $this->getAncestors($id);
            $category->descendants = $this->getDescendants($id);
            
            // Cache result
            $this->cache->set($cacheKey, $category, self::CACHE_TTL);
            
            return $category;
            
        } catch (\Throwable $e) {
            Log::error('Failed to get category hierarchy', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            
            throw new CategoryException(
                'Failed to get category hierarchy: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    /**
     * Validates category hierarchy to prevent cycles
     */
    private function validateHierarchy(int $parentId, ?int $categoryId = null): void
    {
        // Prevent self-reference
        if ($categoryId && $parentId === $categoryId) {
            throw new CategoryException('Category cannot be its own parent');
        }
        
        // Check if parent exists
        $parent = $this->database->find('categories', $parentId);
        if (!$parent) {
            throw new CategoryException('Parent category not found');
        }
        
        // Check for circular reference
        if ($categoryId) {
            $descendants = $this->getDescendants($categoryId);
            if ($descendants->contains('id', $parentId)) {
                throw new CategoryException('Circular reference detected in category hierarchy');
            }
        }
        
        // Check maximum depth
        $depth = $this->calculateDepth($parentId);
        if ($depth >= 5) { // Maximum depth of 5 levels
            throw new CategoryException('Maximum category depth exceeded');
        }
    }

    /**
     * Builds category tree from flat list
     */
    private function buildTree(Collection $categories, ?int $parentId = null): Collection
    {
        return $categories
            ->where('parent_id', $parentId)
            ->map(function ($category) use ($categories) {
                $category->children = $this->buildTree($categories, $category->id);
                return $category;
            });
    }

    /**
     * Gets category ancestors
     */
    private function getAncestors(int $categoryId): Collection
    {
        return collect($this->database->query(
            'WITH RECURSIVE ancestors AS (
                SELECT * FROM categories WHERE id = ?
                UNION ALL
                SELECT c.* FROM categories c
                INNER JOIN ancestors a ON c.id = a.parent_id
            )
            SELECT * FROM ancestors WHERE id != ?
            ORDER BY id',
            [$categoryId, $categoryId]
        ));
    }

    /**
     * Gets category descendants
     */
    private function getDescendants(int $categoryId): Collection
    {
        return collect($this->database->query(
            'WITH RECURSIVE descendants AS (
                SELECT * FROM categories WHERE id = ?
                UNION ALL
                SELECT c.* FROM categories c
                INNER JOIN descendants d ON c.parent_id = d.id
            )
            SELECT * FROM descendants WHERE id != ?
            ORDER BY id',
            [$categoryId, $categoryId]
        ));
    }

    /**
     * Calculates category depth in hierarchy
     */
    private function calculateDepth(int $categoryId): int
    {
        $ancestors = $this->getAncestors($categoryId);
        return $ancestors->count();
    }

    /**
     * Updates hierarchy paths for category and descendants
     */
    private function updateHierarchyPaths(Category $category): void
    {
        // Get ancestors to build path
        $ancestors = $this->getAncestors($category->id);
        $path = $ancestors->pluck('id')->push($category->id)->implode('/');
        
        // Update category path
        $this->database->update('categories', $category->id, ['path' => $path]);
        
        // Update descendants paths
        $descendants = $this->getDescendants($category->id);
        foreach ($descendants as $descendant) {
            $this->updateHierarchyPaths($descendant);
        }
    }

    /**
     * Checks if category has subcategories
     */
    private function hasSubcategories(int $categoryId): bool
    {
        return $this->database->exists('categories', ['parent_id' => $categoryId]);
    }

    /**
     * Checks if category has associated content
     */
    private function hasAssociatedContent(int $categoryId): bool
    {
        return $this->database->exists('content_categories', ['category_id' => $categoryId]);
    }

    /**
     * Clears category-related cache
     */
    private function clearCategoryCache(): void
    {
        $this->cache->delete(self::CACHE_KEY_TREE);
        // Could also clear specific category caches if needed
    }
}
