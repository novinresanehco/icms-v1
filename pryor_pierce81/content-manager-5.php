<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Validation\ValidationServiceInterface;
use App\Core\Storage\StorageManagerInterface;
use App\Core\Cache\CacheManagerInterface;
use App\Core\Media\MediaManagerInterface;
use App\Core\Exception\{
    ContentException,
    ValidationException,
    SecurityException
};

class ContentManager implements ContentManagerInterface
{
    private SecurityManagerInterface $security;
    private ValidationServiceInterface $validator;
    private StorageManagerInterface $storage;
    private CacheManagerInterface $cache;
    private MediaManagerInterface $media;
    private MonitoringService $monitor;

    public function __construct(
        SecurityManagerInterface $security,
        ValidationServiceInterface $validator,
        StorageManagerInterface $storage,
        CacheManagerInterface $cache,
        MediaManagerInterface $media,
        MonitoringService $monitor
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->storage = $storage;
        $this->cache = $cache;
        $this->media = $media;
        $this->monitor = $monitor;
    }

    public function createContent(array $data, string $userId): Content
    {
        $operationId = $this->monitor->startOperation('content_create');

        try {
            // Security validation
            $this->security->validateOperation('content:create', $userId);

            // Input validation
            $validated = $this->validator->validateData($data, 'content_create');

            // Begin transaction
            $this->storage->beginTransaction();

            // Create content
            $content = new Content($validated);
            $content->setCreatedBy($userId);
            $content->setStatus(ContentStatus::DRAFT);

            // Process media
            if (isset($validated['media'])) {
                $media = $this->media->processMedia($validated['media'], $userId);
                $content->attachMedia($media);
            }

            // Save content
            $content = $this->storage->save($content);

            // Generate cache
            $this->cache->set(
                $this->getCacheKey($content->getId()), 
                $content,
                ['user_id' => $userId]
            );

            $this->storage->commit();

            $this->monitor->recordSuccess($operationId);

            return $content;

        } catch (\Exception $e) {
            $this->storage->rollback();
            $this->monitor->recordFailure($operationId, $e);
            throw $e;
        }
    }

    public function updateContent(string $contentId, array $data, string $userId): Content
    {
        $operationId = $this->monitor->startOperation('content_update');

        try {
            // Security checks
            $this->security->validateOperation('content:update', $userId, ['content_id' => $contentId]);

            // Validate input
            $validated = $this->validator->validateData($data, 'content_update');

            // Begin transaction
            $this->storage->beginTransaction();

            // Get existing content
            $content = $this->getContent($contentId);
            if (!$content) {
                throw new ContentException("Content not found: $contentId");
            }

            // Create revision
            $this->createRevision($content);

            // Update content
            $content->update($validated);
            $content->setUpdatedBy($userId);

            // Handle media updates
            if (isset($validated['media'])) {
                $this->updateContentMedia($content, $validated['media'], $userId);
            }

            // Save changes
            $content = $this->storage->save($content);

            // Update cache
            $this->cache->delete($this->getCacheKey($contentId));

            $this->storage->commit();

            $this->monitor->recordSuccess($operationId);

            return $content;

        } catch (\Exception $e) {
            $this->storage->rollback();
            $this->monitor->recordFailure($operationId, $e);
            throw $e;
        }
    }

    public function deleteContent(string $contentId, string $userId): void
    {
        $operationId = $this->monitor->startOperation('content_delete');

        try {
            // Security validation
            $this->security->validateOperation('content:delete', $userId, ['content_id' => $contentId]);

            $this->storage->beginTransaction();

            // Get content
            $content = $this->getContent($contentId);
            if (!$content) {
                throw new ContentException("Content not found: $contentId");
            }

            // Archive content
            $content->setStatus(ContentStatus::ARCHIVED);
            $content->setDeletedBy($userId);
            $this->storage->save($content);

            // Remove from cache
            $this->cache->delete($this->getCacheKey($contentId));

            $this->storage->commit();

            $this->monitor->recordSuccess($operationId);

        } catch (\Exception $e) {
            $this->storage->rollback();
            $this->monitor->recordFailure($operationId, $e);
            throw $e;
        }
    }

    private function createRevision(Content $content): void
    {
        $revision = new ContentRevision($content);
        $this->storage->save($revision);
    }

    private function updateContentMedia(Content $content, array $mediaData, string $userId): void
    {
        $content->clearMedia();
        $media = $this->media->processMedia($mediaData, $userId);
        $content->attachMedia($media);
    }

    private function getCacheKey(string $contentId): string
    {
        return "content:$contentId";
    }
}
