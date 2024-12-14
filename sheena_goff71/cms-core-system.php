<?php

namespace App\Core\CMS;

use App\Core\Auth\AuthenticationSystem;
use App\Core\Security\SecurityManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class ContentManagementSystem implements CMSInterface
{
    private SecurityManager $security;
    private AuthenticationSystem $auth;
    private ContentRepository $content;
    private MediaManager $media;
    private CacheManager $cache;
    private AuditLogger $auditLogger;

    public function __construct(
        SecurityManager $security,
        AuthenticationSystem $auth,
        ContentRepository $content,
        MediaManager $media,
        CacheManager $cache,
        AuditLogger $auditLogger
    ) {
        $this->security = $security;
        $this->auth = $auth;
        $this->content = $content;
        $this->media = $media;
        $this->cache = $cache;
        $this->auditLogger = $auditLogger;
    }

    public function createContent(array $data, array $metadata): ContentResult
    {
        return $this->security->executeCriticalOperation(
            new ContentOperation('create', function() use ($data, $metadata) {
                // Validate user permissions
                $this->validatePermissions('content.create');

                // Sanitize and validate content
                $sanitizedData = $this->sanitizeContent($data);
                $validatedData = $this->validateContent($sanitizedData);

                // Create content with versioning
                $content = $this->content->create([
                    'data' => $validatedData,
                    'metadata' => $metadata,
                    'version' => 1,
                    'status' => ContentStatus::DRAFT,
                    'created_by' => auth()->id()
                ]);

                // Process any associated media
                if (!empty($data['media'])) {
                    $this->processContentMedia($content, $data['media']);
                }

                // Clear relevant caches
                $this->cache->invalidateContentCaches($content);

                // Log content creation
                $this->auditLogger->logContentCreation($content);

                return new ContentResult($content);
            })
        );
    }

    public function updateContent(int $id, array $data, array $metadata): ContentResult
    {
        return $this->security->executeCriticalOperation(
            new ContentOperation('update', function() use ($id, $data, $metadata) {
                // Validate permissions and fetch content
                $this->validatePermissions('content.update');
                $content = $this->getContentOrFail($id);

                // Create new version
                $newVersion = $this->createContentVersion($content);
                
                // Update content
                $sanitizedData = $this->sanitizeContent($data);
                $validatedData = $this->validateContent($sanitizedData);
                
                $content->update([
                    'data' => $validatedData,
                    'metadata' => array_merge($content->metadata, $metadata),
                    'version' => $newVersion->version,
                    'updated_by' => auth()->id()
                ]);

                // Update media associations
                if (isset($data['media'])) {
                    $this->updateContentMedia($content, $data['media']);
                }

                // Invalidate caches
                $this->cache->invalidateContentCaches($content);

                // Log update
                $this->auditLogger->logContentUpdate($content);

                return new ContentResult($content);
            })
        );
    }

    public function publishContent(int $id): ContentResult
    {
        return $this->security->executeCriticalOperation(
            new ContentOperation('publish', function() use ($id) {
                // Validate publish permissions
                $this->validatePermissions('content.publish');
                
                $content = $this->getContentOrFail($id);

                // Perform pre-publish validation
                $this->validatePublishRequirements($content);

                // Update status
                $content->update([
                    'status' => ContentStatus::PUBLISHED,
                    'published_at' => now(),
                    'published_by' => auth()->id()
                ]);

                // Generate caches for published content
                $this->cache->warmContentCaches($content);

                // Log publication
                $this->auditLogger->logContentPublication($content);

                return new ContentResult($content);
            })
        );
    }

    public function getContent(int $id): ContentResult
    {
        return $this->security->executeCriticalOperation(
            new ContentOperation('read', function() use ($id) {
                // Validate read permissions
                $this->validatePermissions('content.read');

                // Try to get from cache first
                $content = $this->cache->remember(
                    "content:{$id}",
                    config('cms.cache_ttl'),
                    fn() => $this->content->findWithRelations($id)
                );

                if (!$content) {
                    throw new ContentNotFoundException("Content {$id} not found");
                }

                // Log access
                $this->auditLogger->logContentAccess($content);

                return new ContentResult($content);
            })
        );
    }

    private function validatePermissions(string $permission): void
    {
        if (!$this->auth->validateSession(request()->bearerToken())->hasPermission($permission)) {
            throw new PermissionDeniedException("Missing permission: {$permission}");
        }
    }

    private function getContentOrFail(int $id): Content
    {
        $content = $this->content->find($id);
        if (!$content) {
            throw new ContentNotFoundException("Content {$id} not found");
        }
        return $content;
    }

    private function createContentVersion(Content $content): ContentVersion
    {
        return $this->content->createVersion([
            'content_id' => $content->id,
            'data' => $content->data,
            'metadata' => $content->metadata,
            'version' => $content->version + 1,
            'created_by' => auth()->id()
        ]);
    }

    private function sanitizeContent(array $data): array
    {
        return array_map(function($value) {
            if (is_string($value)) {
                return $this->security->sanitizeHtml($value);
            }
            return $value;
        }, $data);
    }

    private function validateContent(array $data): array
    {
        $validator = validator($data, [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'slug' => 'required|string|unique:contents,slug',
            'status' => 'required|in:draft,published,archived',
            'media.*' => 'nullable|exists:media,id'
        ]);

        if ($validator->fails()) {
            throw new ContentValidationException($validator->errors()->first());
        }

        return $data;
    }

    private function processContentMedia(Content $content, array $mediaIds): void
    {
        foreach ($mediaIds as $mediaId) {
            $this->media->associateWithContent($mediaId, $content->id);
        }
    }

    private function validatePublishRequirements(Content $content): void
    {
        $requirements = [
            'has_required_fields' => !empty($content->title) && !empty($content->content),
            'has_valid_status' => $content->status === ContentStatus::DRAFT,
            'meets_quality_check' => $this->performQualityCheck($content),
            'passes_security_scan' => $this->security->scanContent($content->data)
        ];

        if (in_array(false, $requirements, true)) {
            throw new PublishValidationException('Content does not meet publish requirements');
        }
    }

    private function performQualityCheck(Content $content): bool
    {
        // Implement content quality validation
        return true; // Placeholder
    }
}

class ContentStatus
{
    public const DRAFT = 'draft';
    public const PUBLISHED = 'published';
    public const ARCHIVED = 'archived';
}
