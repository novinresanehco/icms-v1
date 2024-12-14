<?php

namespace App\Core\Repository;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Core\Security\CoreSecurityManager;
use App\Core\Validation\CoreValidationManager;
use App\Core\Interfaces\CmsRepositoryInterface;
use App\Core\Logging\AuditLogger;
use App\Exceptions\{
    SecurityException,
    ValidationException,
    DataException
};

abstract class CoreCmsRepository implements CmsRepositoryInterface
{
    protected CoreSecurityManager $security;
    protected CoreValidationManager $validator;
    protected AuditLogger $auditLogger;
    protected string $model;
    protected array $config;

    public function __construct(
        CoreSecurityManager $security,
        CoreValidationManager $validator,
        AuditLogger $auditLogger,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
        $this->config = $config;
    }

    public function create(array $data): mixed
    {
        return $this->security->validateSecureOperation(function() use ($data) {
            DB::beginTransaction();
            
            try {
                // Validate input data
                $validated = $this->validator->validateData($data, 'create');
                
                // Create entity with monitoring
                $entity = $this->model::create($validated);
                
                // Cache invalidation
                $this->invalidateCache();
                
                // Log creation
                $this->auditLogger->logCreation($entity);
                
                DB::commit();
                return $entity;
                
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }
        });
    }

    public function update(int $id, array $data): mixed
    {
        return $this->security->validateSecureOperation(function() use ($id, $data) {
            DB::beginTransaction();
            
            try {
                // Find entity
                $entity = $this->findOrFail($id);
                
                // Validate update data
                $validated = $this->validator->validateData($data, 'update');
                
                // Update with monitoring
                $entity->update($validated);
                
                // Cache invalidation
                $this->invalidateCache([$id]);
                
                // Log update
                $this->auditLogger->logUpdate($entity);
                
                DB::commit();
                return $entity;
                
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }
        });
    }

    public function delete(int $id): bool
    {
        return $this->security->validateSecureOperation(function() use ($id) {
            DB::beginTransaction();
            
            try {
                // Find entity
                $entity = $this->findOrFail($id);
                
                // Delete entity
                $entity->delete();
                
                // Cache invalidation
                $this->invalidateCache([$id]);
                
                // Log deletion
                $this->auditLogger->logDeletion($entity);
                
                DB::commit();
                return true;
                
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }
        });
    }

    public function find(int $id): mixed
    {
        return Cache::remember(
            $this->getCacheKey($id),
            $this->config['cache_ttl'],
            fn() => $this->model::find($id)
        );
    }

    public function findOrFail(int $id): mixed
    {
        $entity = $this->find($id);
        
        if (!$entity) {
            throw new DataException("Entity not found: {$id}");
        }
        
        return $entity;
    }

    protected function invalidateCache(array $ids = []): void
    {
        if (empty($ids)) {
            Cache::tags($this->getCacheTags())->flush();
            return;
        }

        foreach ($ids as $id) {
            Cache::forget($this->getCacheKey($id));
        }
    }

    protected function getCacheKey(int $id): string
    {
        return sprintf('%s_%d', $this->model, $id);
    }

    protected function getCacheTags(): array
    {
        return [$this->model];
    }
}

class ContentRepository extends CoreCmsRepository
{
    protected string $model = Content::class;
    
    public function createContent(array $data): Content
    {
        // Additional content-specific validation
        $this->validator->validateContentType($data);
        
        return $this->create($data);
    }
    
    public function updateContent(int $id, array $data): Content
    {
        // Additional content-specific validation
        $this->validator->validateContentType($data);
        
        return $this->update($id, $data);
    }
}

class MediaRepository extends CoreCmsRepository
{
    protected string $model = Media::class;
    
    public function createMedia(array $data): Media
    {
        // Additional media-specific validation
        $this->validator->validateMediaType($data);
        
        return $this->create($data);
    }
}

class CategoryRepository extends CoreCmsRepository
{
    protected string $model = Category::class;
    
    public function createCategory(array $data): Category
    {
        // Ensure unique slug
        $data['slug'] = $this->generateUniqueSlug($data['name']);
        
        return $this->create($data);
    }
    
    private function generateUniqueSlug(string $name): string
    {
        $slug = str_slug($name);
        $count = 2;
        
        while ($this->model::where('slug', $slug)->exists()) {
            $slug = str_slug($name) . '-' . $count++;
        }
        
        return $slug;
    }
}
