<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManagerInterface;
use App\Core\Audit\AuditLoggerInterface;

class ContentManager implements ContentManagerInterface 
{
    private SecurityManagerInterface $security;
    private CacheManagerInterface $cache;
    private AuditLoggerInterface $audit;
    private ContentRepository $repository;
    private MediaManager $media;
    
    public function __construct(
        SecurityManagerInterface $security,
        CacheManagerInterface $cache,
        AuditLoggerInterface $audit,
        ContentRepository $repository,
        MediaManager $media
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->audit = $audit;
        $this->repository = $repository;
        $this->media = $media;
    }

    public function createContent(array $data, User $author): ContentEntity
    {
        return $this->security->executeCriticalOperation(
            new CreateContentOperation($data, $author),
            function() use ($data, $author) {
                // Validate content data
                $validatedData = $this->validateContentData($data);
                
                // Process and store media files
                $processedData = $this->processMediaContent($validatedData);
                
                // Create content version
                $content = $this->repository->create([
                    'data' => $processedData,
                    'author_id' => $author->id,
                    'version' => 1,
                    'status' => ContentStatus::DRAFT
                ]);
                
                // Clear relevant caches
                $this->invalidateContentCaches($content);
                
                // Log content creation
                $this->audit->logContentOperation('create', $content, $author);
                
                return $content;
            }
        );
    }

    public function updateContent(int $id, array $data, User $editor): ContentEntity
    {
        return $this->security->executeCriticalOperation(
            new UpdateContentOperation($id, $data, $editor),
            function() use ($id, $data, $editor) {
                // Get existing content
                $content = $this->getContent($id);
                
                // Verify edit permissions
                if (!$this->canEdit($content, $editor)) {
                    throw new ContentAccessDeniedException();
                }
                
                // Validate update data
                $validatedData = $this->validateContentData($data);
                
                // Process media updates
                $processedData = $this->processMediaContent($validatedData);
                
                // Create new version
                $newVersion = $this->repository->createVersion($content, [
                    'data' => $processedData,
                    'editor_id' => $editor->id,
                    'version' => $content->version + 1
                ]);
                
                // Update main content
                $updatedContent = $this->repository->update($content->id, [
                    'current_version_id' => $newVersion->id
                ]);
                
                // Clear caches
                $this->invalidateContentCaches($updatedContent);
                
                // Log update
                $this->audit->logContentOperation('update', $updatedContent, $editor);
                
                return $updatedContent;
            }
        );
    }

    public function publishContent(int $id, User $publisher): ContentEntity
    {
        return $this->security->executeCriticalOperation(
            new PublishContentOperation($id, $publisher),
            function() use ($id, $publisher) {
                // Get content
                $content = $this->getContent($id);
                
                // Verify publish permissions
                if (!$this->canPublish($content, $publisher)) {
                    throw new ContentAccessDeniedException();
                }
                
                // Validate content is publishable
                $this->validatePublishable($content);
                
                // Update status
                $publishedContent = $this->repository->update($content->id, [
                    'status' => ContentStatus::PUBLISHED,
                    'published_at' => now(),
                    'publisher_id' => $publisher->id
                ]);
                
                // Clear caches
                $this->invalidateContentCaches($publishedContent);
                
                // Log publish
                $this->audit->logContentOperation('publish', $publishedContent, $publisher);
                
                return $publishedContent;
            }
        );
    }

    protected function validateContentData(array $data): array
    {
        $validator = new ContentValidator();
        
        if (!$validator->validate($data)) {
            throw new ContentValidationException($validator->getErrors());
        }
        
        return $validator->getValidated();
    }

    protected function processMediaContent(array $data): array
    {
        if (isset($data['media'])) {
            foreach ($data['media'] as $key => $mediaItem) {
                $data['media'][$key] = $this->media->processMedia($mediaItem);
            }
        }
        
        return $data;
    }

    protected function invalidateContentCaches(ContentEntity $content): void
    {
        // Clear content cache
        $this->cache->forget("content:{$content->id}");
        
        // Clear listing caches
        $this->cache->tags(['content_list'])->flush();
        
        // Clear related caches
        if ($content->categories) {
            foreach ($content->categories as $category) {
                $this->cache->forget("category:{$category->id}:content");
            }
        }
    }

    protected function validatePublishable(ContentEntity $content): void
    {
        if (!$content->isComplete()) {
            throw new ContentIncompleteException('Required fields missing');
        }

        if (!$this->validateContentSecurity($content)) {
            throw new ContentSecurityException('Content failed security validation');
        }
    }

    protected function validateContentSecurity(ContentEntity $content): bool
    {
        // Scan for malicious content
        if (!$this->security->scanContent($content->data)) {
            return false;
        }

        // Validate media security
        if ($content->hasMedia() && !$this->media->validateMediaSecurity($content->media)) {
            return false;
        }

        return true;
    }
}
