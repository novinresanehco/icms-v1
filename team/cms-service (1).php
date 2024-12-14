<?php

namespace App\Core\Services;

class CMSService implements CMSServiceInterface
{
    private ContentManager $content;
    private MediaManager $media;
    private CategoryManager $category;
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private MetricsCollector $metrics;
    private SearchService $search;
    private EventDispatcher $events;

    public function __construct(
        ContentManager $content,
        MediaManager $media,
        CategoryManager $category,
        SecurityManager $security,
        ValidationService $validator,
        CacheManager $cache,
        MetricsCollector $metrics,
        SearchService $search,
        EventDispatcher $events
    ) {
        $this->content = $content;
        $this->media = $media;
        $this->category = $category;
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->metrics = $metrics;
        $this->search = $search;
        $this->events = $events;
    }

    public function createContent(array $data): CMSResponse
    {
        $startTime = microtime(true);
        $this->security->enforcePermission('content.create');

        DB::beginTransaction();
        try {
            $data = $this->validateContentData($data);
            $media = $this->extractMediaData($data);
            
            $content = $this->content->create($data);
            
            if (!empty($media)) {
                $this->processMediaAttachments($content, $media);
            }
            
            if (isset($data['categories'])) {
                $this->processCategories($content, $data['categories']);
            }
            
            $this->processMetadata($content, $data);
            $this->updateSearchIndex($content);
            
            $this->events->dispatch(new ContentCreated($content));
            DB::commit();
            
            $this->invalidateContentCache();
            $this->recordMetrics('content_create', $startTime);
            
            return new CMSResponse($content, true, 'Content created successfully');
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, 'content_create', $data);
            throw $e;
        }
    }

    public function updateContent(int $id, array $data): CMSResponse
    {
        $startTime = microtime(true);
        
        DB::beginTransaction();
        try {
            $content = $this->content->findOrFail($id);
            $this->security->enforcePermission('content.update', $content);
            
            $data = $this->validateContentData($data);
            $media = $this->extractMediaData($data);
            
            $content = $this->content->update($id, $data);
            
            if (!empty($media)) {
                $this->processMediaAttachments($content, $media, true);
            }
            
            if (isset($data['categories'])) {
                $this->processCategories($content, $data['categories'], true);
            }
            
            $this->processMetadata($content, $data);
            $this->updateSearchIndex($content);
            
            $this->events->dispatch(new ContentUpdated($content));
            DB::commit();
            
            $this->invalidateContentCache($id);
            $this->recordMetrics('content_update', $startTime);
            
            return new CMSResponse($content, true, 'Content updated successfully');
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, 'content_update', ['id' => $id, 'data' => $data]);
            throw $e;
        }
    }

    public function publishContent(int $id): CMSResponse
    {
        $startTime = microtime(true);
        
        DB::beginTransaction();
        try {
            $content = $this->content->findOrFail($id);
            $this->security->enforcePermission('content.publish', $content);
            
            $this->validatePublishState($content);
            $content = $this->content->publish($id);
            
            $this->events->dispatch(new ContentPublished($content));
            DB::commit();
            
            $this->invalidateContentCache($id);
            $this->recordMetrics('content_publish', $startTime);
            
            return new CMSResponse($content, true, 'Content published successfully');
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, 'content_publish', ['id' => $id]);
            throw $e;
        }
    }

    private function validateContentData(array $data): array
    {
        return $this->validator->validate($data, [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published,archived',
            'categories' => 'array',
            'metadata' => 'array'
        ]);
    }

    private function extractMediaData(array &$data): array
    {
        $media = $data['media'] ?? [];
        unset($data['media']);
        return $media;
    }

    private function processMediaAttachments(
        Content $content, 
        array $media, 
        bool $update = false
    ): void {
        if ($update) {
            $this->media->detachFromContent($content);
        }
        
        foreach ($media as $item) {
            $this->media->attachToContent($content, $item);
        }
    }

    private function processCategories(
        Content $content, 
        array $categories, 
        bool $update = false
    ): void {
        if ($update) {
            $this->category->detachFromContent($content);
        }
        
        foreach ($categories as $categoryId) {
            $this->category->attachToContent($content, $categoryId);
        }
    }

    private function processMetadata(Content $content, array $data): void
    {
        if (isset($data['metadata'])) {
            $this->content->updateMetadata($content, $data['metadata']);
        }
    }

    private function updateSearchIndex(Content $content): void
    {
        $this->search->updateDocument($content);
    }

    private function invalidateContentCache(?int $id = null): void
    {
        if ($id) {
            $this->cache->forget("content:{$id}");
        }
        $this->cache->forget('content:list');
    }

    private function recordMetrics(string $operation, float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        
        $this->metrics->record('cms_operation', [
            'operation' => $operation,
            'duration' => $duration,
            'memory' => memory_get_peak_usage(true)
        ]);
    }

    private function handleOperationFailure(
        \Exception $e, 
        string $operation, 
        array $context
    ): void {
        $this->metrics->increment('cms_error', [
            'operation' => $operation,
            'error' => get_class($e),
            'message' => $e->getMessage()
        ]);
        
        $this->events->dispatch(new OperationFailed($operation, $e, $context));
    }

    private function validatePublishState(Content $content): void
    {
        if (!$content->canBePublished()) {
            throw new InvalidStateException('Content cannot be published in its current state');
        }
    }
}
