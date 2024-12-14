<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityCore;
use App\Core\Validation\ValidationService;
use App\Core\Cache\CacheManager;
use App\Exceptions\CMSException;

class ContentManager implements ContentManagerInterface 
{
    private SecurityCore $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private array $config;

    public function __construct(
        SecurityCore $security,
        ValidationService $validator,
        CacheManager $cache,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function createContent(array $data, SecurityContext $context): ContentResult
    {
        return $this->security->validateSecureOperation(function() use ($data, $context) {
            // Validate content
            $validatedData = $this->validator->validateContentData($data);
            
            // Create content
            return DB::transaction(function() use ($validatedData, $context) {
                // Generate metadata
                $metadata = $this->generateContentMetadata($validatedData, $context);
                
                // Store content
                $content = $this->contentRepository->create($validatedData, $metadata);
                
                // Set permissions
                $this->setContentPermissions($content, $context);
                
                // Clear relevant caches
                $this->clearContentCaches();
                
                return new ContentResult($content);
            });
        }, $context);
    }

    public function updateContent(int $id, array $data, SecurityContext $context): ContentResult 
    {
        if (!$this->security->verifyAccess("content:$id", 'update', $context)) {
            throw new CMSException('Content update access denied');
        }

        return $this->security->validateSecureOperation(function() use ($id, $data, $context) {
            $validatedData = $this->validator->validateContentData($data);
            
            return DB::transaction(function() use ($id, $validatedData, $context) {
                // Verify current version
                $this->verifyContentVersion($id, $data['version'] ?? null);
                
                // Update content
                $content = $this->contentRepository->update($id, $validatedData);
                
                // Update metadata
                $this->updateContentMetadata($content);
                
                // Clear caches
                $this->clearContentCaches($id);
                
                return new ContentResult($content);
            });
        }, $context);
    }

    public function deleteContent(int $id, SecurityContext $context): bool
    {
        if (!$this->security->verifyAccess("content:$id", 'delete', $context)) {
            throw new CMSException('Content deletion access denied');
        }

        return $this->security->validateSecureOperation(function() use ($id) {
            return DB::transaction(function() use ($id) {
                // Create backup
                $this->backupContent($id);
                
                // Delete content
                $this->contentRepository->delete($id);
                
                // Clean up resources
                $this->cleanupContentResources($id);
                
                // Clear caches
                $this->clearContentCaches($id);
                
                return true;
            });
        }, $context);
    }

    private function generateContentMetadata(array $data, SecurityContext $context): array
    {
        return [
            'created_at' => now(),
            'created_by' => $context->userId,
            'version' => 1,
            'checksum' => $this->generateChecksum($data),
            'status' => 'draft',
            'encryption_key_id' => $this->security->generateKeyId()
        ];
    }

    private function setContentPermissions(Content $content, SecurityContext $context): void
    {
        $permissions = [
            'owner' => $context->userId,
            'roles' => $this->resolveContentRoles($content, $context),
            'access_level' => $content->access_level ?? 'private'
        ];

        $this->permissionRepository->setPermissions($content->id, $permissions);
    }

    private function verifyContentVersion(int $id, ?int $version): void
    {
        if ($version === null) {
            throw new CMSException('Content version required for update');
        }

        $currentVersion = $this->contentRepository->getCurrentVersion($id);
        if ($version !== $currentVersion) {
            throw new CMSException('Content version mismatch');
        }
    }

    private function updateContentMetadata(Content $content): void
    {
        $metadata = [
            'updated_at' => now(),
            'version' => $content->version + 1,
            'checksum' => $this->generateChecksum($content->toArray()),
            'update_type' => $this->determineUpdateType($content)
        ];

        $this->contentRepository->updateMetadata($content->id, $metadata);
    }

    private function backupContent(int $id): void
    {
        $content = $this->contentRepository->find($id);
        $this->contentBackupRepository->create([
            'content_id' => $id,
            'data' => $content->toArray(),
            'metadata' => $content->metadata,
            'version' => $content->version,
            'backup_date' => now()
        ]);
    }

    private function cleanupContentResources(int $id): void
    {
        // Clean up media files
        $this->mediaRepository->deleteContentMedia($id);
        
        // Remove from search index
        $this->searchIndexer->removeContent($id);
        
        // Clear permissions
        $this->permissionRepository->clearPermissions($id);
        
        // Remove relationships
        $this->relationshipRepository->removeContentRelationships($id);
    }

    private function clearContentCaches(int $id = null): void
    {
        $keys = ['content:list'];
        
        if ($id !== null) {
            $keys[] = "content:$id";
            $keys[] = "content:$id:meta";
            $keys[] = "content:$id:permissions";
        }

        foreach ($keys as $key) {
            $this->cache->delete($key);
        }
    }

    private function generateChecksum(array $data): string
    {
        return hash('sha256', serialize($data));
    }

    private function resolveContentRoles(Content $content, SecurityContext $context): array
    {
        return array_intersect(
            $context->roles,
            $this->config['content_roles']
        );
    }

    private function determineUpdateType(Content $content): string
    {
        return $content->isDraft() ? 'draft_update' : 'published_update';
    }
}
