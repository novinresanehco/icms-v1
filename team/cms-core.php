<?php

namespace App\Core\CMS;

use Illuminate\Support\Facades\DB;
use App\Core\Security\SecurityManager;
use App\Core\Auth\AuthenticationSystem;
use App\Core\Storage\MediaManager;
use App\Core\Cache\CacheManager;

class ContentManagementSystem
{
    private SecurityManager $security;
    private AuthenticationSystem $auth;
    private MediaManager $media;
    private CacheManager $cache;

    public function __construct(
        SecurityManager $security,
        AuthenticationSystem $auth,
        MediaManager $media,
        CacheManager $cache
    ) {
        $this->security = $security;
        $this->auth = $auth;
        $this->media = $media;
        $this->cache = $cache;
    }

    public function createContent(ContentRequest $request): ContentResponse
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processContentCreation($request),
            ['action' => 'content_create', 'user' => $request->getUser()]
        );
    }

    public function updateContent(int $id, ContentRequest $request): ContentResponse
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processContentUpdate($id, $request),
            ['action' => 'content_update', 'content_id' => $id]
        );
    }

    private function processContentCreation(ContentRequest $request): ContentResponse
    {
        // Validate user permissions
        $this->auth->validatePermissions($request->getUser(), 'content:create');

        // Begin atomic operation
        DB::beginTransaction();

        try {
            // Create content version
            $content = new Content([
                'title' => $request->getTitle(),
                'body' => $request->getBody(),
                'status' => ContentStatus::DRAFT,
                'user_id' => $request->getUser()->id,
                'version' => 1
            ]);

            // Process and store media
            if ($request->hasMedia()) {
                $mediaIds = $this->processMediaAttachments($request->getMedia());
                $content->media_ids = $mediaIds;
            }

            // Store content with validation
            $content->save();

            // Handle categories and tags
            $this->processMetadata($content, $request);

            // Create initial version
            $this->createContentVersion($content);

            DB::commit();

            // Clear relevant caches
            $this->invalidateContentCaches($content);

            return new ContentResponse($content);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function processContentUpdate(int $id, ContentRequest $request): ContentResponse
    {
        $content = Content::findOrFail($id);

        // Validate user permissions
        $this->auth->validatePermissions($request->getUser(), 'content:update', $content);

        DB::beginTransaction();

        try {
            // Create new version
            $newVersion = $content->version + 1;
            
            // Update content
            $content->update([
                'title' => $request->getTitle(),
                'body' => $request->getBody(),
                'version' => $newVersion,
                'updated_at' => now()
            ]);

            // Process media changes
            if ($request->hasMedia()) {
                $mediaIds = $this->processMediaAttachments($request->getMedia());
                $content->media_ids = $mediaIds;
            }

            // Update metadata
            $this->processMetadata($content, $request);

            // Store version history
            $this->createContentVersion($content);

            DB::commit();

            // Clear relevant caches
            $this->invalidateContentCaches($content);

            return new ContentResponse($content);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function processMediaAttachments(array $media): array
    {
        return array_map(function($mediaItem) {
            return $this->media->processAndStore($mediaItem, [
                'security_scan' => true,
                'optimize' => true
            ]);
        }, $media);
    }

    private function processMetadata(Content $content, ContentRequest $request): void
    {
        // Handle categories
        if ($request->hasCategories()) {
            $content->categories()->sync($request->getCategories());
        }

        // Handle tags
        if ($request->hasTags()) {
            $content->tags()->sync($request->getTags());
        }
    }

    private function createContentVersion(Content $content): void
    {
        ContentVersion::create([
            'content_id' => $content->id,
            'version' => $content->version,
            'data' => $content->toArray(),
            'user_id' => auth()->id(),
            'created_at' => now()
        ]);
    }

    private function invalidateContentCaches(Content $content): void
    {
        $this->cache->tags(['content', "content:{$content->id}"])->flush();
    }
}
