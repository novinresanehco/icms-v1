<?php

namespace App\Core\CMS;

class ContentManager implements ContentManagerInterface
{
    private DataManager $dataManager;
    private SecurityManager $security;
    private ValidationService $validator;
    private VersionManager $versions;
    private MediaManager $media;
    private CacheManager $cache;
    private MetricsCollector $metrics;

    public function __construct(
        DataManager $dataManager,
        SecurityManager $security,
        ValidationService $validator,
        VersionManager $versions,
        MediaManager $media,
        CacheManager $cache,
        MetricsCollector $metrics
    ) {
        $this->dataManager = $dataManager;
        $this->security = $security;
        $this->validator = $validator;
        $this->versions = $versions;
        $this->media = $media;
        $this->cache = $cache;
        $this->metrics = $metrics;
    }

    public function createContent(array $data, User $user): ContentResult
    {
        DB::beginTransaction();
        
        try {
            $validated = $this->validator->validate($data, ContentRules::CREATE);
            
            $securityContext = new SecurityContext($user, 'content', 'create');
            $this->security->validateAccess($securityContext);

            $content = new Content($validated);
            $content->setAuthor($user);
            $content->setStatus(ContentStatus::DRAFT);

            if (isset($validated['media'])) {
                $content->attachMedia(
                    $this->media->processMedia($validated['media'])
                );
            }

            $operation = new DataOperation(
                'create', 
                'content',
                $content->toArray()
            );

            $result = $this->dataManager->executeDataOperation($operation);
            $this->versions->createInitialVersion($result->getId(), $content);
            
            DB::commit();
            $this->cache->invalidateContentList();
            
            return new ContentResult($result->getData());

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure('create', $e);
            throw $e;
        }
    }

    public function updateContent(int $id, array $data, User $user): ContentResult
    {
        DB::beginTransaction();

        try {
            $validated = $this->validator->validate($data, ContentRules::UPDATE);
            
            $securityContext = new SecurityContext($user, 'content', 'update', $id);
            $this->security->validateAccess($securityContext);

            $currentContent = $this->getContent($id);
            $updatedContent = $currentContent->merge($validated);
            
            if (isset($validated['media'])) {
                $updatedContent->updateMedia(
                    $this->media->processMedia($validated['media'])
                );
            }

            $operation = new DataOperation(
                'update',
                'content',
                $id,
                $updatedContent->toArray()
            );

            $result = $this->dataManager->executeDataOperation($operation);
            $this->versions->createVersion($id, $updatedContent);
            
            DB::commit();
            $this->invalidateContentCache($id);
            
            return new ContentResult($result->getData());

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure('update', $e);
            throw $e;
        }
    }

    public function publishContent(int $id, User $user): ContentResult
    {
        DB::beginTransaction();

        try {
            $securityContext = new SecurityContext($user, 'content', 'publish', $id);
            $this->security->validateAccess($securityContext);

            $content = $this->getContent($id);
            $content->setStatus(ContentStatus::PUBLISHED);
            $content->setPublishedAt(now());
            
            $operation = new DataOperation(
                'update',
                'content',
                $id,
                $content->toArray()
            );

            $result = $this->dataManager->executeDataOperation($operation);
            
            DB::commit();
            $this->invalidateContentCache($id);
            
            return new ContentResult($result->getData());

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure('publish', $e);
            throw $e;
        }
    }

    public function getContent(int $id): Content
    {
        return $this->cache->remember("content.$id", function() use ($id) {
            $operation = new DataOperation('retrieve', 'content', $id);
            $result = $this->dataManager->executeDataOperation($operation);
            return new Content($result->getData());
        });
    }

    public function listContent(ContentFilter $filter): ContentCollection
    {
        $cacheKey = "content.list." . $filter->getCacheKey();
        
        return $this->cache->remember($cacheKey, function() use ($filter) {
            $operation = new DataOperation(
                'list',
                'content',
                null,
                $filter->toArray()
            );
            
            $result = $this->dataManager->executeDataOperation($operation);
            return new ContentCollection($result->getData());
        });
    }

    private function handleFailure(string $operation, \Exception $e): void
    {
        $this->metrics->incrementFailureCount("content.$operation", $e->getCode());
        
        Log::error('Content operation failed', [
            'operation' => $operation,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function invalidateContentCache(int $id): void
    {
        $this->cache->invalidate("content.$id");
        $this->cache->invalidateContentList();
    }
}
