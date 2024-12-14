<?php

namespace App\Core\Content;

use Illuminate\Support\Facades\DB;
use App\Core\Security\SecurityContext;
use App\Core\Contracts\ContentManagerInterface;

class ContentManager implements ContentManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private AuditSystem $audit;
    private CacheManager $cache;
    private MediaManager $media;
    private MetricsCollector $metrics;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        AuditSystem $audit,
        CacheManager $cache,
        MediaManager $media,
        MetricsCollector $metrics
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->cache = $cache;
        $this->media = $media;
        $this->metrics = $metrics;
    }

    public function createContent(array $data, SecurityContext $context): ContentResult
    {
        $startTime = microtime(true);
        DB::beginTransaction();

        try {
            $this->security->validateAccess($context, 'content:create');
            $this->validator->validateContentData($data);

            $content = new Content($data);
            $content->setAuthor($context->getUser());
            $content->setStatus('draft');

            if (isset($data['media'])) {
                $content->attachMedia(
                    $this->media->processMediaItems($data['media'])
                );
            }

            $content->save();
            
            $this->audit->logContentCreation($content, $context);
            $this->cache->invalidateContentCache();

            DB::commit();
            
            return new ContentResult($content);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, __FUNCTION__, $data);
            throw $e;
        } finally {
            $this->recordMetrics(__FUNCTION__, microtime(true) - $startTime);
        }
    }

    public function updateContent(int $id, array $data, SecurityContext $context): ContentResult
    {
        $startTime = microtime(true);
        DB::beginTransaction();

        try {
            $content = $this->findOrFail($id);
            
            $this->security->validateAccess($context, 'content:update', $content);
            $this->validator->validateContentData($data, $content);

            $previousVersion = $content->createVersion();
            
            $content->update($data);
            
            if (isset($data['media'])) {
                $content->syncMedia(
                    $this->media->processMediaItems($data['media'])
                );
            }

            $this->audit->logContentUpdate($content, $previousVersion, $context);
            $this->cache->invalidateContentCache($id);

            DB::commit();
            
            return new ContentResult($content);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, __FUNCTION__, ['id' => $id, 'data' => $data]);
            throw $e;
        } finally {
            $this->recordMetrics(__FUNCTION__, microtime(true) - $startTime);
        }
    }

    public function publishContent(int $id, SecurityContext $context): ContentResult
    {
        $startTime = microtime(true);
        DB::beginTransaction();

        try {
            $content = $this->findOrFail($id);
            
            $this->security->validateAccess($context, 'content:publish', $content);
            $this->validator->validateContentForPublishing($content);

            $content->publish();
            $content->setPublishedBy($context->getUser());
            $content->save();

            $this->audit->logContentPublication($content, $context);
            $this->cache->invalidateContentCache($id);

            $this->triggerContentPublishedEvents($content);

            DB::commit();
            
            return new ContentResult($content);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, __FUNCTION__, ['id' => $id]);
            throw $e;
        } finally {
            $this->recordMetrics(__FUNCTION__, microtime(true) - $startTime);
        }
    }

    public function deleteContent(int $id, SecurityContext $context): bool
    {
        $startTime = microtime(true);
        DB::beginTransaction();

        try {
            $content = $this->findOrFail($id);
            
            $this->security->validateAccess($context, 'content:delete', $content);
            
            $content->delete();
            
            $this->audit->logContentDeletion($content, $context);
            $this->cache->invalidateContentCache($id);

            DB::commit();
            
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, __FUNCTION__, ['id' => $id]);
            throw $e;
        } finally {
            $this->recordMetrics(__FUNCTION__, microtime(true) - $startTime);
        }
    }

    public function getContent(int $id, SecurityContext $context): ContentResult
    {
        $startTime = microtime(true);

        try {
            $cacheKey = "content:{$id}";
            
            $content = $this->cache->remember($cacheKey, function() use ($id) {
                return $this->findOrFail($id);
            });

            $this->security->validateAccess($context, 'content:read', $content);
            
            $this->audit->logContentAccess($content, $context);
            
            return new ContentResult($content);

        } catch (\Exception $e) {
            $this->handleOperationFailure($e, __FUNCTION__, ['id' => $id]);
            throw $e;
        } finally {
            $this->recordMetrics(__FUNCTION__, microtime(true) - $startTime);
        }
    }

    public function listContent(array $filters, SecurityContext $context): ContentCollection
    {
        $startTime = microtime(true);

        try {
            $this->security->validateAccess($context, 'content:list');
            $this->validator->validateContentFilters($filters);

            $cacheKey = $this->generateListCacheKey($filters);
            
            $contents = $this->cache->remember($cacheKey, function() use ($filters) {
                return $this->queryContent($filters);
            });

            $this->audit->logContentList($filters, $context);
            
            return new ContentCollection($contents);

        } catch (\Exception $e) {
            $this->handleOperationFailure($e, __FUNCTION__, ['filters' => $filters]);
            throw $e;
        } finally {
            $this->recordMetrics(__FUNCTION__, microtime(true) - $startTime);
        }
    }

    private function findOrFail(int $id): Content
    {
        $content = Content::find($id);
        
        if (!$content) {
            throw new ContentNotFoundException("Content not found: {$id}");
        }
        
        return $content;
    }

    private function handleOperationFailure(\Exception $e, string $operation, array $data): void
    {
        $this->audit->logOperationFailure($operation, $e, $data);
        $this->metrics->incrementFailureCount('content_operation');
    }

    private function recordMetrics(string $operation, float $duration): void
    {
        $this->metrics->record([
            'type' => 'content_operation',
            'operation' => $operation,
            'duration' => $duration,
            'memory' => memory_get_peak_usage(true)
        ]);
    }
}
