<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManagerInterface;
use App\Core\Service\BaseService;
use App\Core\Repository\ContentRepository;
use App\Core\Validation\ContentValidator;
use App\Core\Security\AuditLogger;
use App\Core\Exceptions\CMSException;

class ContentOperationManager extends BaseService
{
    protected ContentRepository $content;
    protected ContentValidator $validator;
    protected CacheManagerInterface $cache;
    protected AuditLogger $audit;
    protected array $securityContext;

    public function __construct(
        ContentRepository $content,
        SecurityManagerInterface $security,
        ContentValidator $validator,
        CacheManagerInterface $cache,
        AuditLogger $audit
    ) {
        $this->content = $content;
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->audit = $audit;
    }

    public function createContent(array $data): Content
    {
        return $this->executeSecureOperation('content.create', function() use ($data) {
            // Pre-process content
            $processedData = $this->preprocessContent($data);
            
            // Validate content structure
            $this->validator->validateContentStructure($processedData);
            
            // Store with versioning
            $content = $this->content->createWithVersion($processedData);
            
            // Process media attachments
            if (isset($data['media'])) {
                $this->processMediaAttachments($content, $data['media']);
            }
            
            // Update cache
            $this->cache->tags(['content'])->flush();
            
            // Index for search
            $this->indexContent($content);
            
            return $content;
        }, ['data' => $data]);
    }

    public function updateContent(int $id, array $data): Content
    {
        return $this->executeSecureOperation('content.update', function() use ($id, $data) {
            // Validate existence and access
            $content = $this->content->findOrFail($id);
            
            // Create new version
            $version = $this->content->createVersion($content);
            
            // Update content
            $processedData = $this->preprocessContent($data);
            $this->validator->validateContentStructure($processedData);
            $content = $this->content->update($id, $processedData);
            
            // Update media
            if (isset($data['media'])) {
                $this->processMediaAttachments($content, $data['media']);
            }
            
            // Clear cache
            $this->invalidateContentCache($id);
            
            // Update search index
            $this->indexContent($content);
            
            return $content;
        }, ['id' => $id, 'data' => $data]);
    }

    public function publishContent(int $id): bool
    {
        return $this->executeSecureOperation('content.publish', function() use ($id) {
            // Validate content
            $content = $this->content->findOrFail($id);
            $this->validator->validateForPublishing($content);
            
            // Update status
            $result = $this->content->publish($id);
            
            if ($result) {
                // Clear cache
                $this->invalidateContentCache($id);
                
                // Update search index
                $this->indexContent($content);
                
                // Trigger notifications
                $this->notifyContentPublished($content);
            }
            
            return $result;
        }, ['id' => $id]);
    }

    public function deleteContent(int $id): bool
    {
        return $this->executeSecureOperation('content.delete', function() use ($id) {
            // Validate and backup
            $content = $this->content->findOrFail($id);
            $this->backupContent($content);
            
            // Remove content
            $result = $this->content->softDelete($id);
            
            if ($result) {
                // Clear cache
                $this->invalidateContentCache($id);
                
                // Remove from search index
                $this->removeFromIndex($id);
                
                // Clean up media
                $this->cleanupMediaAttachments($content);
            }
            
            return $result;
        }, ['id' => $id]);
    }

    protected function preprocessContent(array $data): array
    {
        // Sanitize input
        $data = $this->validator->sanitizeInput($data);
        
        // Process embedded content
        if (isset($data['content'])) {
            $data['content'] = $this->processEmbeddedContent($data['content']);
        }
        
        // Generate SEO metadata
        if (!isset($data['metadata'])) {
            $data['metadata'] = $this->generateMetadata($data);
        }
        
        return $data;
    }

    protected function invalidateContentCache(int $id): void
    {
        $tags = [
            'content',
            "content.$id",
            'content.list'
        ];
        
        $this->cache->tags($tags)->flush();
    }

    protected function indexContent(Content $content): void
    {
        try {
            $this->search->index([
                'id' => $content->id,
                'title' => $content->title,
                'content' => $content->content,
                'metadata' => $content->metadata,
                'status' => $content->status,
                'updated_at' => $content->updated_at
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to index content', [
                'content_id' => $content->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    protected function processMediaAttachments(Content $content, array $media): void
    {
        foreach ($media as $item) {
            $this->validateMediaItem($item);
            $this->content->attachMedia($content->id, $item['id'], $item['type']);
        }
    }

    protected function validateMediaItem(array $item): void
    {
        if (!isset($item['id']) || !isset($item['type'])) {
            throw new CMSException('Invalid media item structure');
        }
        
        if (!$this->validator->isValidMediaType($item['type'])) {
            throw new CMSException('Invalid media type');
        }
    }

    protected function backupContent(Content $content): void
    {
        try {
            $this->backup->storeContent($content);
        } catch (\Exception $e) {
            Log::error('Content backup failed', [
                'content_id' => $content->id,
                'error' => $e->getMessage()
            ]);
            throw new CMSException('Failed to backup content before deletion');
        }
    }

    protected function cleanupMediaAttachments(Content $content): void
    {
        $media = $this->content->getMediaAttachments($content->id);
        foreach ($media as $item) {
            $this->content->detachMedia($content->id, $item->id);
        }
    }

    protected function notifyContentPublished(Content $content): void
    {
        $this->events->dispatch(new ContentPublished($content));
    }

    protected function getCacheTagsForOperation(string $operation, array $context): array
    {
        return match($operation) {
            'content.create', 'content.update', 'content.publish', 'content.delete' 
                => ['content', 'content.list'],
            default => []
        };
    }
}
