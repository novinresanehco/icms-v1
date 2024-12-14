<?php

namespace App\Core\CMS;

class CMSManager implements CMSInterface 
{
    private SecurityManager $security;
    private ValidationService $validator;
    private ContentRepository $content;
    private VersionManager $versions;
    private CacheManager $cache;

    public function createContent(array $data): Result
    {
        return $this->security->executeCriticalOperation('content.create', function() use ($data) {
            // Validate content
            $validatedData = $this->validator->validateContent($data);

            // Create version
            $versionId = $this->versions->createVersion($validatedData);

            // Store content
            $content = $this->content->create(array_merge(
                $validatedData,
                ['version_id' => $versionId]
            ));

            // Cache result
            $this->cache->storeContent($content);

            return new Result($content);
        });
    }

    public function updateContent(int $id, array $data): Result
    {
        return $this->security->executeCriticalOperation('content.update', function() use ($id, $data) {
            // Verify content exists
            $content = $this->content->find($id);
            if (!$content) {
                throw new ContentException('Content not found');
            }

            // Validate update data
            $validatedData = $this->validator->validateUpdate($id, $data);

            // Create new version
            $versionId = $this->versions->createVersion($validatedData);

            // Update content
            $updated = $this->content->update($id, array_merge(
                $validatedData,
                ['version_id' => $versionId]
            ));

            // Update cache
            $this->cache->updateContent($updated);

            return new Result($updated);
        });
    }

    public function deleteContent(int $id): Result
    {
        return $this->security->executeCriticalOperation('content.delete', function() use ($id) {
            // Verify content
            $content = $this->content->find($id);
            if (!$content) {
                throw new ContentException('Content not found');
            }

            // Create deletion version
            $this->versions->createDeletionVersion($id);

            // Soft delete content
            $this->content->softDelete($id);

            // Remove from cache
            $this->cache->removeContent($id);

            return new Result(['id' => $id, 'deleted' => true]);
        });
    }

    public function getContent(int $id): Result
    {
        return $this->security->executeCriticalOperation('content.read', function() use ($id) {
            // Try cache first
            $cached = $this->cache->getContent($id);
            if ($cached) {
                return new Result($cached);
            }

            // Get from repository
            $content = $this->content->find($id);
            if (!$content) {
                throw new ContentException('Content not found');
            }

            // Cache content
            $this->cache->storeContent($content);

            return new Result($content);
        });
    }

    public function listContent(array $filters = [], int $page = 1): Result
    {
        return $this->security->executeCriticalOperation('content.list', function() use ($filters, $page) {
            // Try cache
            $cacheKey = $this->generateCacheKey($filters, $page);
            $cached = $this->cache->get($cacheKey);
            if ($cached) {
                return new Result($cached);
            }

            // Get from repository
            $content = $this->content->paginate($filters, $page);

            // Cache results
            $this->cache->set($cacheKey, $content);

            return new Result($content);
        });
    }

    public function publishContent(int $id): Result 
    {
        return $this->security->executeCriticalOperation('content.publish', function() use ($id) {
            // Verify content
            $content = $this->content->find($id);
            if (!$content) {
                throw new ContentException('Content not found');
            }

            // Validate publishable state
            $this->validator->validatePublishable($content);

            // Create published version
            $versionId = $this->versions->createPublishedVersion($id);

            // Update content status
            $published = $this->content->update($id, [
                'status' => 'published',
                'published_at' => time(),
                'version_id' => $versionId
            ]);

            // Update cache
            $this->cache->updateContent($published);

            return new Result($published);
        });
    }

    private function generateCacheKey(array $filters, int $page): string
    {
        return 'content.list.' . md5(serialize($filters)) . ".$page";
    }
}

interface CMSInterface
{
    public function createContent(array $data): Result;
    public function updateContent(int $id, array $data): Result;
    public function deleteContent(int $id): Result;
    public function getContent(int $id): Result;
    public function listContent(array $filters = [], int $page = 1): Result;
    public function publishContent(int $id): Result;
}

class Result
{
    private array $data;
    private bool $success;

    public function __construct(array $data, bool $success = true)
    {
        $this->data = $data;
        $this->success = $success;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }
}

class ContentException extends \Exception {}
