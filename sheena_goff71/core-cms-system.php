<?php

namespace App\Core\CMS;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\{SecurityManager, ValidationService};
use App\Core\Services\{MediaService, CacheService};
use App\Core\Interfaces\{ContentManagementInterface, MediaManagementInterface};
use App\Core\Exceptions\{ContentException, ValidationException, SecurityException};

class CoreCMSManager implements ContentManagementInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private MediaService $mediaService;
    private CacheService $cache;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        MediaService $mediaService,
        CacheService $cache
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->mediaService = $mediaService;
        $this->cache = $cache;
    }

    public function createContent(array $data): ContentResult
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeContentCreation($data),
            ['action' => 'create_content', 'data' => $data]
        );
    }

    public function updateContent(int $id, array $data): ContentResult
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeContentUpdate($id, $data),
            ['action' => 'update_content', 'id' => $id, 'data' => $data]
        );
    }

    public function publishContent(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeContentPublication($id),
            ['action' => 'publish_content', 'id' => $id]
        );
    }

    private function executeContentCreation(array $data): ContentResult
    {
        // Validate content structure
        $this->validateContentData($data);

        DB::beginTransaction();
        try {
            // Process media attachments
            $mediaIds = $this->processMediaAttachments($data['media'] ?? []);

            // Create content record
            $content = Content::create([
                'title' => $data['title'],
                'slug' => $this->generateUniqueSlug($data['title']),
                'content' => $this->sanitizeContent($data['content']),
                'status' => ContentStatus::DRAFT,
                'metadata' => $this->prepareMetadata($data['metadata'] ?? []),
                'created_by' => auth()->id()
            ]);

            // Attach media
            $content->media()->attach($mediaIds);

            // Create content version
            $this->createContentVersion($content);

            // Cache management
            $this->manageContentCache($content);

            DB::commit();

            return new ContentResult($content, true);
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Content creation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function executeContentUpdate(int $id, array $data): ContentResult
    {
        $content = Content::findOrFail($id);

        // Validate update permissions
        $this->validateUpdatePermissions($content);

        DB::beginTransaction();
        try {
            // Create new version before update
            $this->createContentVersion($content);

            // Update content
            $content->update([
                'title' => $data['title'] ?? $content->title,
                'content' => $this->sanitizeContent($data['content'] ?? $content->content),
                'metadata' => $this->mergeMetadata($content->metadata, $data['metadata'] ?? []),
                'updated_by' => auth()->id()
            ]);

            // Update media attachments if needed
            if (isset($data['media'])) {
                $this->updateMediaAttachments($content, $data['media']);
            }

            // Update cache
            $this->invalidateContentCache($content);

            DB::commit();

            return new ContentResult($content, true);
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Content update failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function executeContentPublication(int $id): bool
    {
        $content = Content::findOrFail($id);

        // Validate publication requirements
        $this->validatePublicationRequirements($content);

        DB::beginTransaction();
        try {
            // Create pre-publication version
            $this->createContentVersion($content);

            // Update status
            $content->update([
                'status' => ContentStatus::PUBLISHED,
                'published_at' => now(),
                'published_by' => auth()->id()
            ]);

            // Generate required caches
            $this->generatePublicationCache($content);

            // Notify subscribers if any
            $this->notifyContentSubscribers($content);

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Content publication failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function validateContentData(array $data): void
    {
        $rules = [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'metadata' => 'array',
            'media' => 'array'
        ];

        if (!$this->validator->validate($data, $rules)) {
            throw new ValidationException('Invalid content data');
        }
    }

    private function sanitizeContent(string $content): string
    {
        return clean($content, [
            'HTML.Allowed' => $this->getAllowedHtmlTags(),
            'CSS.Allowed' => $this->getAllowedCssProperties(),
            'AutoFormat.AutoParagraph' => true,
            'AutoFormat.RemoveEmpty' => true
        ]);
    }

    private function createContentVersion(Content $content): void
    {
        ContentVersion::create([
            'content_id' => $content->id,
            'data' => $content->toArray(),
            'created_by' => auth()->id()
        ]);
    }

    private function manageContentCache(Content $content): void
    {
        $cacheKey = "content:{$content->id}";
        $this->cache->put($cacheKey, $content, now()->addHours(24));
        $this->cache->tags(['content'])->put("content:latest", $content->id);
    }

    private function invalidateContentCache(Content $content): void
    {
        $this->cache->forget("content:{$content->id}");
        $this->cache->tags(['content'])->flush();
    }

    private function generatePublicationCache(Content $content): void
    {
        $cacheKey = "published_content:{$content->id}";
        $this->cache->put($cacheKey, $content, now()->addDays(7));
        $this->cache->tags(['published'])->put("content:latest", $content->id);
    }

    private function validatePublicationRequirements(Content $content): void
    {
        if (!$this->meetsPublicationCriteria($content)) {
            throw new ValidationException('Content does not meet publication requirements');
        }
    }

    private function meetsPublicationCriteria(Content $content): bool
    {
        return (
            $content->status === ContentStatus::DRAFT &&
            !empty($content->title) &&
            !empty($content->content) &&
            $this->validateContentSecurity($content)
        );
    }

    private function validateContentSecurity(Content $content): bool
    {
        return (
            $this->security->validateContent($content->content) &&
            $this->security->validateMetadata($content->metadata) &&
            $this->security->validateMediaSecurity($content->media)
        );
    }
}
