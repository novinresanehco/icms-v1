<?php

namespace App\Core\CMS;

use Illuminate\Support\Facades\DB;
use App\Core\Security\SecurityManager;
use App\Core\Services\{
    ValidationService,
    CacheManager,
    AuditLogger,
    MediaService
};
use App\Core\Exceptions\{
    ContentException,
    ValidationException,
    SecurityException
};

class ContentManager
{
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private AuditLogger $auditLogger;
    private MediaService $mediaService;
    private array $config;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        CacheManager $cache,
        AuditLogger $auditLogger,
        MediaService $mediaService,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->auditLogger = $auditLogger;
        $this->mediaService = $mediaService;
        $this->config = $config;
    }

    public function createContent(array $data): array
    {
        return $this->security->executeCriticalOperation(function() use ($data) {
            $validated = $this->validateContentData($data);
            
            return DB::transaction(function() use ($validated) {
                // Process and store content
                $content = $this->processContent($validated);
                $stored = $this->storeContent($content);
                
                // Handle media attachments
                if (!empty($validated['media'])) {
                    $this->processMediaAttachments($stored['id'], $validated['media']);
                }
                
                // Invalidate relevant caches
                $this->invalidateContentCaches($stored['id']);
                
                // Log content creation
                $this->auditLogger->logContentCreation([
                    'content_id' => $stored['id'],
                    'type' => $content['type'],
                    'user_id' => $validated['user_id']
                ]);
                
                return $stored;
            });
        }, ['operation' => 'content_create']);
    }

    public function updateContent(int $id, array $data): array
    {
        return $this->security->executeCriticalOperation(function() use ($id, $data) {
            $validated = $this->validateContentData($data);
            
            return DB::transaction(function() use ($id, $validated) {
                // Verify content exists and is updatable
                $existing = $this->getContent($id);
                $this->validateContentAccess($existing, 'update');
                
                // Process and update content
                $content = $this->processContent($validated);
                $updated = $this->updateStoredContent($id, $content);
                
                // Handle media updates
                if (isset($validated['media'])) {
                    $this->updateMediaAttachments($id, $validated['media']);
                }
                
                // Invalidate relevant caches
                $this->invalidateContentCaches($id);
                
                // Log content update
                $this->auditLogger->logContentUpdate([
                    'content_id' => $id,
                    'type' => $content['type'],
                    'user_id' => $validated['user_id']
                ]);
                
                return $updated;
            });
        }, ['operation' => 'content_update']);
    }

    public function publishContent(int $id): bool
    {
        return $this->security->executeCriticalOperation(function() use ($id) {
            return DB::transaction(function() use ($id) {
                // Verify content and publishing requirements
                $content = $this->getContent($id);
                $this->validateContentAccess($content, 'publish');
                $this->validatePublishingRequirements($content);
                
                // Update publishing status
                $published = $this->updatePublishingStatus($id, true);
                
                // Invalidate relevant caches
                $this->invalidateContentCaches($id);
                
                // Log publishing action
                $this->auditLogger->logContentPublishing([
                    'content_id' => $id,
                    'type' => $content['type'],
                    'user_id' => auth()->id()
                ]);
                
                return $published;
            });
        }, ['operation' => 'content_publish']);
    }

    protected function validateContentData(array $data): array
    {
        $validated = $this->validator->validate($data, $this->getContentValidationRules());
        
        if (!$validated) {
            throw new ValidationException('Content validation failed');
        }
        
        return $validated;
    }

    protected function processContent(array $data): array
    {
        // Process and sanitize content
        $processed = $this->sanitizeContent($data);
        
        // Validate processed content
        if (!$this->validator->validateProcessedContent($processed)) {
            throw new ContentException('Content processing failed validation');
        }
        
        return $processed;
    }

    protected function validateContentAccess(array $content, string $operation): void
    {
        if (!$this->security->validateAccess($content, $operation)) {
            throw new SecurityException('Content access denied');
        }
    }

    protected function validatePublishingRequirements(array $content): void
    {
        if (!$this->validator->validatePublishingRequirements($content)) {
            throw new ContentException('Publishing requirements not met');
        }
    }

    protected function invalidateContentCaches(int $contentId): void
    {
        $this->cache->invalidatePattern("content.{$contentId}.*");
        $this->cache->invalidate('content.list');
    }

    protected function getContentValidationRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'type' => 'required|string|in:' . implode(',', $this->config['allowed_types']),
            'user_id' => 'required|integer|exists:users,id',
            'media.*' => 'sometimes|integer|exists:media,id'
        ];
    }

    private function sanitizeContent(array $data): array
    {
        // Implement content sanitization logic
        return $data;
    }
}
