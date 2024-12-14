<?php

namespace App\Core\Content;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Core\Security\SecurityManager;
use App\Core\Content\Events\ContentEvent;
use App\Core\Content\DTOs\{ContentData, ContentVersion};
use App\Core\Exceptions\{ContentException, ValidationException};

class ContentManager implements ContentManagementInterface 
{
    private SecurityManager $security;
    private ContentRepository $repository;
    private MediaManager $mediaManager;
    private ValidationService $validator;
    private CacheManager $cache;
    private AuditLogger $auditLogger;

    public function create(array $data, SecurityContext $context): Content 
    {
        return $this->security->executeCriticalOperation(
            new CreateContentOperation($data),
            $context,
            function() use ($data) {
                DB::beginTransaction();
                try {
                    $validated = $this->validator->validate($data);
                    $content = $this->repository->create($validated);
                    
                    if (isset($validated['media'])) {
                        $this->mediaManager->attachMedia($content, $validated['media']);
                    }

                    $this->processCategories($content, $validated['categories'] ?? []);
                    $this->processTags($content, $validated['tags'] ?? []);
                    
                    $this->cache->invalidateContentCache();
                    
                    DB::commit();
                    $this->auditLogger->logContentCreation($content, $context);
                    
                    event(new ContentEvent(ContentEvent::CREATED, $content));
                    
                    return $content;
                    
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw new ContentException('Content creation failed: ' . $e->getMessage(), 0, $e);
                }
            }
        );
    }

    public function update(int $id, array $data, SecurityContext $context): Content 
    {
        $content = $this->repository->findOrFail($id);
        
        return $this->security->executeCriticalOperation(
            new UpdateContentOperation($content, $data),
            $context,
            function() use ($content, $data) {
                DB::beginTransaction();
                try {
                    $validated = $this->validator->validate($data);
                    $this->createVersion($content);
                    
                    $content = $this->repository->update($content, $validated);
                    
                    if (isset($validated['media'])) {
                        $this->mediaManager->syncMedia($content, $validated['media']);
                    }

                    $this->processCategories($content, $validated['categories'] ?? []);
                    $this->processTags($content, $validated['tags'] ?? []);
                    
                    $this->cache->invalidateContentCache($content->id);
                    
                    DB::commit();
                    $this->auditLogger->logContentUpdate($content, $context);
                    
                    event(new ContentEvent(ContentEvent::UPDATED, $content));
                    
                    return $content;
                    
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw new ContentException('Content update failed: ' . $e->getMessage(), 0, $e);
                }
            }
        );
    }

    public function delete(int $id, SecurityContext $context): bool 
    {
        $content = $this->repository->findOrFail($id);
        
        return $this->security->executeCriticalOperation(
            new DeleteContentOperation($content),
            $context,
            function() use ($content) {
                DB::beginTransaction();
                try {
                    $this->mediaManager->detachAllMedia($content);
                    $this->repository->delete($content);
                    
                    $this->cache->invalidateContentCache($content->id);
                    
                    DB::commit();
                    $this->auditLogger->logContentDeletion($content, $context);
                    
                    event(new ContentEvent(ContentEvent::DELETED, $content));
                    
                    return true;
                    
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw new ContentException('Content deletion failed: ' . $e->getMessage(), 0, $e);
                }
            }
        );
    }

    public function publish(int $id, SecurityContext $context): bool 
    {
        $content = $this->repository->findOrFail($id);
        
        return $this->security->executeCriticalOperation(
            new PublishContentOperation($content),
            $context,
            function() use ($content) {
                DB::beginTransaction();
                try {
                    $content->published_at = now();
                    $this->repository->save($content);
                    
                    $this->cache->invalidateContentCache($content->id);
                    
                    DB::commit();
                    $this->auditLogger->logContentPublication($content, $context);
                    
                    event(new ContentEvent(ContentEvent::PUBLISHED, $content));
                    
                    return true;
                    
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw new ContentException('Content publication failed: ' . $e->getMessage(), 0, $e);
                }
            }
        );
    }

    protected function createVersion(Content $content): ContentVersion 
    {
        return $this->repository->createVersion($content);
    }

    protected function processCategories(Content $content, array $categories): void 
    {
        $this->repository->syncCategories($content, $categories);
    }

    protected function processTags(Content $content, array $tags): void 
    {
        $this->repository->syncTags($content, $tags);
    }

    public function getById(int $id, SecurityContext $context): Content 
    {
        return $this->cache->remember(
            "content.{$id}",
            3600,
            fn() => $this->repository->findOrFail($id)
        );
    }

    public function search(array $criteria, SecurityContext $context): Collection 
    {
        $cacheKey = "content.search." . md5(serialize($criteria));
        
        return $this->cache->remember(
            $cacheKey,
            3600,
            fn() => $this->repository->search($criteria)
        );
    }
}
