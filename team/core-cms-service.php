<?php

namespace App\Core\Services;

use App\Core\Repository\{
    ContentRepository,
    MediaRepository,
    CategoryRepository
};
use App\Core\Security\CoreSecurityManager;
use App\Core\Events\ContentCreated;
use App\Core\Logging\AuditLogger;
use App\Exceptions\ServiceException;

class ContentService
{
    private ContentRepository $contentRepo;
    private MediaRepository $mediaRepo;
    private CategoryRepository $categoryRepo;
    private CoreSecurityManager $security;
    private AuditLogger $auditLogger;

    public function __construct(
        ContentRepository $contentRepo,
        MediaRepository $mediaRepo,
        CategoryRepository $categoryRepo,
        CoreSecurityManager $security,
        AuditLogger $auditLogger
    ) {
        $this->contentRepo = $contentRepo;
        $this->mediaRepo = $mediaRepo;
        $this->categoryRepo = $categoryRepo;
        $this->security = $security;
        $this->auditLogger = $auditLogger;
    }

    public function createContent(array $data): Content
    {
        return $this->security->validateSecureOperation(function() use ($data) {
            DB::beginTransaction();
            
            try {
                // Create content
                $content = $this->contentRepo->createContent($data);
                
                // Handle media attachments
                if (!empty($data['media'])) {
                    $this->handleMediaAttachments($content, $data['media']);
                }
                
                // Handle categories
                if (!empty($data['categories'])) {
                    $this->handleCategories($content, $data['categories']);
                }
                
                // Dispatch creation event
                event(new ContentCreated($content));
                
                // Log operation
                $this->auditLogger->logContentCreation($content);
                
                DB::commit();
                return $content;
                
            } catch (\Throwable $e) {
                DB::rollBack();
                throw new ServiceException(
                    'Content creation failed: ' . $e->getMessage(),
                    previous: $e
                );
            }
        });
    }

    public function updateContent(int $id, array $data): Content
    {
        return $this->security->validateSecureOperation(function() use ($id, $data) {
            DB::beginTransaction();
            
            try {
                // Update content
                $content = $this->contentRepo->updateContent($id, $data);
                
                // Handle media changes
                if (isset($data['media'])) {
                    $this->handleMediaAttachments($content, $data['media']);
                }
                
                // Handle category changes
                if (isset($data['categories'])) {
                    $this->handleCategories($content, $data['categories']);
                }
                
                // Log operation
                $this->auditLogger->logContentUpdate($content);
                
                DB::commit();
                return $content;
                
            } catch (\Throwable $e) {
                DB::rollBack();
                throw new ServiceException(
                    'Content update failed: ' . $e->getMessage(),
                    previous: $e
                );
            }
        });
    }

    private function handleMediaAttachments(Content $content, array $mediaIds): void
    {
        // Verify media exists
        foreach ($mediaIds as $mediaId) {
            $this->mediaRepo->findOrFail($mediaId);
        }
        
        $content->media()->sync($mediaIds);
    }

    private function handleCategories(Content $content, array $categoryIds): void
    {
        // Verify categories exist
        foreach ($categoryIds as $categoryId) {
            $this->categoryRepo->findOrFail($categoryId);
        }
        
        $content->categories()->sync($categoryIds);
    }
}
