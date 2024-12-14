<?php

namespace App\Core\Content;

class ContentManager implements ContentManagerInterface 
{
    private ContentRepository $repository;
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private MetricsCollector $metrics;
    private MediaManager $media;
    private array $config;

    public function __construct(
        ContentRepository $repository,
        SecurityManager $security,
        ValidationService $validator,
        CacheManager $cache,
        MetricsCollector $metrics,
        MediaManager $media,
        array $config
    ) {
        $this->repository = $repository;
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->metrics = $metrics;
        $this->media = $media;
        $this->config = $config;
    }

    public function create(array $data, array $media = []): Content
    {
        $startTime = microtime(true);
        DB::beginTransaction();

        try {
            $this->validateContent($data);
            $this->security->validateAccess('content.create');

            $content = $this->repository->create($data);
            
            if (!empty($media)) {
                $this->processMedia($content, $media);
            }

            $this->processVersioning($content);
            $this->updateSearchIndex($content);
            
            DB::commit();
            $this->invalidateCache($content);
            $this->recordMetrics('create', $startTime);

            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError($e, 'create', $data);
            throw $e;
        }
    }

    public function update(int $id, array $data, array $media = []): Content
    {
        $startTime = microtime(true);
        DB::beginTransaction();

        try {
            $content = $this->repository->findOrFail($id);
            $this->validateContent($data);
            $this->security->validateAccess('content.update', $content);

            $content = $this->repository->update($id, $data);
            
            if (!empty($media)) {
                $this->processMedia($content, $media);
            }

            $this->processVersioning($content);
            $this->updateSearchIndex($content);
            
            DB::commit();
            $this->invalidateCache($content);
            $this->recordMetrics('update', $startTime);

            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError($e, 'update', ['id' => $id, 'data' => $data]);
            throw $e;
        }
    }

    public function publish(int $id): Content
    {
        $startTime = microtime(true);
        DB::beginTransaction();

        try {
            $content = $this->repository->findOrFail($id);
            $this->security->validateAccess('content.publish', $content);
            
            $this->validatePublishState($content);
            $content->publish();
            
            $this->processVersioning($content);
            $this->updateSearchIndex($content);
            
            DB::commit();
            $this->invalidateCache($content);
            $this->recordMetrics('publish', $startTime);

            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError($e, 'publish', ['id' => $id]);
            throw $e;
        }
    }

    public function delete(int $id): bool
    {
        $startTime = microtime(true);
        DB::beginTransaction();

        try {
            $content = $this->repository->findOrFail($id);
            $this->security->validateAccess('content.delete', $content);
            
            $this->cleanupMedia($content);
            $this->cleanupVersions($content);
            $this->removeFromSearchIndex($content);
            
            $result = $this->repository->delete($id);
            
            DB::commit();
            $this->invalidateCache($content);
            $this->recordMetrics('delete', $startTime);

            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError($e, 'delete', ['id' => $id]);
            throw $e;
        }
    }

    private function validateContent(array $data): void
    {
        if (!$this->validator->validate($data, $this->config['validation_rules'])) {
            throw new ValidationException('Invalid content data');
        }
    }

    private function validatePublishState(Content $content): void
    {
        if (!$content->isReadyForPublish()) {
            throw new StateException('Content not ready for publishing');
        }
    }

    private function processMedia(Content $content, array $media): void
    {
        foreach ($media as $file) {
            $this->media->process($content, $file);
        }
    }

    private function processVersioning(Content $content): void
    {
        if ($this->config['versioning_enabled']) {
            $this->repository->createVersion($content);
        }
    }

    private function updateSearchIndex(Content $content): void
    {
        if ($this->config['search_enabled']) {
            $this->searchIndex->update($content);
        }
    }

    private function removeFromSearchIndex(Content $content): void
    {
        if ($this->config['search_enabled']) {
            $this->searchIndex->remove($content);
        }
    }

    private function cleanupMedia(Content $content): void
    {
        $this->media->cleanup($content);
    }

    private function cleanupVersions(Content $content): void
    {
        if ($this->config['versioning_enabled']) {
            $this->repository->deleteVersions($content);
        }
    }

    private function invalidateCache(Content $content): void
    {
        $this->cache->forget("content:{$content->id}");
        $this->cache->forget("content:list");
    }

    private function recordMetrics(string $operation, float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        
        $this->metrics->record('content_operation', [
            'operation' => $operation,
            'duration' => $duration,
            'memory' => memory_get_peak_usage(true)
        ]);
    }

    private function handleError(\Exception $e, string $operation, array $context): void
    {
        $this->metrics->increment('content_error', [
            'operation' => $operation,
            'error' => get_class($e),
            'message' => $e->getMessage()
        ]);
    }
}
