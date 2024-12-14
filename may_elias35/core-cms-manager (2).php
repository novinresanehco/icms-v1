<?php

namespace App\Core\CMS;

use App\Core\Security\CoreSecurityManager;
use App\Core\Services\ValidationService;
use App\Core\Contracts\{ContentRepositoryInterface, CacheInterface};
use Illuminate\Support\Facades\DB;
use App\Core\Exceptions\{ContentException, ValidationException};

class ContentManager implements ContentManagerInterface
{
    private CoreSecurityManager $security;
    private ContentRepositoryInterface $repository;
    private CacheInterface $cache;
    private ValidationService $validator;

    public function __construct(
        CoreSecurityManager $security,
        ContentRepositoryInterface $repository,
        CacheInterface $cache,
        ValidationService $validator
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->cache = $cache;
        $this->validator = $validator;
    }

    public function createContent(array $data, array $context): Content
    {
        return $this->security->executeSecureOperation(
            function() use ($data, $context) {
                $validatedData = $this->validateContentData($data);
                
                $content = DB::transaction(function() use ($validatedData, $context) {
                    $content = $this->repository->create($validatedData);
                    $this->processContentMetadata($content, $context);
                    $this->handleMediaAttachments($content, $validatedData);
                    return $content;
                });

                $this->cache->invalidateContentCache($content->id);
                return $content;
            },
            $context
        );
    }

    public function updateContent(int $id, array $data, array $context): Content
    {
        return $this->security->executeSecureOperation(
            function() use ($id, $data, $context) {
                $validatedData = $this->validateContentData($data);
                
                $content = $this->cache->remember("content.$id", function() use ($id) {
                    return $this->repository->findOrFail($id);
                });

                DB::transaction(function() use ($content, $validatedData, $context) {
                    $this->repository->update($content->id, $validatedData);
                    $this->processContentMetadata($content, $context);
                    $this->handleMediaAttachments($content, $validatedData);
                    $this->createContentVersion($content);
                });

                $this->cache->invalidateContentCache($id);
                return $this->repository->findOrFail($id);
            },
            $context
        );
    }

    public function deleteContent(int $id, array $context): bool
    {
        return $this->security->executeSecureOperation(
            function() use ($id, $context) {
                DB::transaction(function() use ($id) {
                    $this->repository->softDelete($id);
                    $this->cleanupContentResources($id);
                });

                $this->cache->invalidateContentCache($id);
                return true;
            },
            $context
        );
    }

    public function publishContent(int $id, array $context): bool
    {
        return $this->security->executeSecureOperation(
            function() use ($id, $context) {
                $content = $this->repository->findOrFail($id);
                
                if (!$this->validator->validatePublishState($content)) {
                    throw new ContentException('Content not ready for publishing');
                }

                DB::transaction(function() use ($content) {
                    $this->repository->publish($content->id);
                    $this->generateContentCache($content);
                    $this->notifySubscribers($content);
                });

                return true;
            },
            $context
        );
    }

    private function validateContentData(array $data): array
    {
        $rules = [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published,archived',
            'metadata' => 'array'
        ];

        return $this->validator->validate($data, $rules);
    }

    private function processContentMetadata(Content $content, array $context): void
    {
        $metadata = [
            'created_by' => $context['user_id'],
            'created_at' => now(),
            'ip_address' => $context['ip_address'],
            'user_agent' => $context['user_agent']
        ];

        $this->repository->updateMetadata($content->id, $metadata);
    }

    private function handleMediaAttachments(Content $content, array $data): void
    {
        if (!empty($data['media'])) {
            foreach ($data['media'] as $mediaItem) {
                $this->processMediaItem($content, $mediaItem);
            }
        }
    }

    private function createContentVersion(Content $content): void
    {
        $this->repository->createVersion([
            'content_id' => $content->id,
            'data' => $content->toArray(),
            'created_at' => now()
        ]);
    }

    private function generateContentCache(Content $content): void
    {
        $cacheData = $this->repository->getCacheableData($content);
        $this->cache->putContentCache($content->id, $cacheData);
    }

    private function cleanupContentResources(int $contentId): void
    {
        $this->repository->cleanupMedia($contentId);
        $this->repository->cleanupVersions($contentId);
        $this->cache->invalidateContentCache($contentId);
    }

    private function processMediaItem(Content $content, array $mediaItem): void
    {
        if (!$this->validator->validateMediaItem($mediaItem)) {
            throw new ValidationException('Invalid media item');
        }

        $this->repository->attachMedia($content->id, $mediaItem);
    }

    private function notifySubscribers(Content $content): void
    {
        // Implement subscriber notification logic
    }
}
