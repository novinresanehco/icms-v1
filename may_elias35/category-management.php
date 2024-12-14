<?php

namespace App\Core\Categories;

use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class CategoryManager implements CategoryManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private AuditLogger $auditLogger;
    private array $config;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        AuditLogger $auditLogger,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
        $this->config = $config;
    }

    public function createCategory(array $data): CategoryResponse
    {
        return $this->security->executeSecureOperation(function() use ($data) {
            $this->validateCategory($data);
            
            DB::beginTransaction();
            try {
                // Process category data
                $processed = $this->processCategory($data);
                
                // Create category
                $category = $this->storeCategory($processed);
                
                // Handle hierarchy
                if (isset($data['parent_id'])) {
                    $this->handleHierarchy($category, $data['parent_id']);
                }
                
                // Generate paths
                $this->generatePaths($category);
                
                DB::commit();
                
                // Clear category caches
                $this->invalidateCategoryCaches();
                
                return new CategoryResponse($category);
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw new CategoryException('Failed to create category: ' . $e->getMessage(), 0, $e);
            }
        }, ['operation' => 'category_create']);
    }

    public function updateCategory(int $id, array $data): CategoryResponse
    {
        return $this->security->executeSecureOperation(function() use ($id, $data) {
            $this->validateUpdate($id, $data);
            
            DB::beginTransaction();
            try {
                // Get category
                $category = $this->findCategory($id);
                
                // Create version
                $this->createVersion($category);
                
                // Update category
                $category = $this->performUpdate($category, $data);
                
                // Update hierarchy if needed
                if (isset($data['parent_id']) && $data['parent_id'] !== $category->parent_id) {
                    $this->updateHierarchy($category, $data['parent_id']);
                }
                
                // Regenerate paths
                $this->regeneratePaths($category);
                
                DB::commit();
                
                // Clear caches
                $this->invalidateCategoryCaches();
                
                return new CategoryResponse($category);
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw new CategoryException('Failed to update category: ' . $e->getMessage(), 0, $e);
            }
        }, ['operation' => 'category_update', 'category_id' => $id]);
    }

    private function validateCategory(array $data): void
    {
        $rules = [
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:categories',
            'description' => 'nullable|string',
            'parent_id' => 'nullable|exists:categories,id',
            'status' => 'required|in:active,inactive'
        ];
        
        if (!$this->validator->validate($data, $rules)) {
            throw new ValidationException('Category validation failed');
        }
        
        if (isset($data['parent_id'])) {
            $this->validateHierarchy($data['parent_id']);
        }
    }

    private function processCategory(array $data): array
    {
        return [
            'name' => $this->processName($data['name']),
            'slug' => $this->processSlug($data['slug']),
            'description' => $data['description'] ?? null,
            'status' => $data['status'],
            'metadata' => $this->generateMetadata($data)
        ];
    }

    private function storeCategory(array $data): Category
    {
        $category = new Category($data);
        $category->save();
        
        $this->auditLogger->logCategoryCreation($category);
        
        return $category;
    }

    private function handleHierarchy(Category $category, int $parentId): void
    {
        $parent = $this->findCategory($parentId);
        
        if ($this->wouldCreateCycle($category, $parent)) {
            throw new CategoryException('Cannot create circular reference in category hierarchy');
        }
        
        $category->parent()->associate($parent);
        $category->save();
    }

    private function generatePaths(Category $category): void
    {
        $paths = $this->calculatePaths($category);
        
        foreach ($paths as $type => $path) {
            $category->paths()->create([
                'type' => $type,
                'path' => $path
            ]);
        }
    }

    private function updateHierarchy(Category $category, int $newParentId): void
    {
        $newParent = $this->findCategory($newParentId);
        
        if ($this->wouldCreateCycle($category, $newParent)) {
            throw new CategoryException('Cannot create circular reference in category hierarchy');
        }
        
        $oldParent = $category->parent;
        
        $category->parent()->associate($newParent);
        $category->save();
        
        // Update all affected categories
        $this->updateAffectedCategories($category, $oldParent);
    }

    private function regeneratePaths(Category $category): void
    {
        // Delete old paths
        $category->paths()->delete();
        
        // Generate new paths
        $this->generatePaths($category);
        
        // Update child paths
        