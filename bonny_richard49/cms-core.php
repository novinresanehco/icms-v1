<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Auth\AuthenticationManager;
use App\Core\Exceptions\CMSException;
use Illuminate\Support\Facades\DB;

class ContentManager
{
    private SecurityManager $security;
    private CacheManager $cache;
    private AuthenticationManager $auth;
    private ValidationService $validator;
    private AuditLogger $auditLogger;
    private MediaHandler $mediaHandler;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        AuthenticationManager $auth,
        ValidationService $validator,
        AuditLogger $auditLogger,
        MediaHandler $mediaHandler
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->auth = $auth;
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
        $this->mediaHandler = $mediaHandler;
    }

    public function createContent(array $data, User $user): Content
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processContentCreation($data, $user),
            new SecurityContext('content_creation', ['user' => $user, 'data' => $data])
        );
    }

    private function processContentCreation(array $data, User $user): Content
    {
        // Validate content data
        $this->validateContentData($data);

        // Create content version
        $content = new Content([
            'title' => $data['title'],
            'slug' => $this->generateUniqueSlug($data['title']),
            'type' => $data['type'],
            'status' => ContentStatus::DRAFT,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $content->save();

        // Create initial version
        $version = $this->createContentVersion($content, $data);

        // Handle media attachments
        if (!empty($data['media'])) {
            $this->processMediaAttachments($content, $data['media']);
        }

        // Handle categories
        if (!empty($data['categories'])) {
            $this->processCategories($content, $data['categories']);
        }

        // Log content creation
        $this->auditLogger->logContentCreation($content, $user);

        // Invalidate relevant caches
        $this->invalidateContentCaches($content);

        return $content->fresh(['currentVersion', 'media', 'categories']);
    }

    private function validateContentData(array $data): void
    {
        $rules = [
            'title' => 'required|string|max:255',
            'type' => 'required|string|in:page,post,custom',
            'content' => 'required|string',
            'media.*' => 'nullable|uuid|exists:media,id',
            'categories.*' => 'nullable|uuid|exists:categories,id'
        ];

        if (!$this->validator->validate($data, $rules)) {
            throw new CMSException('Invalid content data');
        }
    }

    private function createContentVersion(Content $content, array $data): ContentVersion
    {
        $version = new ContentVersion([
            'content_id' => $content->id,
            'content_data' => $this->sanitizeContent($data['content']),
            'version_number' => 1,
            'created_at' => now()
        ]);

        $version->save();

        return $version;
    }

    private function sanitizeContent(string $content): string
    {
        // Implement content sanitization logic
        return clean($content);
    }

    private function processMediaAttachments(Content $content, array $mediaIds): void
    {
        foreach ($mediaIds as $mediaId) {
            $media = Media::findOrFail($mediaId);
            
            // Verify media ownership and status
            if (!$this->mediaHandler->verifyMedia($media)) {
                throw new CMSException('Invalid media attachment');
            }

            $content->media()->attach($media->id);
        }
    }

    private function processCategories(Content $content, array $categoryIds): void
    {
        $content->categories()->sync($categoryIds);
    }

    public function updateContent(string $contentId, array $data, User $user): Content
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processContentUpdate($contentId, $data, $user),
            new SecurityContext('content_update', [
                'content_id' => $contentId, 
                'user' => $user, 
                'data' => $data
            ])
        );
    }

    private function processContentUpdate(string $contentId, array $data, User $user): Content
    {
        $content = Content::findOrFail($contentId);

        // Verify update permissions
        if (!$user->can('update', $content)) {
            throw new UnauthorizedException('Cannot update content');
        }

        // Validate update data
        $this->validateContentData($data);

        // Create new version
        $version = $this->createContentVersion($content, $data);
        $content->current_version_id = $version->id;

        // Update content metadata
        $content->fill([
            'title' => $data['title'],
            'slug' => $this->generateUniqueSlug($data['title'], $content->id),
            'updated_at' => now()
        ]);

        $content->save();

        // Update relations if needed
        if (isset($data['media'])) {
            $this->processMediaAttachments($content, $data['media']);
        }

        if (isset($data['categories'])) {
            $this->processCategories($content, $data['categories']);
        }

        // Log update
        $this->auditLogger->logContentUpdate($content, $user);

        // Invalidate caches
        $this->invalidateContentCaches($content);

        return $content->fresh(['currentVersion', 'media', 'categories']);
    }

    public function publishContent(string $contentId, User $user): Content
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processContentPublication($contentId, $user),
            new SecurityContext('content_publication', [
                'content_id' => $contentId,
                'user' => $user
            ])
        );
    }

    private function processContentPublication(string $contentId, User $user): Content
    {
        $content = Content::findOrFail($contentId);

        // Verify publication permissions
        if (!$user->can('publish', $content)) {
            throw new UnauthorizedException('Cannot publish content');
        }

        // Verify content is ready for publication
        if (!$this->validateForPublication($content)) {
            throw new CMSException('Content not ready for publication');
        }

        $content->status = ContentStatus::PUBLISHED;
        $content->published_at = now();
        $content->save();

        // Log publication
        $this->auditLogger->logContentPublication($content, $user);

        // Invalidate caches
        $this->invalidateContentCaches($content);

        return $content->fresh();
    }

    private function validateForPublication(Content $content): bool
    {
        // Implement publication validation logic
        return true;
    }

    private function invalidateContentCaches(Content $content): void
    {
        $this->cache->tags(['content', "content:{$content->id}"])->flush();
    }

    private function generateUniqueSlug(string $title, ?string $excludeId = null): string
    {
        $slug = Str::slug($title);
        $count = 1;

        while (true) {
            $query = Content::where('slug', $slug);
            if ($excludeId) {
                $query->where('id', '!=', $excludeId);
            }

            if (!$query->exists()) {
                break;
            }

            $slug = Str::slug($title) . '-' . $count++;
        }

        return $slug;
    }
}
