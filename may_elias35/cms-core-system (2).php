<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\CMS\Services\{MediaService, ValidationService, CacheService};
use App\Core\CMS\Models\{Content, Category, Media};
use App\Core\CMS\Events\{ContentCreated, ContentUpdated, ContentDeleted};
use App\Core\CMS\Exceptions\{ContentValidationException, ContentNotFoundException};
use Illuminate\Support\Facades\{DB, Event, Cache};
use Illuminate\Database\Eloquent\Collection;

class ContentManager
{
    private SecurityManager $security;
    private MediaService $mediaService;
    private ValidationService $validator;
    private CacheService $cache;

    public function __construct(
        SecurityManager $security,
        MediaService $mediaService,
        ValidationService $validator,
        CacheService $cache
    ) {
        $this->security = $security;
        $this->mediaService = $mediaService;
        $this->validator = $validator;
        $this->cache = $cache;
    }

    /**
     * Create new content with version control
     */
    public function createContent(array $data, int $userId): Content
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processContentCreation($data, $userId),
            ['action' => 'create_content', 'user_id' => $userId]
        );
    }

    private function processContentCreation(array $data, int $userId): Content
    {
        // Validate content data
        $this->validator->validateContent($data);

        // Process any media attachments
        $media = $this->processMediaAttachments($data['media'] ?? []);

        // Create content with version control
        $content = DB::transaction(function() use ($data, $userId, $media) {
            // Create main content
            $content = Content::create([
                'title' => $data['title'],
                'slug' => $this->generateUniqueSlug($data['title']),
                'content' => $data['content'],
                'status' => $data['status'] ?? 'draft',
                'user_id' => $userId,
                'category_id' => $data['category_id'] ?? null,
                'version' => 1
            ]);

            // Attach media
            if ($media) {
                $content->media()->attach($media);
            }

            // Handle categories
            if (!empty($data['categories'])) {
                $content->categories()->attach($data['categories']);
            }

            // Create initial version record
            $content->versions()->create([
                'content' => $data['content'],
                'version' => 1,
                'user_id' => $userId
            ]);

            return $content;
        });

        // Clear relevant caches
        $this->cache->clearContentCaches($content);

        // Dispatch creation event
        Event::dispatch(new ContentCreated($content));

        return $content;
    }

    /**
     * Update existing content with version control
     */
    public function updateContent(int $id, array $data, int $userId): Content
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processContentUpdate($id, $data, $userId),
            ['action' => 'update_content', 'content_id' => $id, 'user_id' => $userId]
        );
    }

    private function processContentUpdate(int $id, array $data, int $userId): Content
    {
        $content = Content::findOrFail($id);

        // Validate update data
        $this->validator->validateContent($data, $content);

        // Process media changes
        $media = $this->processMediaAttachments($data['media'] ?? []);

        return DB::transaction(function() use ($content, $data, $userId, $media) {
            // Create new version
            $newVersion = $content->version + 1;
            
            // Store previous version
            $content->versions()->create([
                'content' => $content->content,
                'version' => $content->version,
                'user_id' => $userId
            ]);

            // Update content
            $content->update([
                'title' => $data['title'] ?? $content->title,
                'content' => $data['content'] ?? $content->content,
                'status' => $data['status'] ?? $content->status,
                'category_id' => $data['category_id'] ?? $content->category_id,
                'version' => $newVersion
            ]);

            // Update media attachments
            if ($media) {
                $content->media()->sync($media);
            }

            // Update categories
            if (isset($data['categories'])) {
                $content->categories()->sync($data['categories']);
            }

            return $content;
        });

        // Clear relevant caches
        $this->cache->clearContentCaches($content);

        // Dispatch update event
        Event::dispatch(new ContentUpdated($content));

        return $content;
    }

    /**
     * Delete content with safety checks
     */
    public function deleteContent(int $id, int $userId): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processContentDeletion($id, $userId),
            ['action' => 'delete_content', 'content_id' => $id, 'user_id' => $userId]
        );
    }

    private function processContentDeletion(int $id, int $userId): bool
    {
        $content = Content::findOrFail($id);

        return DB::transaction(function() use ($content, $userId) {
            // Archive content instead of hard delete
            $content->update([
                'status' => 'archived',
                'archived_at' => now(),
                'archived_by' => $userId
            ]);

            // Keep relationships for potential restoration
            Event::dispatch(new ContentDeleted($content));

            return true;
        });
    }

    /**
     * Process and validate media attachments
     */
    private function processMediaAttachments(array $mediaIds): array
    {
        if (empty($mediaIds)) {
            return [];
        }

        $media = Media::findMany($mediaIds);
        
        if ($media->count() !== count($mediaIds)) {
            throw new ContentValidationException('Invalid media attachments');
        }

        return $mediaIds;
    }

    /**
     * Generate unique slug for content
     */
    private function generateUniqueSlug(string $title): string
    {
        $slug = str_slug($title);
        $originalSlug = $slug;
        $count = 1;

        while (Content::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $count++;
        }

        return $slug;
    }

    /**
     * Retrieve content with caching
     */
    public function getContent(int $id): Content
    {
        return $this->cache->remember(
            "content:{$id}",
            fn() => Content::with(['categories', 'media'])->findOrFail($id)
        );
    }
}
