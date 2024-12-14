<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityCore;
use App\Core\Cache\CacheManager;
use App\Core\Validation\ValidationService;
use App\Exceptions\CMSException;

class CMSCore implements CMSInterface 
{
    private SecurityCore $security;
    private CacheManager $cache;
    private ValidationService $validator;
    private array $config;

    public function __construct(
        SecurityCore $security,
        CacheManager $cache,
        ValidationService $validator,
        array $config
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator; 
        $this->config = $config;
    }

    public function createContent(array $data, SecurityContext $context): ContentResult 
    {
        return $this->security->validateSecureOperation(
            function() use ($data, $context) {
                // Validate content data
                $validatedData = $this->validator->validateContent($data);
                
                // Create with transaction protection
                $content = DB::transaction(function() use ($validatedData, $context) {
                    $content = $this->contentRepository->create($validatedData);
                    $this->setContentPermissions($content, $context);
                    return $content;
                });

                // Clear relevant caches
                $this->invalidateContentCaches($content->id);

                return new ContentResult($content);
            },
            $context
        );
    }

    public function updateContent(int $id, array $data, SecurityContext $context): ContentResult
    {
        // Verify update permission
        if (!$this->security->verifyAccess("content:$id", 'update', $context)) {
            throw new CMSException('Update access denied');
        }

        return $this->security->validateSecureOperation(
            function() use ($id, $data, $context) {
                $validatedData = $this->validator->validateContent($data);
                
                $content = DB::transaction(function() use ($id, $validatedData) {
                    $content = $this->contentRepository->update($id, $validatedData);
                    $this->updateContentMetadata($content);
                    return $content;
                });

                $this->invalidateContentCaches($id);
                $this->notifyContentUpdate($content);

                return new ContentResult($content);
            },
            $context
        );
    }

    public function deleteContent(int $id, SecurityContext $context): bool
    {
        if (!$this->security->verifyAccess("content:$id", 'delete', $context)) {
            throw new CMSException('Delete access denied');
        }

        return $this->security->validateSecureOperation(
            function() use ($id) {
                return DB::transaction(function() use ($id) {
                    $this->contentRepository->delete($id);
                    $this->cleanupContentResources($id);
                    $this->invalidateContentCaches($id);
                    return true;
                });
            },
            $context
        );
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

    private function invalidateContentCaches(int $contentId): void
    {
        $cacheKeys = [
            "content:$contentId",
            "content:$contentId:meta",
            "content:list"
        ];

        foreach ($cacheKeys as $key) {
            $this->cache->delete($key);
        }
    }

    private function updateContentMetadata(Content $content): void
    {
        $metadata = [
            'updated_at' => now(),
            'version' => $content->version + 1,
            'checksum' => $this->generateContentChecksum($content)
        ];

        $this->contentRepository->updateMetadata($content->id, $metadata);
    }

    private function cleanupContentResources(int $contentId): void
    {
        // Remove associated files
        $this->fileRepository->deleteContentFiles($contentId);
        
        // Clear permissions
        $this->permissionRepository->clearPermissions($contentId);
        
        // Remove from search index
        $this->searchIndex->removeContent($contentId);
    }

    private function notifyContentUpdate(Content $content): void
    {
        event(new ContentUpdated($content));
    }

    private function resolveContentRoles(Content $content, SecurityContext $context): array
    {
        return array_intersect(
            $context->roles,
            $this->config['content_roles']
        );
    }
}
