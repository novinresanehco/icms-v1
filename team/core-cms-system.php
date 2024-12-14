<?php

namespace App\Core\CMS;

use App\Core\Auth\{AuthenticationManager, SecurityContext};
use App\Core\Security\{ValidationService, EncryptionService};
use Illuminate\Support\Facades\{DB, Cache, Log};

class ContentManager implements ContentManagementInterface
{
    private AuthenticationManager $auth;
    private ValidationService $validator;
    private ContentRepository $repository;
    private AuditLogger $auditLogger;
    private CacheManager $cache;

    public function __construct(
        AuthenticationManager $auth,
        ValidationService $validator,
        ContentRepository $repository,
        AuditLogger $auditLogger,
        CacheManager $cache
    ) {
        $this->auth = $auth;
        $this->validator = $validator;
        $this->repository = $repository;
        $this->auditLogger = $auditLogger;
        $this->cache = $cache;
    }

    public function create(array $data, SecurityContext $context): Content
    {
        DB::beginTransaction();
        
        try {
            // Security verification
            $this->auth->validateAccess($context);
            
            // Validate content data
            $validated = $this->validator->validateContent($data);
            
            // Create content with security metadata
            $content = $this->repository->create(array_merge($validated, [
                'created_by' => $context->getUser()->id,
                'status' => ContentStatus::DRAFT,
                'version' => 1
            ]));
            
            // Handle media attachments if present
            if (!empty($data['media'])) {
                $this->handleMediaAttachments($content, $data['media']);
            }
            
            // Update search index
            $this->updateSearchIndex($content);
            
            // Log content creation
            $this->auditLogger->logContentCreation($content, $context);
            
            DB::commit();
            return $content;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->auditLogger->logFailedOperation('content.create', $e, $context);
            throw $e;
        }
    }

    public function update(int $id, array $data, SecurityContext $context): Content
    {
        DB::beginTransaction();
        
        try {
            $content = $this->repository->find($id);
            if (!$content) {
                throw new ContentNotFoundException("Content {$id} not found");
            }
            
            // Security verification
            $this->auth->validateAccess($context);
            
            // Validate update data
            $validated = $this->validator->validateContent($data, $content);
            
            // Create new version
            $newVersion = $content->version + 1;
            
            // Update content
            $content = $this->repository->update($id, array_merge($validated, [
                'updated_by' => $context->getUser()->id,
                'version' => $newVersion
            ]));
            
            // Handle media updates
            if (isset($data['media'])) {
                $this->handleMediaAttachments($content, $data['media']);
            }
            
            // Clear cache
            $this->clearContentCache($content);
            
            // Update search index
            $this->updateSearchIndex($content);
            
            // Log update
            $this->auditLogger->logContentUpdate($content, $context);
            
            DB::commit();
            return $content;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->auditLogger->logFailedOperation('content.update', $e, $context);
            throw $e;
        }
    }

    public function publish(int $id, SecurityContext $context): Content
    {
        DB::beginTransaction();
        
        try {
            $content = $this->repository->find($id);
            if (!$content) {
                throw new ContentNotFoundException("Content {$id} not found");
            }
            
            // Verify publish permission
            $this->auth->validateAccess($context);
            
            // Validate content is ready for publish
            $this->validatePublishState($content);
            
            // Update status
            $content = $this->repository->update($id, [
                'status' => ContentStatus::PUBLISHED,
                'published_at' => now(),
                'published_by' => $context->getUser()->id
            ]);
            
            // Clear cache
            $this->clearContentCache($content);
            
            // Update search index
            $this->updateSearchIndex($content);
            
            // Log publish
            $this->auditLogger->logContentPublish($content, $context);
            
            DB::commit();
            return $content;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->auditLogger->logFailedOperation('content.publish', $e, $context);
            throw $e;
        }
    }

    public function delete(int $id, SecurityContext $context): bool
    {
        DB::beginTransaction();
        
        try {
            $content = $this->repository->find($id);
            if (!$content) {
                throw new ContentNotFoundException("Content {$id} not found");
            }
            
            // Security verification
            $this->auth->validateAccess($context);
            
            // Soft delete content
            $this->repository->delete($id);
            
            // Clear cache
            $this->clearContentCache($content);
            
            // Remove from search index
            $this->removeFromSearchIndex($content);
            
            // Log deletion
            $this->auditLogger->logContentDeletion($content, $context);
            
            DB::commit();
            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->auditLogger->logFailedOperation('content.delete', $e, $context);
            throw $e;
        }
    }

    private function handleMediaAttachments(Content $content, array $media): void
    {
        foreach ($media as $item) {
            $this->validator->validateMediaItem($item);
            $this->repository->attachMedia($content->id, $item);
        }
    }

    private function validatePublishState(Content $content): void
    {
        if ($content->status === ContentStatus::PUBLISHED) {
            throw new InvalidOperationException('Content is already published');
        }

        if (!$this->validator->validatePublishReadiness($content)) {
            throw new ValidationException('Content is not ready for publish');
        }
    }

    private function clearContentCache(Content $content): void
    {
        $this->cache->forget("content:{$content->id}");
        $this->cache->forget("content:slug:{$content->slug}");
        $this->cache->tags(['content'])->flush();
    }

    private function updateSearchIndex(Content $content): void
    {
        if ($content->status === ContentStatus::PUBLISHED) {
            $this->searchIndex->upsert($content);
        } else {
            $this->searchIndex->remove($content);
        }
    }

    private function removeFromSearchIndex(Content $content): void
    {
        $this->searchIndex->remove($content);
    }
}
