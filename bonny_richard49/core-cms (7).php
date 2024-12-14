<?php

namespace App\Core\CMS;

final class ContentManagementSystem
{
    private SecurityManager $security;
    private ValidationService $validator;
    private ContentRepository $content;
    private AuditService $audit;
    private CacheManager $cache;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        ContentRepository $content,
        AuditService $audit,
        CacheManager $cache
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->content = $content;
        $this->audit = $audit;
        $this->cache = $cache;
    }

    public function createContent(array $data, SecurityContext $context): Content
    {
        return $this->security->executeCriticalOperation(function() use ($data, $context) {
            // Pre-validation
            $this->validator->validateContentCreation($data);
            
            // Execute in transaction
            return DB::transaction(function() use ($data, $context) {
                // Create core content
                $content = $this->content->create($this->prepareContent($data));
                
                // Process relationships
                $this->processRelationships($content, $data);
                
                // Set permissions
                $this->setContentPermissions($content, $data['permissions'] ?? []);
                
                // Index for search
                $this->indexContent($content);
                
                // Clear relevant cache
                $this->cache->invalidateGroup('content');
                
                // Audit trail
                $this->audit->logContentCreation($content, $context);
                
                return $content;
            });
        }, $context);
    }

    public function updateContent(int $id, array $data, SecurityContext $context): Content
    {
        return $this->security->executeCriticalOperation(function() use ($id, $data, $context) {
            // Load content with validation
            $content = $this->findOrFail($id);
            
            // Validate update data
            $this->validator->validateContentUpdate($data);
            
            // Execute in transaction
            return DB::transaction(function() use ($content, $data, $context) {
                // Update core fields
                $content->update($this->prepareContent($data));
                
                // Update relationships if changed
                if (isset($data['relationships'])) {
                    $this->updateRelationships($content, $data['relationships']);
                }
                
                // Update permissions if changed
                if (isset($data['permissions'])) {
                    $this->updatePermissions($content, $data['permissions']);
                }
                
                // Re-index content
                $this->indexContent($content);
                
                // Clear cache
                $this->cache->invalidateGroup("content:{$content->id}");
                $this->cache->invalidateGroup('content');
                
                // Audit trail
                $this->audit->logContentUpdate($content, $context);
                
                return $content->fresh();
            });
        }, $context);
    }

    public function deleteContent(int $id, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(function() use ($id, $context) {
            // Load with validation
            $content = $this->findOrFail($id);
            
            // Execute in transaction
            return DB::transaction(function() use ($content, $context) {
                // Remove relationships
                $this->removeRelationships($content);
                
                // Remove permissions
                $this->removePermissions($content);
                
                // Remove from index
                $this->removeFromIndex($content);
                
                // Physical delete
                $deleted = $content->delete();
                
                // Clear cache
                $this->cache->invalidateGroup("content:{$content->id}");
                $this->cache->invalidateGroup('content');
                
                // Audit trail
                $this->audit->logContentDeletion($content, $context);
                
                return $deleted;
            });
        }, $context);
    }

    private function findOrFail(int $id): Content
    {
        if (!$content = $this->content->find($id)) {
            throw new ContentNotFoundException("Content not found: {$id}");
        }
        return $content;
    }

    private function prepareContent(array $data): array
    {
        return array_merge($data, [
            'metadata' => $this->prepareMetadata($data['metadata'] ?? []),
            'published_at' => $this->resolvePublishDate($data),
            'version' => $this->generateVersion()
        ]);
    }

    private function processRelationships(Content $content, array $data): void
    {
        if (isset($data['relationships'])) {
            foreach ($data['relationships'] as $type => $items) {
                $this->validateRelationships($items, $type);
                $content->$type()->attach($items);
            }
        }
    }

    private function setContentPermissions(Content $content, array $permissions): void
    {
        $this->validator->validatePermissions($permissions);
        $content->permissions()->createMany($permissions);
    }

    private function indexContent(Content $content): void
    {
        // Implement search indexing
    }

    private function validateRelationships(array $items, string $type): void
    {
        if (!$this->validator->validateRelationships($items, $type)) {
            throw new ValidationException("Invalid relationships for type: {$type}");
        }
    }
}
