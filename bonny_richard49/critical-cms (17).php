<?php

namespace App\Core\CMS;

class ContentManager 
{
    private CriticalOperationManager $operations;
    private VersionManager $versions;
    private MediaManager $media;
    private SecurityManager $security;

    public function __construct(
        CriticalOperationManager $operations,
        VersionManager $versions,
        MediaManager $media,
        SecurityManager $security
    ) {
        $this->operations = $operations;
        $this->versions = $versions;
        $this->media = $media;
        $this->security = $security;
    }

    public function createContent(array $data): Result
    {
        return $this->operations->executeCriticalOperation('content.create', function() use ($data) {
            // Create version
            $versionId = $this->versions->create($data);

            // Process media
            $media = $this->processMedia($data['media'] ?? []);

            // Store content
            $content = array_merge($data, [
                'version_id' => $versionId,
                'media_ids' => $media
            ]);

            return new Result($content);
        });
    }

    public function updateContent(int $id, array $data): Result
    {
        return $this->operations->executeCriticalOperation('content.update', function() use ($id, $data) {
            // Create new version
            $versionId = $this->versions->createFromContent($id);

            // Update media
            $media = $this->processMedia($data['media'] ?? []);

            // Update content
            $content = array_merge($data, [
                'id' => $id,
                'version_id' => $versionId,
                'media_ids' => $media
            ]);

            return new Result($content);
        });
    }

    public function publishContent(int $id): Result
    {
        return $this->operations->executeCriticalOperation('content.publish', function() use ($id) {
            // Verify content
            $content = $this->verifyContent($id);

            // Check publishing requirements
            $this->verifyPublishingRequirements($content);

            // Update status
            $content['status'] = 'published';
            $content['published_at'] = time();

            return new Result($content);
        });
    }

    public function deleteContent(int $id): Result
    {
        return $this->operations->executeCriticalOperation('content.delete', function() use ($id) {
            // Archive content
            $this->versions->archive($id);

            // Remove media
            $this->cleanupMedia($id);

            return new Result(['id' => $id, 'deleted' => true]);
        });
    }

    protected function processMedia(array $media): array
    {
        $processedIds = [];

        foreach ($media as $item) {
            $processedIds[] = $this->media->process($item);
        }

        return $processedIds;
    }

    protected function verifyContent(int $id): array
    {
        $content = $this->getContent($id);

        if (!$content) {
            throw new ContentNotFoundException();
        }

        return $content;
    }

    protected function verifyPublishingRequirements(array $content): void
    {
        // Verify all required fields
        foreach (['title', 'body', 'author'] as $field) {
            if (empty($content[$field])) {
                throw new ValidationException("Missing required field: $field");
            }
        }

        // Verify media integrity
        if (!empty($content['media_ids'])) {
            $this->media->verifyAll($content['media_ids']);
        }

        // Security checks
        $this->security->verifyPublishingPermissions($content);
    }

    protected function cleanupMedia(int $contentId): void
    {
        $content = $this->getContent($contentId);
        
        if (!empty($content['media_ids'])) {
            foreach ($content['media_ids'] as $mediaId) {
                $this->media->delete($mediaId);
            }
        }
    }

    protected function getContent(int $id): ?array
    {
        $result = $this->operations->executeCriticalOperation(
            'content.read',
            ['id' => $id]
        );

        return $result->isValid() ? $result->getData() : null;
    }
}

class VersionManager
{
    private $storage;

    public function create(array $data): string
    {
        return uniqid('v_', true);
    }

    public function createFromContent(int $contentId): string
    {
        return uniqid('v_', true);
    }

    public function archive(int $contentId): void
    {
        // Archive implementation
    }
}

class MediaManager
{
    private $storage;

    public function process(array $media): string
    {
        return uniqid('m_', true);
    }

    public function verifyAll(array $mediaIds): void
    {
        foreach ($mediaIds as $id) {
            if (!$this->verify($id)) {
                throw new MediaException("Invalid media: $id");
            }
        }
    }

    public function delete(string $mediaId): void
    {
        // Delete implementation
    }

    protected function verify(string $mediaId): bool
    {
        return true;
    }
}

class ContentNotFoundException extends \Exception {}
class MediaException extends \Exception {}
