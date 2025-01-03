<?php

namespace App\Core\Content;

class ContentManager implements ContentManagerInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private DatabaseManager $database;
    private VersionManager $versions;
    private AuditService $audit;
    private ValidationService $validator;
    private MetricsCollector $metrics;
    private QueueManager $queue;
    private StorageManager $storage;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        DatabaseManager $database,
        VersionManager $versions,
        AuditService $audit,
        ValidationService $validator,
        MetricsCollector $metrics,
        QueueManager $queue,
        StorageManager $storage
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->database = $database;
        $this->versions = $versions;
        $this->audit = $audit;
        $this->validator = $validator;
        $this->metrics = $metrics;
        $this->queue = $queue;
        $this->storage = $storage;
    }

    public function create(ContentRequest $request): Content
    {
        $startTime = microtime(true);
        
        try {
            DB::beginTransaction();

            $this->validateRequest($request);
            $this->security->validateAccess($request->getUser(), 'content.create');

            $content = $this->processContent($request);
            $version = $this->versions->createInitialVersion($content);
            
            $this->processRelations($content, $request);
            $this->processMedia($content, $request);
            
            $this->cache->invalidateContentCache($content);
            $this->audit->logContentCreation($content, $request->getUser());
            $this->metrics->recordContentOperation('create', microtime(true) - $startTime);

            DB::commit();
            
            $this->queue->dispatch(new ContentCreatedJob($content));
            
            return $content;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, 'create', $request);
            throw $e;
        }
    }

    public function update(string $id, ContentRequest $request): Content
    {
        try {
            DB::beginTransaction();

            $content = $this->findContent($id);
            $this->validateUpdateRequest($content, $request);
            $this->security->validateAccess($request->getUser(), 'content.update');

            $version = $this->versions->createVersion($content);
            $content = $this->updateContent($content, $request);
            
            $this->processRelations($content, $request);
            $this->processMedia($content, $request);
            
            $this->cache->invalidateContentCache($content);
            $this->audit->logContentUpdate($content, $request->getUser());

            DB::commit();
            
            $this->queue->dispatch(new ContentUpdatedJob($content));
            
            return $content;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, 'update', $request);
            throw $e;
        }
    }

    public function publish(string $id, User $user): Content
    {
        try {
            DB::beginTransaction();

            $content = $this->findContent($id);
            $this->security->validateAccess($user, 'content.publish');
            
            $content->setStatus(ContentStatus::PUBLISHED);
            $content->setPublishedAt(new \DateTime());
            $content->setPublishedBy($user);
            
            $this->database->save($content);
            $this->cache->invalidateContentCache($content);
            $this->audit->logContentPublication($content, $user);

            DB::commit();
            
            $this->queue->dispatch(new ContentPublishedJob($content));
            
            return $content;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, 'publish', $id);
            throw $e;
        }
    }

    public function delete(string $id, User $user): void
    {
        try {
            DB::beginTransaction();

            $content = $this->findContent($id);
            $this->security->validateAccess($user, 'content.delete');
            
            $this->versions->archiveVersions($content);
            $this->cleanupRelations($content);
            $this->cleanupMedia($content);
            
            $this->database->delete($content);
            $this->cache->invalidateContentCache($content);
            $this->audit->logContentDeletion($content, $user);

            DB::commit();
            
            $this->queue->dispatch(new ContentDeletedJob($content));
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, 'delete', $id);
            throw $e;
        }
    }

    public function find(string $id): Content
    {
        return $this->cache->remember("content:{$id}", function() use ($id) {
            $content = $this->database->findContent($id);
            
            if (!$content) {
                throw new ContentNotFoundException("Content not found: {$id}");
            }
            
            return $content;
        });
    }

    public function search(SearchRequest $request): ContentCollection
    {
        $this->validateSearchRequest($request);
        
        $cacheKey = $this->buildSearchCacheKey($request);
        
        return $this->cache->remember($cacheKey, function() use ($request) {
            return $this->database->searchContent($request);
        });
    }

    private function validateRequest(ContentRequest $request): void
    {
        if (!$this->validator->validate($request)) {
            throw new InvalidRequestException('Invalid content request');
        }
    }

    private function processContent(ContentRequest $request): Content
    {
        $content = new Content();
        $content->fill($request->getContentData());
        $content->setCreatedBy($request->getUser());
        $content->setStatus(ContentStatus::DRAFT);
        
        $this->database->save($content);
        
        return $content;
    }

    private function processRelations(Content $content, ContentRequest $request): void
    {
        foreach ($request->getRelations() as $relation) {
            $this->database->createContentRelation($content, $relation);
        }
    }

    private function processMedia(Content $content, ContentRequest $request): void
    {
        foreach ($request->getMedia() as $media) {
            $this->storage->processContentMedia($content, $media);
        }
    }

    private function handleOperationFailure(\Exception $e, string $operation, $context): void
    {
        $this->audit->logContentOperationFailure($operation, $context, $e);
        $this->metrics->recordContentOperationFailure($operation);
    }
}
