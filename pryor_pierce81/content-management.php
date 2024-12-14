<?php

namespace App\Core\Content;

use App\Core\Security\SecurityContext;
use App\Core\Cache\CacheManager;
use App\Core\Validation\ContentValidator;
use Illuminate\Support\Facades\DB;

class ContentManager implements ContentManagementInterface 
{
    private SecurityManager $security;
    private CacheManager $cache;
    private ContentValidator $validator;
    private ContentRepository $repository;
    private AuditLogger $audit;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ContentValidator $validator,
        ContentRepository $repository,
        AuditLogger $audit
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->repository = $repository;
        $this->audit = $audit;
    }

    public function create(array $data, SecurityContext $context): Content
    {
        return $this->security->executeCriticalOperation(
            new CreateContentOperation($data, $this->validator),
            $context,
            function() use ($data) {
                $validated = $this->validator->validateCreate($data);
                
                DB::beginTransaction();
                try {
                    $content = $this->repository->create($validated);
                    $this->cache->invalidateContentCache($content);
                    $this->audit->logContentCreation($content);
                    DB::commit();
                    return $content;
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            }
        );
    }

    public function update(int $id, array $data, SecurityContext $context): Content
    {
        $cacheKey = "content.{$id}";
        
        return $this->security->executeCriticalOperation(
            new UpdateContentOperation($id, $data, $this->validator),
            $context,
            function() use ($id, $data, $cacheKey) {
                $validated = $this->validator->validateUpdate($data);
                
                DB::beginTransaction();
                try {
                    $content = $this->repository->update($id, $validated);
                    $this->cache->invalidate($cacheKey);
                    $this->audit->logContentUpdate($content);
                    DB::commit();
                    return $content;
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            }
        );
    }

    public function delete(int $id, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(
            new DeleteContentOperation($id),
            $context,
            function() use ($id) {
                DB::beginTransaction();
                try {
                    $result = $this->repository->delete($id);
                    $this->cache->invalidateContentCache($id);
                    $this->audit->logContentDeletion($id);
                    DB::commit();
                    return $result;
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            }
        );
    }

    public function find(int $id, SecurityContext $context): ?Content
    {
        $cacheKey = "content.{$id}";
        
        return $this->cache->remember($cacheKey, 3600, function() use ($id, $context) {
            return $this->security->executeCriticalOperation(
                new ReadContentOperation($id),
                $context,
                fn() => $this->repository->find($id)
            );
        });
    }

    public function publishContent(int $id, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(
            new PublishContentOperation($id),
            $context,
            function() use ($id) {
                DB::beginTransaction();
                try {
                    $result = $this->repository->publish($id);
                    $this->cache->invalidateContentCache($id);
                    $this->audit->logContentPublication($id);
                    DB::commit();
                    return $result;
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            }
        );
    }

    public function versionContent(int $id, SecurityContext $context): ContentVersion
    {
        return $this->security->executeCriticalOperation(
            new VersionContentOperation($id),
            $context,
            function() use ($id) {
                DB::beginTransaction();
                try {
                    $version = $this->repository->createVersion($id);
                    $this->audit->logVersionCreation($id, $version);
                    DB::commit();
                    return $version;
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            }
        );
    }

    public function list(array $criteria, SecurityContext $context): Collection
    {
        $cacheKey = $this->generateListCacheKey($criteria);
        
        return $this->cache->remember($cacheKey, 3600, function() use ($criteria, $context) {
            return $this->security->executeCriticalOperation(
                new ListContentOperation($criteria),
                $context,
                fn() => $this->repository->list($criteria)
            );
        });
    }

    private function generateListCacheKey(array $criteria): string
    {
        return 'content.list.' . md5(serialize($criteria));
    }
}
