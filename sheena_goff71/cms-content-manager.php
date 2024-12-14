<?php

namespace App\Core\Content;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Cache\CacheManager;
use App\Core\Interfaces\ContentManagerInterface;

class ContentManager implements ContentManagerInterface 
{
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private LogManager $logger;
    
    public function createContent(array $data): Content
    {
        return $this->security->executeSecureOperation(function() use ($data) {
            DB::beginTransaction();
            try {
                $validated = $this->validator->validateContent($data);
                $content = $this->persistContent($validated);
                $this->cache->invalidateContentCache($content->getId());
                $this->logger->logContentCreation($content);
                
                DB::commit();
                return $content;
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        }, ['action' => 'content.create']);
    }

    public function updateContent(int $id, array $data): Content 
    {
        return $this->security->executeSecureOperation(function() use ($id, $data) {
            DB::beginTransaction();
            try {
                $content = $this->findContent($id);
                $validated = $this->validator->validateContent($data);
                $updated = $this->updateContentData($content, $validated);
                $this->cache->invalidateContentCache($id);
                $this->logger->logContentUpdate($updated);
                
                DB::commit();
                return $updated;
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        }, ['action' => 'content.update', 'content_id' => $id]);
    }

    public function deleteContent(int $id): bool
    {
        return $this->security->executeSecureOperation(function() use ($id) {
            DB::beginTransaction();
            try {
                $content = $this->findContent($id);
                $this->deleteContentData($content);
                $this->cache->invalidateContentCache($id);
                $this->logger->logContentDeletion($content);
                
                DB::commit();
                return true;
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        }, ['action' => 'content.delete', 'content_id' => $id]);
    }

    public function getContent(int $id): Content
    {
        return $this->cache->remember("content.$id", function() use ($id) {
            return $this->findContent($id);
        });
    }

    public function listContent(array $criteria): array
    {
        $cacheKey = $this->generateListCacheKey($criteria);
        
        return $this->cache->remember($cacheKey, function() use ($criteria) {
            return $this->findContentByCriteria($criteria);
        });
    }

    public function versionContent(int $id): ContentVersion
    {
        return $this->security->executeSecureOperation(function() use ($id) {
            DB::beginTransaction();
            try {
                $content = $this->findContent($id);
                $version = $this->createContentVersion($content);
                $this->logger->logVersionCreation($version);
                
                DB::commit();
                return $version;
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        }, ['action' => 'content.version', 'content_id' => $id]);
    }

    public function publishContent(int $id): bool
    {
        return $this->security->executeSecureOperation(function() use ($id) {
            DB::beginTransaction();
            try {
                $content = $this->findContent($id);
                $this->validatePublishState($content);
                $this->updatePublishStatus($content, true);
                $this->cache->invalidateContentCache($id);
                $this->logger->logContentPublish($content);
                
                DB::commit();
                return true;
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        }, ['action' => 'content.publish', 'content_id' => $id]);
    }

    protected function findContent(int $id): Content
    {
        $content = Content::find($id);
        if (!$content) {
            throw new ContentNotFoundException("Content not found: $id");
        }
        return $content;
    }

    protected function persistContent(array $data): Content
    {
        return Content::create($data);
    }

    protected function updateContentData(Content $content, array $data): Content
    {
        $content->update($data);
        return $content->fresh();
    }

    protected function deleteContentData(Content $content): void
    {
        $content->delete();
    }

    protected function findContentByCriteria(array $criteria): array
    {
        return Content::where($criteria)->get()->all();
    }

    protected function createContentVersion(Content $content): ContentVersion
    {
        return ContentVersion::create([
            'content_id' => $content->id,
            'data' => $content->toArray(),
            'created_by' => auth()->id()
        ]);
    }

    protected function validatePublishState(Content $content): void
    {
        if (!$this->validator->validatePublishState($content)) {
            throw new InvalidContentStateException('Content not ready for publishing');
        }
    }

    protected function updatePublishStatus(Content $content, bool $status): void
    {
        $content->update(['published' => $status, 'published_at' => now()]);
    }

    protected function generateListCacheKey(array $criteria): string
    {
        return 'content.list.' . md5(serialize($criteria));
    }
}
