<?php

namespace App\Core\CMS;

use App\Core\Services\CriticalBaseService;
use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Validation\ValidationService;
use App\Core\Models\Content;
use App\Core\Exceptions\CMSException;
use Illuminate\Support\Facades\DB;

class ContentManagementService extends CriticalBaseService
{
    private ContentRepository $repository;
    private MediaService $mediaService;
    private VersionControl $versionControl;

    public function createContent(array $data): Content
    {
        return $this->executeCritical('content:create', function () use ($data) {
            // Validate input
            $validated = $this->validator->validateContent($data);
            
            // Process media if present
            if (isset($validated['media'])) {
                $validated['media'] = $this->mediaService->processMedia($validated['media']);
            }

            // Create version control entry
            $version = $this->versionControl->initVersion();

            // Store content with versioning
            $content = $this->repository->create(array_merge($validated, [
                'version_id' => $version->id,
                'status' => 'draft'
            ]));

            // Clear relevant caches
            $this->cache->invalidateGroup('content');

            return $content;
        });
    }

    public function updateContent(int $id, array $data): Content
    {
        return $this->executeCritical('content:update', function () use ($id, $data) {
            // Fetch existing with locking
            $content = $this->repository->findWithLock($id);
            
            // Create new version
            $version = $this->versionControl->createVersion($content);

            // Validate and update
            $validated = $this->validator->validateContent($data);
            
            // Process media updates
            if (isset($validated['media'])) {
                $validated['media'] = $this->mediaService->updateMedia(
                    $content->media,
                    $validated['media']
                );
            }

            // Update with new version
            $content = $this->repository->update($content, array_merge($validated, [
                'version_id' => $version->id
            ]));

            // Clear caches
            $this->cache->invalidateGroup("content:{$id}");

            return $content;
        });
    }

    public function publishContent(int $id): Content
    {
        return $this->executeCritical('content:publish', function () use ($id) {
            $content = $this->repository->findWithLock($id);
            
            // Validate publish requirements
            if (!$this->validator->canPublish($content)) {
                throw new CMSException('Content cannot be published - validation failed');
            }

            // Create published version
            $publishedVersion = $this->versionControl->createPublishedVersion($content);

            // Update content status
            $content = $this->repository->update($content, [
                'status' => 'published',
                'published_version_id' => $publishedVersion->id,
                'published_at' => now()
            ]);

            // Clear caches
            $this->cache->invalidateGroup("content:{$id}");
            $this->cache->invalidateGroup('published_content');

            return $content;
        });
    }

    public function getContent(int $id): Content
    {
        return $this->retrieveData("content:{$id}", function () use ($id) {
            return $this->repository->find($id);
        });
    }

    public function getPublishedContent(array $criteria = []): array
    {
        return $this->retrieveData('published_content:' . md5(serialize($criteria)), 
            function () use ($criteria) {
                return $this->repository->findPublished($criteria);
            }
        );
    }

    public function deleteContent(int $id): void
    {
        $this->executeCritical('content:delete', function () use ($id) {
            $content = $this->repository->findWithLock($id);
            
            // Archive version history
            $this->versionControl->archiveVersions($content);
            
            // Delete media
            if ($content->media) {
                $this->mediaService->deleteMedia($content->media);
            }

            // Delete content
            $this->repository->delete($content);

            // Clear caches
            $this->cache->invalidateGroup("content:{$id}");
            $this->cache->invalidateGroup('published_content');
        });
    }

    protected function buildCacheKey(string $operation, $params): string
    {
        return match($operation) {
            'content:get' => "content:{$params['id']}",
            'content:list' => 'content:list:' . md5(serialize($params)),
            'content:published' => 'content:published:' . md5(serialize($params)),
            default => parent::buildCacheKey($operation, $params)
        };
    }
}
